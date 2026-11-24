#!/usr/bin/env bash
#
# The Windows test VMs WITHOUT WMIC, for PHPFinder's Windows suite: Windows Server 2025 and Windows 11, each with
# Microsoft's WMIC Feature on Demand removed, OpenSSH, the Visual C++ runtime and PHP 8.4 in C:\php (what the suite's
# guest/provision.ps1 expects of a VM). No IIS: the IIS runners use the shared IIS VM of ../buildfiles/vm/iis.
# Modelled on that VM's iisvm.sh. It runs under QEMU/KVM on a Linux "KVM host": this machine, or another one reached
# over SSH. The VM has no network presence: its SSH port is forwarded to 127.0.0.1 on the KVM host only.
#
# Usage: pfvm.sh --os=OS [--host=HOST] COMMAND [ARGS…]
#
#   --os=OS               server2025 (Windows Server 2025 Standard Evaluation, Server Core) or win11 (Windows 11
#                         Enterprise Evaluation, UEFI with Secure Boot and a TPM 2.0). Default: PFVM_OS. Required.
#   --host=HOST           The KVM host: `local` (default) or an SSH destination. For a remote host, this script copies
#                         itself there and runs the command there; the commands below behave the same either way.
#
#   build [--force]       Install Windows unattended, then PHP and the VC++ runtime, turn Windows Update off, remove
#                         the WMIC capability (recording what it leaves, in wmic.log in the VM directory), and save
#                         the disk as the snapshot `ready`. Skipped when the snapshot exists, unless --force.
#   start                 Restore `ready`, boot, and wait until the guest answers SSH. Builds first when there is no
#                         image, and rebuilds when the evaluation licence is about to expire.
#   stop                  Shut the guest down (ACPI, then kill after PFVM_STOP_TIMEOUT seconds). Never fails.
#   status                Whether the image exists and the VM runs, as key=value lines.
#   ssh [CMD…]            Run a command in the guest (Windows PowerShell), or open a session.
#   ps                    Run the PowerShell script read from stdin in the guest (as -EncodedCommand).
#   put FILE… GUESTDIR    Copy files into a guest directory (created when missing), e.g. C:/pf/.
#   destroy               Stop the VM and delete it (not the downloads cache).
#
# Environment (on the KVM host; forwarded there when set here):
#   PFVM_OS               The OS, when --os is not given
#   PFVM_DIR              Where the VM lives (default ~/VMs/akeeba-pf-<os>)
#   PFVM_CACHE            The downloads cache (default ~/.cache/akeeba-phpfinder-vm/downloads)
#   PFVM_SSH_PORT         Guest SSH, forwarded to 127.0.0.1 on the KVM host (default 22231 for server2025, 22232 for
#                         win11; the IIS VM uses 22225)
#   PFVM_MIN_GRACE_DAYS   Rebuild when the evaluation licence has fewer days left (default 7)
#   PFVM_STOP_TIMEOUT     Seconds to wait for an ACPI shutdown before killing QEMU (default 120)
#
# KVM host requirements: Linux with a usable /dev/kvm, qemu-system-x86_64, qemu-img, python3, ssh, scp, ssh-keygen,
# curl, iconv, tar, socat, and one of mkisofs, genisoimage or xorriso. win11 also needs swtpm and the OVMF firmware
# with Secure Boot and Microsoft's keys enrolled, as qcow2 (edk2-ovmf: OVMF_CODE_4M.secboot.qcow2 and
# OVMF_VARS_4M.secboot.qcow2; PFVM_OVMF_CODE and PFVM_OVMF_VARS name others).
#
# See ../README.md, "The VMs without WMIC".

set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)

die() { echo "pfvm: $*" >&2; exit 1; }
note() { echo "pfvm: $*" >&2; }

usage() { sed -n '/^# Usage:/,/^# See \.\.\/README/p' "$HERE/pfvm.sh" | sed 's/^# \{0,1\}//'; }

HOST=local
OS=${PFVM_OS:-}

while [ $# -gt 0 ]; do
	case $1 in
		--host=*) HOST=${1#--host=}; shift ;;
		--host) [ $# -ge 2 ] || die "--host needs a value"; HOST=$2; shift 2 ;;
		--os=*) OS=${1#--os=}; shift ;;
		--os) [ $# -ge 2 ] || die "--os needs a value"; OS=$2; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		--) shift; break ;;
		-*) die "unknown option $1" ;;
		*) break ;;
	esac
done

[ -n "$HOST" ] || HOST=local
[ $# -ge 1 ] || { usage >&2; exit 64; }

# Everything which differs between the two VMs.
case $OS in
	server2025)
		OS_TITLE='Windows Server 2025 Standard Evaluation (Server Core)'
		ISO_NAME=26100.1742.240906-0331.ge_release_svc_refresh_SERVER_EVAL_x64FRE_en-us.iso
		FIRMWARE=bios
		DEFAULT_SSH_PORT=22231
		;;
	win11)
		OS_TITLE='Windows 11 Enterprise Evaluation'
		ISO_NAME=26100.1742.240906-0331.ge_release_svc_refresh_CLIENTENTERPRISEEVAL_OEMRET_x64FRE_en-us.iso
		FIRMWARE=uefi
		DEFAULT_SSH_PORT=22232
		;;
	'') usage >&2; die "no OS: give --os=server2025 or --os=win11" ;;
	*) die "unknown OS '$OS': server2025 or win11" ;;
esac

CMD=$1
shift

SSH_PORT=${PFVM_SSH_PORT:-$DEFAULT_SSH_PORT}

# ---------------------------------------------------------------------------------------------------------------------
# Client side: dispatching the commands to a remote KVM host.
# ---------------------------------------------------------------------------------------------------------------------

# Relative to the remote HOME. Not ../buildfiles' IIS VM tooling directory: the two never share anything.
REMOTE_TOOLING='.cache/akeeba-phpfinder-vm/tooling'

# The PFVM_* variables set here, as `env` arguments for the remote command.
forwarded_env() {
	local name value out=''

	for name in PFVM_DIR PFVM_CACHE PFVM_SSH_PORT PFVM_MIN_GRACE_DAYS PFVM_STOP_TIMEOUT; do
		eval "value=\${$name:-}"
		[ -n "$value" ] && out="$out $(printf '%q' "$name=$value")"
	done

	echo "$out"
}

remote_sync() {
	tar --no-xattrs -C "$HERE" -cf - pfvm.sh make-unattend.py downloads guest \
		| ssh -o BatchMode=yes "$HOST" "mkdir -p $REMOTE_TOOLING && tar --no-xattrs -xf - -C $REMOTE_TOOLING" \
		|| die "could not copy the VM tooling to $HOST"
}

# Runs this script's COMMAND ARGS… on the remote KVM host; stdin passes through.
remote_run() {
	local quoted='' arg

	for arg in "$@"; do
		quoted="$quoted $(printf '%q' "$arg")"
	done

	ssh -o BatchMode=yes "$HOST" "env$(forwarded_env) bash $REMOTE_TOOLING/pfvm.sh --os=$OS$quoted"
}

if [ "$HOST" != local ]; then
	case $CMD in
		build|start|stop|status|ssh|ps|destroy) ;;
		put)
			[ $# -ge 2 ] || die "usage: put FILE… GUESTDIR"
			;;
		*) die "unknown command '$CMD' (see --help)" ;;
	esac

	remote_sync

	if [ "$CMD" = put ]; then
		# The files travel as one tar stream to the KVM host, which unpacks and copies them into the guest.
		files=()

		while [ $# -gt 1 ]; do
			[ -f "$1" ] || die "no such file: $1"
			files+=(-C "$(cd "$(dirname "$1")" && pwd)" "$(basename "$1")")
			shift
		done

		tar --no-xattrs -cf - "${files[@]}" | remote_run put-tar "$1"
		exit $?
	fi

	remote_run "$CMD" "$@"
	exit $?
fi

# ---------------------------------------------------------------------------------------------------------------------
# KVM host side.
# ---------------------------------------------------------------------------------------------------------------------

VMDIR=${PFVM_DIR:-$HOME/VMs/akeeba-pf-$OS}
CACHE=${PFVM_CACHE:-${XDG_CACHE_HOME:-$HOME/.cache}/akeeba-phpfinder-vm/downloads}
MIN_GRACE_DAYS=${PFVM_MIN_GRACE_DAYS:-7}
STOP_TIMEOUT=${PFVM_STOP_TIMEOUT:-120}
KVM_DEVICE=${PFVM_KVM_DEVICE:-/dev/kvm}
SNAPSHOT=ready

DISK=$VMDIR/disk.qcow2
MONITOR=$VMDIR/monitor.socket
PIDFILE=$VMDIR/qemu.pid
KEY=$VMDIR/ssh_key
PASSWORD_FILE=$VMDIR/admin-password.txt
LOCKDIR=$VMDIR/.lock

# UEFI (win11): the firmware's variable store and the TPM's state belong to the VM as much as its disk does. Both are
# saved next to the snapshot when it is taken, and put back with it on every start, so Windows always finds the
# firmware and the TPM it was snapshotted with.
OVMF_CODE=${PFVM_OVMF_CODE:-/usr/share/edk2/ovmf/OVMF_CODE_4M.secboot.qcow2}
OVMF_VARS_TEMPLATE=${PFVM_OVMF_VARS:-/usr/share/edk2/ovmf/OVMF_VARS_4M.secboot.qcow2}
VARS=$VMDIR/ovmf-vars.qcow2
VARS_SAVED=$VMDIR/ovmf-vars.$SNAPSHOT.qcow2
TPM_DIR=$VMDIR/tpm
TPM_SAVED=$VMDIR/tpm.$SNAPSHOT
TPM_SOCKET=$VMDIR/swtpm.socket
TPM_PIDFILE=$VMDIR/swtpm.pid

require() {
	local missing='' c

	for c in "$@"; do
		command -v "$c" >/dev/null 2>&1 || missing="$missing $c"
	done

	[ -z "$missing" ] || die "missing on the KVM host:$missing"
}

check_kvm_host() {
	[ "$(uname -s)" = Linux ] || die "the KVM host must run Linux (this is $(uname -s)); use --host=<a Linux machine with KVM>"
	[ -c "$KVM_DEVICE" ] && [ -r "$KVM_DEVICE" ] && [ -w "$KVM_DEVICE" ] \
		|| die "$KVM_DEVICE is missing or not usable by $(id -un): hardware virtualisation is needed"
	require qemu-system-x86_64 qemu-img python3 ssh scp ssh-keygen curl iconv tar socat

	if [ "$FIRMWARE" = uefi ]; then
		require swtpm
		[ -r "$OVMF_CODE" ] && [ -r "$OVMF_VARS_TEMPLATE" ] \
			|| die "missing on the KVM host: the OVMF Secure Boot firmware ($OVMF_CODE, $OVMF_VARS_TEMPLATE; package edk2-ovmf or ovmf)"
	fi
}

# The VM directory holds the guest's SSH key and Administrator password: only its owner may read it.
private_vmdir() {
	(umask 077; mkdir -p "$VMDIR")
	chmod 700 "$VMDIR"
}

# One VM operation at a time: two `start`s, or a `start` and a `build`, must not race.
lock() {
	local attempts=0

	private_vmdir

	# Re-entrant: `start` builds, and rebuilds, under the lock it already holds.
	[ "$(cat "$LOCKDIR/pid" 2>/dev/null)" = "$$" ] && return 0

	while [ "$attempts" -lt 60 ]; do
		attempts=$((attempts + 1))

		if mkdir "$LOCKDIR" 2>/dev/null; then
			echo $$ > "$LOCKDIR/pid"
			trap 'rm -rf "$LOCKDIR"' EXIT
			return 0
		fi

		# A lock whose holder is gone is stale.
		if ! kill -0 "$(cat "$LOCKDIR/pid" 2>/dev/null || echo 0)" 2>/dev/null; then
			rm -rf "$LOCKDIR"
			continue
		fi

		sleep 5
	done

	die "another pfvm.sh operation has held $LOCKDIR for 5 minutes"
}

running() {
	local pid

	pid=$(cat "$PIDFILE" 2>/dev/null) || return 1
	[ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && ps -o args= -p "$pid" 2>/dev/null | grep -q qemu-system
}

built() {
	[ -f "$DISK" ] && qemu-img snapshot -l -U "$DISK" 2>/dev/null | grep -qw "$SNAPSHOT" || return 1
	[ "$FIRMWARE" != uefi ] || { [ -f "$VARS_SAVED" ] && [ -d "$TPM_SAVED" ]; }
}

guest_ssh() {
	ssh -i "$KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
		-o LogLevel=ERROR -o ConnectTimeout=10 -o BatchMode=yes -p "$SSH_PORT" Administrator@127.0.0.1 "$@"
}

guest_put() {
	local dest=${!#} n=$(($# - 1))

	# </dev/null: callers loop over `while read`, and ssh would swallow the rest of the list from stdin.
	guest_ssh "New-Item -ItemType Directory -Force -Path '$dest' | Out-Null" >/dev/null </dev/null
	scp -q -i "$KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
		-o LogLevel=ERROR -o ConnectTimeout=10 -o BatchMode=yes -P "$SSH_PORT" \
		"${@:1:$n}" "Administrator@127.0.0.1:$dest"
}

# Runs a PowerShell script (stdin) in the guest. As -EncodedCommand (UTF-16LE, base64): nothing in it to lose.
guest_ps() {
	local encoded

	encoded=$( (printf "\$ProgressPreference = 'SilentlyContinue'\n[Console]::OutputEncoding = [Text.Encoding]::UTF8\n"; cat) \
		| iconv -f UTF-8 -t UTF-16LE | base64 | tr -d '\n')
	guest_ssh "powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand $encoded"
}

monitor() {
	echo "$1" | socat -t3 - "UNIX-CONNECT:$MONITOR" >/dev/null 2>&1 || true
}

# A picture of the guest's screen (PPM), for when it does not answer: $VMDIR/screen.ppm.
screendump() {
	running && monitor "screendump $VMDIR/screen.ppm" || true
}

wait_ssh() {
	local timeout=$1 waited=0

	while [ "$waited" -lt "$timeout" ]; do
		running || die "QEMU exited while waiting for the guest; see $VMDIR/qemu.log"
		guest_ssh 'exit 0' >/dev/null 2>&1 && return 0
		sleep 10
		waited=$((waited + 10))
	done

	screendump
	die "the guest did not answer SSH within $timeout seconds (its screen: $VMDIR/screen.ppm)"
}

tpm_running() {
	local pid

	pid=$(cat "$TPM_PIDFILE" 2>/dev/null) || return 1
	[ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

tpm_stop() {
	local pid

	pid=$(cat "$TPM_PIDFILE" 2>/dev/null) || { rm -f "$TPM_SOCKET"; return 0; }

	if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
		kill "$pid" 2>/dev/null || true
		sleep 1
		kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
	fi

	rm -f "$TPM_PIDFILE" "$TPM_SOCKET"
}

# A software TPM 2.0 for the guest, whose state lives in $TPM_DIR; it exits when QEMU disconnects (--terminate).
tpm_start() {
	tpm_stop
	(umask 077; mkdir -p "$TPM_DIR")
	swtpm socket --tpm2 --tpmstate "dir=$TPM_DIR" --ctrl "type=unixio,path=$TPM_SOCKET" --terminate \
		--pid "file=$TPM_PIDFILE" --log "file=$VMDIR/swtpm.log" --daemon \
		|| die "swtpm did not start; see $VMDIR/swtpm.log"
}

qemu_boot() {
	local -a machine=(-machine q35) firmware=()

	rm -f "$PIDFILE"

	if [ "$FIRMWARE" = uefi ]; then
		tpm_start
		machine=(-machine q35,smm=on -global driver=cfi.pflash01,property=secure,value=on)
		firmware=(
			-drive "if=pflash,format=qcow2,unit=0,readonly=on,file=$OVMF_CODE"
			-drive "if=pflash,format=qcow2,unit=1,file=$VARS"
			-chardev "socket,id=chrtpm,path=$TPM_SOCKET" -tpmdev emulator,id=tpm0,chardev=chrtpm
			-device tpm-crb,tpmdev=tpm0
		)
	fi

	qemu-system-x86_64 -name "akeeba-pfvm-$OS" -enable-kvm "${machine[@]}" -cpu host -smp 4 -m 8192 -rtc base=utc \
		"${firmware[@]}" \
		-drive file="$DISK",if=ide,index=0,media=disk \
		"$@" \
		-nic "user,model=e1000e,hostfwd=tcp:127.0.0.1:$SSH_PORT-:22" \
		-vga std -display none \
		-monitor "unix:$MONITOR,server,nowait" \
		-pidfile "$PIDFILE" -daemonize \
		>>"$VMDIR/qemu.log" 2>&1 || { tpm_stop; die "QEMU did not start; see $VMDIR/qemu.log"; }
}

vm_stop() {
	local waited=0 pid

	if ! running; then
		rm -f "$PIDFILE"
		tpm_stop
		return 0
	fi

	pid=$(cat "$PIDFILE")

	if [ -S "$MONITOR" ]; then
		monitor system_powerdown
	else
		guest_ssh 'Stop-Computer -Force' >/dev/null 2>&1 || true
	fi

	while running && [ "$waited" -lt "$STOP_TIMEOUT" ]; do
		sleep 2
		waited=$((waited + 2))
	done

	if running; then
		note "no ACPI shutdown after $STOP_TIMEOUT seconds; killing QEMU"
		kill "$pid" 2>/dev/null || true
		sleep 5
		running && kill -9 "$pid" 2>/dev/null || true
	fi

	rm -f "$PIDFILE"
	tpm_stop
}

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

# The pin list without comments, and without the other OS's ISO: <name> <sha256> <url> [<url>…]
pins() {
	grep -v '^[[:space:]]*\(#\|$\)' "$HERE/downloads" | while read -r name rest; do
		case $name in
			*.iso) [ "$name" = "$ISO_NAME" ] || continue ;;
		esac
		echo "$name $rest"
	done
}

# Downloads every pinned file into the cache, unless it is there with the right hash. A failed or wrong download
# leaves nothing behind: the ISOs are several GB.
fetch_all() {
	local name hash urls url got ok

	mkdir -p "$CACHE"

	while read -r name hash urls; do
		if [ -f "$CACHE/$name" ] && [ "$(sha256_of "$CACHE/$name")" = "$hash" ]; then
			continue
		fi

		ok=0

		for url in $urls; do
			note "downloading $name"
			rm -f "$CACHE/$name.part"

			if curl -fL --retry 3 -o "$CACHE/$name.part" "$url"; then
				got=$(sha256_of "$CACHE/$name.part")

				if [ "$got" = "$hash" ]; then
					mv "$CACHE/$name.part" "$CACHE/$name"
					ok=1
					break
				fi

				note "$name from $url has SHA-256 $got, not the pinned $hash"
			fi
		done

		rm -f "$CACHE/$name.part"
		[ "$ok" -eq 1 ] || die "could not download $name with the pinned SHA-256 (see $HERE/downloads)"
	done <<EOF
$(pins)
EOF
}

make_iso() {
	local out=$1 dir=$2

	if command -v mkisofs >/dev/null 2>&1; then
		mkisofs -quiet -J -o "$out" "$dir" 2>/dev/null
	elif command -v genisoimage >/dev/null 2>&1; then
		genisoimage -quiet -J -o "$out" "$dir"
	elif command -v xorriso >/dev/null 2>&1; then
		xorriso -as mkisofs -quiet -J -o "$out" "$dir" 2>/dev/null
	else
		die "missing on the KVM host: one of mkisofs, genisoimage or xorriso"
	fi
}

# Runs a guest script (guest/NAME) as Administrator, appending its output to $VMDIR/setup.log; fails unless its last
# line is PFVM-OK.
guest_script() {
	local name=$1 out

	shift
	guest_put "$HERE/guest/$name" 'C:/pfvm/'
	out=$(guest_ssh "powershell -NoProfile -ExecutionPolicy Bypass -File C:\\pfvm\\$name $*" 2>&1 | tr -d '\r') || true
	printf '%s\n' "== $name $*" "$out" >>"$VMDIR/setup.log"
	printf '%s\n' "$out" | tail -n 1 | grep -qx 'PFVM-OK'
}

# Boots the installed VM as `start` does, without the installation media; QEMU's default CD drive takes the place of
# the answer file's, and gets its drive letter (E:).
boot_installed() {
	if [ "$FIRMWARE" = uefi ]; then
		qemu_boot
	else
		qemu_boot -boot order=c
	fi
}

cmd_build() {
	local force=0 name rest waited

	[ "${1:-}" = --force ] && force=1

	check_kvm_host
	lock

	if [ "$force" -eq 0 ] && built; then
		note "the image is already built (use build --force to rebuild)"
		return 0
	fi

	running && die "the VM is running; stop it first"

	fetch_all

	# The answer file holds the Administrator password in plain text: written under umask 077, deleted once the
	# install has used it. The password itself stays in $PASSWORD_FILE, readable by the owner only.
	(
		umask 077
		rm -rf "$VMDIR/unattend" "$VMDIR/unattend.iso" "$KEY" "$KEY.pub"
		mkdir -p "$VMDIR/unattend"
		ssh-keygen -q -t ed25519 -N '' -C "akeeba-pfvm-$OS (local test VM)" -f "$KEY"
		(head -c 18 /dev/urandom | base64 | tr -d '/+=\n'; echo 'Aa1!') | tr -d '\n' > "$PASSWORD_FILE"
		python3 "$HERE/make-unattend.py" "$OS" "$VMDIR/unattend" "$KEY.pub" "$PASSWORD_FILE" >/dev/null
		make_iso "$VMDIR/unattend.iso" "$VMDIR/unattend"
	)

	rm -f "$DISK" "$VMDIR/build.info" "$VMDIR/setup.log" "$VMDIR/wmic.log" "$VMDIR/screen.ppm" "$VARS" "$VARS_SAVED"
	rm -rf "$TPM_DIR" "$TPM_SAVED"
	qemu-img create -q -f qcow2 "$DISK" 64G

	note "installing $OS_TITLE (unattended)"

	if [ "$FIRMWARE" = uefi ]; then
		qemu-img convert -O qcow2 "$OVMF_VARS_TEMPLATE" "$VARS"
		chmod 600 "$VARS"
		# The installation media's UEFI boot loader asks to "Press any key to boot from CD or DVD", and boots the (empty)
		# disk when nobody does: press a key for it, for the first half minute only. Later boots must not take the CD.
		qemu_boot -drive "file=$CACHE/$ISO_NAME,if=ide,index=1,media=cdrom" \
			-drive "file=$VMDIR/unattend.iso,if=ide,index=2,media=cdrom"

		for _ in $(seq 1 30); do
			monitor 'sendkey spc'
			sleep 1
		done
	else
		qemu_boot -drive "file=$CACHE/$ISO_NAME,if=ide,index=1,media=cdrom" \
			-drive "file=$VMDIR/unattend.iso,if=ide,index=2,media=cdrom" -boot order=c,once=d
	fi

	wait_ssh 3600

	# The first-logon commands run in order; the last one writes this file.
	waited=0

	until guest_ssh 'Test-Path C:\firstlogon-done.txt' 2>/dev/null | grep -q True; do
		[ "$waited" -lt 900 ] || { screendump; vm_stop; die "the first-logon commands did not finish within 15 minutes"; }
		sleep 10
		waited=$((waited + 10))
	done

	rm -rf "$VMDIR/unattend" "$VMDIR/unattend.iso"

	note "installing the VC++ runtime and PHP, turning Windows Update off"
	guest_put "$HERE/downloads" 'C:/pfvm/'

	while read -r name rest; do
		case $name in
			*.iso) ;;
			*) guest_put "$CACHE/$name" 'C:/pfvm/dl/' ;;
		esac
	done <<EOF
$(pins)
EOF

	guest_script setup.ps1 || { tail -n 5 "$VMDIR/setup.log" >&2; vm_stop; die "the guest setup failed; see $VMDIR/setup.log"; }

	# Microsoft's WMIC Feature on Demand: recorded before, removed, recorded again before and after a restart.
	note "removing the WMIC capability"
	guest_script wmic.ps1 before || { tail -n 5 "$VMDIR/setup.log" >&2; vm_stop; die "recording WMIC failed; see $VMDIR/setup.log"; }
	guest_script wmic.ps1 remove || { tail -n 5 "$VMDIR/setup.log" >&2; vm_stop; die "removing WMIC failed; see $VMDIR/setup.log"; }

	# Restart as `start` boots it, without the installation media: the drive letters are then what the suite sees.
	vm_stop
	boot_installed
	wait_ssh 1200
	guest_script wmic.ps1 after || { tail -n 5 "$VMDIR/setup.log" >&2; vm_stop; die "recording WMIC failed; see $VMDIR/setup.log"; }
	sed -n '/^== wmic.ps1/,/^PFVM-OK/p' "$VMDIR/setup.log" >"$VMDIR/wmic.log"

	# What provision.ps1 expects: D: free for its VHD, E: the empty CD drive.
	guest_script check.ps1 || { tail -n 5 "$VMDIR/setup.log" >&2; vm_stop; die "the guest is not as the suite expects; see $VMDIR/setup.log"; }
	guest_ssh 'Remove-Item C:\pfvm -Recurse -Force' >/dev/null || true

	vm_stop
	qemu-img snapshot -c "$SNAPSHOT" "$DISK"

	if [ "$FIRMWARE" = uefi ]; then
		cp -p "$VARS" "$VARS_SAVED"
		rm -rf "$TPM_SAVED"
		cp -a "$TPM_DIR" "$TPM_SAVED"
	fi

	echo "built=$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$VMDIR/build.info"
	note "built; snapshot '$SNAPSHOT' saved; what the WMIC removal left: $VMDIR/wmic.log"
}

# Minutes left on the evaluation licence, as Windows reports them.
grace_minutes() {
	guest_ps <<'PS' | tr -d '\r' | tail -n 1
(Get-CimInstance SoftwareLicensingProduct -Filter 'PartialProductKey IS NOT NULL' | Where-Object { $_.Name -like 'Windows*' } | Select-Object -First 1).GracePeriodRemaining
PS
}

cmd_start() {
	local rebuilt=${1:-0} minutes

	check_kvm_host
	lock

	running && die "the VM is already running (in use by another run?); stop it with: pfvm.sh --os=$OS --host=<host> stop"

	built || cmd_build

	qemu-img snapshot -a "$SNAPSHOT" "$DISK"

	if [ "$FIRMWARE" = uefi ]; then
		cp -p "$VARS_SAVED" "$VARS"
		rm -rf "$TPM_DIR"
		cp -a "$TPM_SAVED" "$TPM_DIR"
	fi

	boot_installed
	wait_ssh 900

	minutes=$(grace_minutes || true)

	case $minutes in
		''|*[!0-9]*) note "could not read the evaluation licence's remaining time ('$minutes'); carrying on" ;;
		*)
			if [ "$minutes" -lt $((MIN_GRACE_DAYS * 1440)) ]; then
				if [ "$rebuilt" -ne 0 ]; then
					vm_stop
					die "the evaluation licence has only $minutes minutes left right after a rebuild"
				fi
				note "the evaluation licence has $((minutes / 1440)) days left; rebuilding the VM"
				vm_stop
				cmd_build --force
				cmd_start 1
				return
			fi
			;;
	esac

	note "ready: $OS_TITLE, guest SSH at 127.0.0.1:$SSH_PORT on $(hostname)"
}

cmd_status() {
	if built; then
		echo "built=yes"
		[ -f "$VMDIR/build.info" ] && sed -n 's/^built=/build_date=/p' "$VMDIR/build.info"
	else
		echo "built=no"
	fi

	if running; then echo "running=yes"; else echo "running=no"; fi

	echo "os=$OS"
	echo "dir=$VMDIR"
	echo "ssh_port=$SSH_PORT"
}

case $CMD in
	build) cmd_build "$@" ;;
	start) cmd_start ;;
	stop) lock; vm_stop ;;
	status) cmd_status ;;
	ssh)
		running || die "the VM is not running"
		guest_ssh "$@"
		;;
	ps)
		running || die "the VM is not running"
		guest_ps
		;;
	put)
		[ $# -ge 2 ] || die "usage: put FILE… GUESTDIR"
		running || die "the VM is not running"
		guest_put "$@"
		;;
	put-tar)
		# Internal: the remote half of `put`. The files arrive as a tar stream on stdin.
		[ $# -eq 1 ] || die "usage: put-tar GUESTDIR"
		running || die "the VM is not running"
		staging=$(mktemp -d)
		tar --no-xattrs -xf - -C "$staging"
		set -- "$staging"/* "$1"
		guest_put "$@"
		rm -rf "$staging"
		;;
	destroy)
		lock
		vm_stop
		rm -f "$DISK" "$MONITOR" "$PIDFILE" "$KEY" "$KEY.pub" "$PASSWORD_FILE" "$VMDIR/unattend.iso" \
			"$VMDIR/build.info" "$VMDIR/setup.log" "$VMDIR/wmic.log" "$VMDIR/qemu.log" "$VMDIR/swtpm.log" \
			"$VMDIR/screen.ppm" "$VARS" "$VARS_SAVED"
		rm -rf "$VMDIR/unattend" "$TPM_DIR" "$TPM_SAVED"
		note "destroyed the VM in $VMDIR (the downloads in $CACHE are kept)"
		;;
	*) die "unknown command '$CMD' (see --help)" ;;
esac
