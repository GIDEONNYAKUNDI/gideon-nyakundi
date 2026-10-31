#!/bin/bash
# Adds (or refreshes) the security block in public_html/.htaccess without touching anything else in it,
# such as the PHP version lines cPanel writes there.
set -eu
target="${1:?usage: install-htaccess.sh /path/to/public_html}/.htaccess"
block="$(dirname "$0")/htaccess-block.conf"
touch "$target"
tmp="$(mktemp)"
awk '/^# BEGIN Nyakundi 2027 security/{skip=1} !skip{print} /^# END Nyakundi 2027 security/{skip=0}' "$target" > "$tmp"
{ cat "$block"; echo; cat "$tmp"; } > "$target"
rm -f "$tmp"
chmod 644 "$target"
