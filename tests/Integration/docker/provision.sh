#!/bin/sh
#
# Runs INSIDE the `php` container, as root, once per stack. Installs the extra PHP binaries listed in
# /app/fixture/fixtures.txt, each a wrapper around a real PHP harvested into /opt/phpbin/<version>/ by
# harvest.sh, and registers the update-alternatives fixture. Then checks that every one of them runs and
# reports the version it should.
set -eu

FIXTURES=/app/fixture/fixtures.txt
PHPBIN=/opt/phpbin

wrap() {
	dest="$1"; version="$2"; binary="$3"
	dir="${PHPBIN}/${version}"

	[ -x "${dir}/${binary}" ] || { echo "provision: ${dir}/${binary} is missing; was PHP ${version} harvested?" >&2; exit 1; }

	mkdir -p "$(dirname "${dest}")"
	# -n: no php.ini. The runner image's conf.d enables extensions built for ITS PHP version, which this
	# one cannot load.
	cat > "${dest}" <<EOF
#!/bin/sh
exec ${dir}/ld.so --library-path ${dir}/lib ${dir}/${binary} -n "\$@"
EOF
	chmod 0755 "${dest}"
}

grep -v '^[[:space:]]*#' "${FIXTURES}" | grep -v '^[[:space:]]*$' | while read -r path version binary _; do
	wrap "${path}" "${version}" "${binary}"

	case "${binary}" in
		php)     sapi='(cli)';;
		php-fpm) sapi='(fpm-fcgi)';;
		*)       echo "provision: unknown binary '${binary}'" >&2; exit 1;;
	esac

	banner="$("${path}" -v | head -1)"

	case "${banner}" in
		"PHP ${version}."*" ${sapi}"*) echo "  ${path} -> ${banner}";;
		*) echo "provision: ${path} reports '${banner}', expected PHP ${version}.x ${sapi}" >&2; exit 1;;
	esac
done

mkdir -p /opt/pf-ua/link
update-alternatives --install /opt/pf-ua/link/php php /opt/pf-ua/php84 50 >/dev/null
[ "$(update-alternatives --list php)" = "/opt/pf-ua/php84" ] || { echo "provision: update-alternatives fixture failed" >&2; exit 1; }
echo "  update-alternatives --list php -> /opt/pf-ua/php84"
