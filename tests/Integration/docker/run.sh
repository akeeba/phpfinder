#!/usr/bin/env bash
#
# One-shot integration test harness for PHPFinder.
#
# Starts a throwaway container on the shared akeeba-e2e/php:<version> image, copies several more REAL PHP
# CLIs into it at the paths each of PHPFinder's search strategies looks in, and runs the PHPUnit
# integration suite on the host against it. Every run starts from a clean slate and tears the stack down
# on every exit path.
#
# What it does, in order:
#   1. Scrub any previous stack (containers, the phpbin volume).
#   2. Ensure the shared images exist (buildfiles' images.sh).
#   3. Harvest the extra PHP versions (DONOR_VERSIONS) into the phpbin volume.
#   4. Start the `php` container and install the fixtures (provision.sh).
#   5. Run phpunit -c phpunit-integration.xml.
#   6. Tear the stack down.
#
# Usage:  ./run.sh [options] [-- <extra PHPUnit args>]
#   -p, --php=VERSION      Override PHP_VERSION, the runner (e.g. 7.4, 8.1, 8.5, 8.6)
#       --matrix           Run every runner version in PHP_MATRIX, then exit
#   -f, --filter=NAME      Passed through to PHPUnit as --filter
#       --down             Tear everything down and exit
#       --no-tests         Provision the stack but do not run the suite (the stack stays up)
#       --keep-containers  Leave the stack running after the tests finish
#                          (default: the stack is brought down). Then iterate with
#                          phpunit -c phpunit-integration.xml --filter …
#       --host=HOST        Run everything on HOST over SSH, or "local" (default: see README.md "Remote host")
#       --slot=N           With --down or a repeat run on a remote host: which kept slot
#   -h, --help             Show this help
#
# There is no --skip-build: nothing is built. The images come from buildfiles, and the library is
# bind-mounted straight from src/.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")"
INTEGRATION_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${INTEGRATION_DIR}/../.." && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.yml"
# Builds the PHP images every Akeeba E2E stack shares; see ensure_images().
E2E_IMAGES="${REPO_ROOT}/../buildfiles/docker/e2e/images.sh"

# ---------------------------------------------------------------------------
# Pretty logging
# ---------------------------------------------------------------------------
if [ -t 1 ]; then C_B='\033[0;34m'; C_G='\033[0;32m'; C_R='\033[0;31m'; C_Y='\033[0;33m'; C_0='\033[0m'; else C_B=; C_G=; C_R=; C_Y=; C_0=; fi
log()  { printf "${C_B}==>${C_0} %s\n" "$*"; }
ok()   { printf "${C_G}  ok${C_0} %s\n" "$*"; }
warn() { printf "${C_Y}  !!${C_0} %s\n" "$*"; }
die()  { printf "${C_R}ERROR:${C_0} %s\n" "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Local or remote host. In remote mode everything below runs on that host instead, and e2e_remote exits with its status;
# see "Remote E2E hosts" in ../buildfiles/README.md. This comes before anything which needs Docker or sets a trap.
# There is no config file and no published port: the host comes from --host, the environment or build.properties.
# ---------------------------------------------------------------------------
E2E_REMOTE_SH="${REPO_ROOT}/../buildfiles/docker/e2e/remote.sh"
# A nested linked worktree has no ../buildfiles of its own: use the one next to the main repository.
[ -f "${E2E_REMOTE_SH}" ] || E2E_REMOTE_SH="$(dirname "$(git -C "${REPO_ROOT}" rev-parse --path-format=absolute --git-common-dir)")/../buildfiles/docker/e2e/remote.sh"
[ -f "${E2E_REMOTE_SH}" ] || die "buildfiles not found. Check out akeeba/buildfiles next to this repository."
# shellcheck source=/dev/null
. "${E2E_REMOTE_SH}"

E2E_HARNESS_DIR="${SCRIPT_DIR}"
E2E_VERSION_FLAGS=("--php|-p=" "--matrix")
E2E_REQUIRE=(phpunit)

e2e_remote "${BASH_SOURCE[0]}" "$@"

cd "${SCRIPT_DIR}"

command -v docker >/dev/null 2>&1 || die "Docker is not installed or not on PATH."
docker compose version >/dev/null 2>&1 || die "The Docker Compose plugin (docker compose) is not available."
DC() { docker compose -f "${COMPOSE_FILE}" "$@"; }

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
PHP_OVERRIDE=""
ONLY_DOWN=0
RUN_TESTS=1
KEEP_CONTAINERS=0
RUN_MATRIX=0
PHPUNIT_ARGS=()

while [ $# -gt 0 ]; do
	case "$1" in
		-p|--php)          PHP_OVERRIDE="$2"; shift 2;;
		--php=*)           PHP_OVERRIDE="${1#*=}"; shift;;
		--matrix)          RUN_MATRIX=1; shift;;
		-f|--filter)       PHPUNIT_ARGS+=("--filter" "$2"); shift 2;;
		--filter=*)        PHPUNIT_ARGS+=("--filter" "${1#*=}"); shift;;
		--down)            ONLY_DOWN=1; shift;;
		--no-tests)        RUN_TESTS=0; shift;;
		--keep-containers) KEEP_CONTAINERS=1; shift;;
		--host|--slot)     shift 2;;   # read by e2e_remote above
		--host=*|--slot=*) shift;;
		-h|--help)         sed -n '2,/^#   -h, --help/p' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'; exit 0;;
		--)                shift; while [ $# -gt 0 ]; do PHPUNIT_ARGS+=("$1"); shift; done;;
		*)                 die "Unknown option: $1 (use --help)";;
	esac
done

# ---------------------------------------------------------------------------
# Versions: committed in matrix.env, never configured. --php overrides it for one run.
# ---------------------------------------------------------------------------
[ -f "${SCRIPT_DIR}/matrix.env" ] || die "${SCRIPT_DIR}/matrix.env not found."
set -a
# shellcheck source=matrix.env
. "${SCRIPT_DIR}/matrix.env"
set +a
# On a remote E2E host: whatever this run was given, over the sourced file.
e2e_apply_overrides
[ -n "${PHP_OVERRIDE}" ] && export PHP_VERSION="${PHP_OVERRIDE}"

: "${PHP_VERSION:?PHP_VERSION is not set}"
: "${PHP_MATRIX:?PHP_MATRIX is not set}"
: "${DONOR_VERSIONS:?DONOR_VERSIONS is not set}"

# The runner's major.minor, as PHP itself reports it (e.g. 8.6), without any image tag suffix.
RUNNER_MINOR="${PHP_VERSION%%-*}"

# ---------------------------------------------------------------------------
# Matrix mode: re-invoke ourselves once per runner version and aggregate the result.
# ---------------------------------------------------------------------------
if [ "${RUN_MATRIX}" -eq 1 ]; then
	MATRIX_STATUS=0
	FAILED_VERSIONS=""

	for pver in ${PHP_MATRIX}; do
		echo
		log "──────── matrix: PHP ${pver} ────────"
		# This run is already where it is meant to be: --host=local must reach the child, whatever the settings say.
		EXTRA=("--host=local")
		[ "${KEEP_CONTAINERS}" -eq 1 ] && EXTRA+=("--keep-containers")

		"${SCRIPT_PATH}" --php="${pver}" ${EXTRA[@]+"${EXTRA[@]}"} -- ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"} || {
			MATRIX_STATUS=1; FAILED_VERSIONS="${FAILED_VERSIONS} ${pver}"
		}
	done

	echo
	if [ "${MATRIX_STATUS}" -eq 0 ]; then
		ok "Matrix green on: ${PHP_MATRIX}"
	else
		die "Matrix FAILED on:${FAILED_VERSIONS}"
	fi
	exit 0
fi

# ---------------------------------------------------------------------------
# Teardown
# ---------------------------------------------------------------------------
# The name in docker-compose.yml; on a remote E2E host, this run's own (COMPOSE_PROJECT_NAME, which `docker compose`
# honours by itself).
COMPOSE_PROJECT="phpfinder-integration"
[ "${E2E_AGENT:-}" != "1" ] || COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:?}"

# Remove every container and volume of this stack. `down` alone is not enough after an interruption: a
# harvest container killed mid-run (Ctrl-C) may still be on its way out, holding the phpbin volume, so
# `down --volumes` cannot delete it. Force-remove what is left by label until nothing is.
remove_stack() {
	local tries=0 containers volumes

	DC --profile harvest down --volumes --remove-orphans >/dev/null 2>&1 || true

	while [ "${tries}" -lt 15 ]; do
		containers="$(docker ps -aq --filter "label=com.docker.compose.project=${COMPOSE_PROJECT}" 2>/dev/null || true)"
		volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=${COMPOSE_PROJECT}" 2>/dev/null || true)"

		if [ -z "${containers}" ] && [ -z "${volumes}" ]; then
			# shellcheck disable=SC2046
			docker network rm $(docker network ls -q --filter "label=com.docker.compose.project=${COMPOSE_PROJECT}") >/dev/null 2>&1 || true
			return 0
		fi

		# shellcheck disable=SC2086
		[ -n "${containers}" ] && docker rm -f ${containers} >/dev/null 2>&1 || true
		# shellcheck disable=SC2086
		[ -n "${volumes}" ] && docker volume rm -f ${volumes} >/dev/null 2>&1 || true
		tries=$((tries + 1))
		sleep 1
	done

	warn "Could not remove everything of ${COMPOSE_PROJECT}; check docker ps -a / docker volume ls"
}

STACK_DOWN=0
teardown() {
	log "Bringing the Docker stack down"
	remove_stack
	STACK_DOWN=1
	ok "Stack down"
}

scrub() {
	log "Scrubbing any previous stack"
	remove_stack
	ok "Clean slate"
}

if [ "${ONLY_DOWN}" -eq 1 ]; then
	scrub
	ok "Everything torn down."
	exit 0
fi

# However the script ends from here on (the suite finishing, a failed command under `set -e`, die(),
# Ctrl-C or a kill) it never leaves containers or volumes behind. Only --keep-containers and --no-tests
# keep the stack up, on purpose.
FINISHED=0
finish() { FINISHED=1; exit "$1"; }

on_exit() {
	local status=$?

	trap - EXIT INT TERM

	if [ "${KEEP_CONTAINERS}" -eq 1 ] || [ "${RUN_TESTS}" -eq 0 ]; then
		exit "${status}"
	fi

	# Bash 3.2 (macOS) hands an EXIT trap $? = 0 after a fatal shell error such as a failed ${VAR:?}
	# check, then exits with the trap's own status. A run which did not reach finish() has failed.
	[ "${status}" -ne 0 ] || [ "${FINISHED}" -eq 1 ] || status=1

	[ "${STACK_DOWN}" -eq 1 ] || teardown

	exit "${status}"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------
check_versions() {
	local v

	for v in ${DONOR_VERSIONS}; do
		[ "${v}" = "${RUNNER_MINOR}" ] && die "PHP ${PHP_VERSION} is one of the fixture versions (DONOR_VERSIONS=\"${DONOR_VERSIONS}\"); its /usr/local/bin/php would shadow the fixtures. Pick another runner."
	done

	return 0
}

# The images come from buildfiles, shared with every other Akeeba E2E stack. `ensure` builds one only
# when it is missing or its Dockerfile has changed, so this is instant on every run but the first.
ensure_images() {
	local v

	[ -x "${E2E_IMAGES}" ] || die "${E2E_IMAGES} not found. Check out akeeba/buildfiles next to this repository."

	log "Ensuring the shared images (runner ${PHP_VERSION}, fixtures ${DONOR_VERSIONS})"
	"${E2E_IMAGES}" ensure php "${PHP_VERSION}"

	for v in ${DONOR_VERSIONS}; do
		"${E2E_IMAGES}" ensure php "${v}"
	done

	ok "Images ready"
}

harvest() {
	local v

	for v in ${DONOR_VERSIONS}; do
		log "Harvesting the real PHP ${v} binaries"
		DONOR_VERSION="${v}" DC --profile harvest run --rm --no-deps -T harvest \
			|| die "Could not harvest PHP ${v} from akeeba-e2e/php:${v}."
	done
}

bring_up_stack() {
	log "Starting the php container (PHP ${PHP_VERSION})"
	DC up -d --wait php || die "The php container did not start."
	ok "php container is up"

	log "Installing the fixture PHP binaries"
	DC exec -T php sh /app/provision.sh || die "Provisioning the fixtures failed."
	ok "Fixtures installed"
}

phpunit_bin() {
	if command -v phpunit >/dev/null 2>&1; then
		command -v phpunit
	else
		die "PHPUnit not found. Install it globally (composer global require phpunit/phpunit)."
	fi
}

run_tests() {
	[ "${RUN_TESTS}" -eq 1 ] || { warn "Skipping tests (--no-tests). The stack stays up."; return 0; }

	local phpunit major
	local -a display=()
	phpunit="$(phpunit_bin)"

	# How to show the diagnosis of incomplete (known-bug) tests: --verbose up to PHPUnit 9 (the locked
	# 8.5), --display-incomplete from PHPUnit 10, where --verbose no longer exists.
	major="$("${phpunit}" --version | sed -nE 's/^PHPUnit ([0-9]+)\..*/\1/p')"
	if [ -n "${major}" ] && [ "${major}" -ge 10 ]; then
		display=(--display-incomplete --display-skipped)
	else
		display=(--verbose)
	fi

	log "Running the integration suite (runner PHP ${PHP_VERSION})"
	( cd "${REPO_ROOT}" && \
		PHPFINDER_E2E_COMPOSE_FILE="${COMPOSE_FILE}" PHPFINDER_E2E_RUNNER="${RUNNER_MINOR}" \
		"${phpunit}" -c phpunit-integration.xml "${display[@]}" ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"} )
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
log "PHPFinder integration harness — runner PHP ${PHP_VERSION}"
check_versions
scrub
ensure_images
harvest
bring_up_stack

# Run the suite but keep going even if it fails, so the stack is still torn down afterwards. The
# suite's exit status is preserved and re-emitted at the very end.
TEST_STATUS=0
run_tests || TEST_STATUS=$?

echo
if [ "${KEEP_CONTAINERS}" -eq 1 ] || [ "${RUN_TESTS}" -eq 0 ]; then
	ok "Done. The stack remains up for iterating on individual tests:"
	# On a remote E2E host PHPUnit finds this run's stack only by its project name.
	PHPUNIT_PREFIX=""
	[ "${E2E_AGENT:-}" != "1" ] || PHPUNIT_PREFIX="COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT} "
	echo "     ${PHPUNIT_PREFIX}phpunit -c phpunit-integration.xml --filter SomeTest"
	echo "     ${SCRIPT_DIR}/run.sh --down   # to tear it all down"
else
	teardown
	ok "Done. Re-run with --keep-containers to leave the stack up for troubleshooting."
fi

finish "${TEST_STATUS}"
