#!/bin/sh
set -e

if [ -n "$ADMINER_DESIGN" ]; then
	# Only create link on initial start, to ensure that explicit changes to
	# adminer.css after the container was started once are preserved.
	if [ ! -e .adminer-init ]; then
		ln -sf "designs/$ADMINER_DESIGN/adminer.css" .
	fi
fi

# The key has to live outside the web root: php -S serves every file under /var/www/html.
# A volume on /var/lib/adminer keeps saved logins working across container recreation.
if [ -z "${ADMINER_PERMANENT_LOGIN_KEY:-}" ] && [ ! -s /var/lib/adminer/permanent.key ]; then
	key=$(php -r 'echo bin2hex(random_bytes(32));' 2>/dev/null || true)
	if [ -n "$key" ] && printf '%s\n' "$key" > /var/lib/adminer/permanent.key; then
		chmod 600 /var/lib/adminer/permanent.key || true
	else
		echo "adminer: could not write /var/lib/adminer/permanent.key; permanent login will not survive a restart" >&2
		rm -f /var/lib/adminer/permanent.key 2>/dev/null || true
	fi
fi

number=1
for PLUGIN in $ADMINER_PLUGINS; do
	php plugin-loader.php "$PLUGIN" > plugins-enabled/$(printf "%03d" $number)-$PLUGIN.php
	number=$(($number+1))
done

touch .adminer-init || true

exec "$@"
