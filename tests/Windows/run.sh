#!/usr/bin/env bash
#
# One-shot Windows test harness for PHPFinder. Opt-in: never part of `composer test`.
#
# Runs the library on a REAL Windows: the shared IIS test VM (Windows Server 2022, ../buildfiles/vm/iis), under
# QEMU/KVM on a Linux "KVM host" reached over SSH. PHPUnit runs here; the library runs in the guest under several
# real PHP CLIs, and under IIS.
#
# What it does, in order:
#   1. Refuse to run if the VM is already running (another run is using it).
#   2. Start the VM (iisvm.sh restores its clean snapshot every time).
#   3. Copy src/ and guest/ into it, and run guest/provision.ps1 there: it downloads the pinned PHP builds
#      (downloads), installs the runners and the fixtures, and attaches a second drive.
#   4. Run phpunit -c phpunit-windows.xml once per runner (every PHP version, then IIS).
#   5. Stop the VM, on every exit path.
#
# Usage:  tests/Windows/run.sh --host=HOST [options] [-- <extra PHPUnit args>]
#       --host=HOST        The KVM host: an SSH host with KVM (or `local` on such a Linux machine). Default: the
#                          WINDOWS_KVM_HOST environment variable; there is no other default.
#       --runner=LIST      Only these runners, space or comma separated (e.g. 7.2,8.6,8.4-x86,8.4-dx,iis). Default: all
#   -f, --filter=NAME      Passed to PHPUnit as --filter
#       --src=DIR          Copy this directory's PHPFinder.php and Configuration.php into the guest instead of
#                          src/, e.g. an older revision, to show a test failing on the code before a fix
#       --keep-vm          Leave the VM running afterwards; then iterate with
#                          PHPFINDER_WINDOWS_HOST=HOST PHPFINDER_WINDOWS_RUNNER=8.5 phpunit -c phpunit-windows.xml
#       --down             Stop the VM and exit (after --keep-vm)
#   -h, --help             Show this help
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# The shared IIS test VM, used by every Akeeba project which needs a real Windows.
IISVM="${REPO_ROOT}/../buildfiles/vm/iis/iisvm.sh"

# Every PHP version provision.ps1 installs (downloads, plus the VM's own 8.4), the 32-bit ones (<X.Y>-x86, under
# WoW64), two with cmd.exe's delayed expansion turned on in the registry (<X.Y>-dx), then IIS (FastCGI, PHP 8.4), with
# the 64-bit PHP and with the 32-bit one.
ALL_RUNNERS="7.2 8.0 8.1 8.2 8.3 8.4 8.5 8.6 7.2-x86 8.4-x86 7.2-dx 8.4-dx iis iis-x86"

if [ -t 1 ]; then C_B='\033[0;34m'; C_G='\033[0;32m'; C_R='\033[0;31m'; C_Y='\033[0;33m'; C_0='\033[0m'; else C_B=; C_G=; C_R=; C_Y=; C_0=; fi
log()  { printf "${C_B}==>${C_0} %s\n" "$*"; }
ok()   { printf "${C_G}  ok${C_0} %s\n" "$*"; }
warn() { printf "${C_Y}  !!${C_0} %s\n" "$*"; }
die()  { printf "${C_R}ERROR:${C_0} %s\n" "$*" >&2; exit 1; }
# The comment block at the top of this file, up to its last line.
usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${SCRIPT_PATH}"; }

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
KVM_HOST="${WINDOWS_KVM_HOST:-}"
RUNNERS="${ALL_RUNNERS}"
SRC_DIR="${REPO_ROOT}/src"
KEEP_VM=0
ONLY_DOWN=0
PHPUNIT_ARGS=()

while [ $# -gt 0 ]; do
	case "$1" in
		--host)          KVM_HOST="$2"; shift 2;;
		--host=*)        KVM_HOST="${1#*=}"; shift;;
		--runner)        RUNNERS="${2//,/ }"; shift 2;;
		--runner=*)      RUNNERS="${1#*=}"; RUNNERS="${RUNNERS//,/ }"; shift;;
		-f|--filter)     PHPUNIT_ARGS+=("--filter" "$2"); shift 2;;
		--filter=*)      PHPUNIT_ARGS+=("--filter" "${1#*=}"); shift;;
		--src)           SRC_DIR="$2"; shift 2;;
		--src=*)         SRC_DIR="${1#*=}"; shift;;
		--keep-vm)       KEEP_VM=1; shift;;
		--down)          ONLY_DOWN=1; shift;;
		-h|--help)       usage; exit 0;;
		--)              shift; while [ $# -gt 0 ]; do PHPUNIT_ARGS+=("$1"); shift; done;;
		*)               die "Unknown option: $1 (use --help)";;
	esac
done

[ -n "${KVM_HOST}" ] || { usage >&2; die "No KVM host: give --host=HOST or set WINDOWS_KVM_HOST."; }
[ -f "${IISVM}" ] || die "${IISVM} not found. Check out akeeba/buildfiles next to this repository."

for r in ${RUNNERS}; do
	case " ${ALL_RUNNERS} " in
		*" ${r} "*) ;;
		*) die "Unknown runner '${r}'. Runners: ${ALL_RUNNERS}";;
	esac
done

SRC_DIR="$(cd "${SRC_DIR}" && pwd)" || die "--src: no such directory"
[ -f "${SRC_DIR}/PHPFinder.php" ] && [ -f "${SRC_DIR}/Configuration.php" ] \
	|| die "${SRC_DIR} has no PHPFinder.php and Configuration.php"

VM() { bash "${IISVM}" --host="${KVM_HOST}" "$@"; }

vm_running() { VM status | grep -qx 'running=yes'; }

VM_UP=0
vm_down() {
	log "Stopping the Windows VM on ${KVM_HOST}"
	VM stop || true
	VM_UP=0
	ok "VM stopped"
}

if [ "${ONLY_DOWN}" -eq 1 ]; then
	vm_down
	exit 0
fi

# ---------------------------------------------------------------------------
# Teardown: however the script ends from here on (the suite finishing, a failed command under `set -e`, die(),
# Ctrl-C or a kill), it stops the VM it started. Only --keep-vm leaves it running, on purpose.
# ---------------------------------------------------------------------------
FINISHED=0
finish() { FINISHED=1; exit "$1"; }

# The script's own STDOUT and STDERR. A signal which arrives while a function runs with its output redirected (e.g.
# provisioning, into PROVISION_LOG) runs the traps with that redirection still in place: on_exit() restores these, so
# that what it prints is not lost in a temporary file.
exec 3>&1 4>&2
PROVISION_LOG=""

on_exit() {
	local status=$?

	trap - EXIT INT TERM
	exec 1>&3 2>&4

	[ -z "${PROVISION_LOG}" ] || rm -f "${PROVISION_LOG}"

	# Bash 3.2 (macOS) hands an EXIT trap $? = 0 after a fatal shell error such as a failed ${VAR:?} check, then
	# exits with the trap's own status. A run which did not reach finish() has failed.
	[ "${status}" -ne 0 ] || [ "${FINISHED}" -eq 1 ] || status=1

	if [ "${VM_UP}" -eq 1 ] && [ "${KEEP_VM}" -eq 0 ]; then
		vm_down
	fi

	exit "${status}"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------
start_vm() {
	# Never take over a VM another run is using: iisvm.sh start refuses too, but checking first means the teardown
	# never stops a VM this run did not start.
	if vm_running; then
		die "The Windows VM on ${KVM_HOST} is already running: another run is using it. If it is one of yours (--keep-vm), stop it with: ${SCRIPT_PATH} --host=${KVM_HOST} --down"
	fi

	log "Starting the Windows VM on ${KVM_HOST}"
	# Set first, so that on_exit() stops a VM which started but did not answer.
	VM_UP=1
	VM start || die "Could not start the Windows VM."
	ok "VM is up"
}

provision() {

	log "Copying the library and the guest scripts into the VM"
	[ "${SRC_DIR}" = "${REPO_ROOT}/src" ] || warn "Testing the library in ${SRC_DIR}, not src/"
	VM put "${SRC_DIR}/PHPFinder.php" "${SRC_DIR}/Configuration.php" 'C:/pf/src/'
	VM put "${SCRIPT_DIR}/downloads" "${SCRIPT_DIR}/guest/provision.ps1" "${SCRIPT_DIR}/guest/probe.php" \
		"${SCRIPT_DIR}/guest/relay.php" "${SCRIPT_DIR}/guest/iis-probe.php" 'C:/pf/'

	log "Provisioning the guest: PHP downloads, runners, fixtures, second drive"
	PROVISION_LOG="$(mktemp "${TMPDIR:-/tmp}/phpfinder-windows-provision.XXXXXX")"
	VM ssh 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File C:\pf\provision.ps1' >"${PROVISION_LOG}" 2>&1 || true
	tr -d '\r' <"${PROVISION_LOG}" | sed 's/^/     /'

	if ! tr -d '\r' <"${PROVISION_LOG}" | tail -n 1 | grep -qx 'PF-PROVISION-OK'; then
		rm -f "${PROVISION_LOG}"
		die "Provisioning the guest failed (see above)."
	fi

	rm -f "${PROVISION_LOG}"
	ok "Guest provisioned"
}

phpunit_bin() {
	command -v phpunit >/dev/null 2>&1 || die "PHPUnit not found. Install it globally (composer global require phpunit/phpunit)."
	command -v phpunit
}

run_suite() {
	local runner=$1 phpunit major
	local -a display=()

	phpunit="$(phpunit_bin)"
	major="$("${phpunit}" --version | sed -nE 's/^PHPUnit ([0-9]+)\..*/\1/p')"
	if [ -n "${major}" ] && [ "${major}" -ge 10 ]; then
		display=(--display-incomplete --display-skipped)
	else
		display=(--verbose)
	fi

	log "Running the Windows suite (runner: ${runner})"
	( cd "${REPO_ROOT}" && \
		PHPFINDER_WINDOWS_HOST="${KVM_HOST}" PHPFINDER_WINDOWS_RUNNER="${runner}" PHPFINDER_WINDOWS_IISVM="${IISVM}" \
		"${phpunit}" -c phpunit-windows.xml "${display[@]}" ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"} )
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
log "PHPFinder Windows harness: KVM host ${KVM_HOST}, runners ${RUNNERS}"
start_vm
provision

FAILED=""
for runner in ${RUNNERS}; do
	echo
	run_suite "${runner}" || FAILED="${FAILED} ${runner}"
done

echo
if [ "${KEEP_VM}" -eq 1 ]; then
	ok "The VM stays up. Iterate with:"
	echo "     PHPFINDER_WINDOWS_HOST=${KVM_HOST} PHPFINDER_WINDOWS_RUNNER=8.5 phpunit -c phpunit-windows.xml --filter SomeTest"
	echo "     ${SCRIPT_PATH} --host=${KVM_HOST} --down   # to stop it"
fi

if [ -n "${FAILED}" ]; then
	printf "${C_R}FAILED${C_0} on:%s\n" "${FAILED}"
	finish 1
fi

ok "Green on: ${RUNNERS}"
finish 0
