#!/bin/sh
#
# Runs INSIDE an akeeba-e2e/php:<version> container (the `harvest` service). Copies that image's real
# PHP CLI and PHP-FPM binaries into /out/<major.minor>/ along with every shared library they link
# against and the dynamic loader itself, so that the `php` service can run them whatever its own Debian
# release is:
#
#   /out/8.3/ld.so --library-path /out/8.3/lib /out/8.3/php -n -v
#
# Usage: harvest.sh   (the version is read from the binary, not from the image tag, so 8.6-rc works)
set -eu

version="$(/usr/local/bin/php -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
dst="/out/${version}"

rm -rf "${dst}"
mkdir -p "${dst}/lib"

cp -L /usr/local/bin/php "${dst}/php"
cp -L /usr/local/sbin/php-fpm "${dst}/php-fpm"

for bin in /usr/local/bin/php /usr/local/sbin/php-fpm; do
	# "libfoo.so.1 => /path/libfoo.so.1 (0x…)" and "/lib/ld-linux-….so.1 (0x…)"; skip linux-vdso.
	ldd "${bin}" | awk '$2 == "=>" && $3 ~ /^\// { print $3 } $1 ~ /^\// { print $1 }' | while read -r lib; do
		cp -L "${lib}" "${dst}/lib/"
	done
done

loader="$(ldd /usr/local/bin/php | awk '$1 ~ /^\// { print $1 }')"
[ -n "${loader}" ] || { echo "harvest: no dynamic loader found for /usr/local/bin/php" >&2; exit 1; }
cp -L "${loader}" "${dst}/ld.so"

# Prove it runs from the copy alone before anybody relies on it.
"${dst}/ld.so" --library-path "${dst}/lib" "${dst}/php" -n -v | head -1

echo "harvested PHP ${version} into ${dst}"
