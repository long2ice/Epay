#!/usr/bin/env bash
set -euo pipefail
image=${1:?image required}
version=${2:?PHP version required}
[[ "$version" =~ ^8\.2\.[0-9]+$ ]]
actual=$(docker run --rm "$image" php -r 'echo PHP_VERSION;')
[[ "$actual" == "$version" ]]
docker run --rm "$image" php-fpm -v
docker run --rm "$image" php -r '
foreach (["pdo_mysql", "mysqli", "bcmath", "openssl", "curl", "mbstring"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing $extension\n"); exit(1); }
}'
docker run --rm "$image" php-fpm -t
docker run --rm "$image" nginx -t
docker run --rm "$image" sh -ec '
for file in index.php api.php pay.php submit.php plugins/alipaycode/server.php; do
    php -l "$file"
done'
container=$(docker run -d --publish 127.0.0.1::80 "$image")
cleanup() { docker rm -f "$container" >/dev/null; }
trap cleanup EXIT
# This probe exists only in the disposable container, never in the published image.
docker exec "$container" sh -c 'printf "%s\n" "<?php echo PHP_VERSION;" > /var/www/html/runtime-probe.php'
port=$(docker port "$container" 80/tcp | head -1)
for attempt in {1..30}; do
    if response=$(curl --fail --silent --max-time 2 "http://$port/runtime-probe.php") && [[ "$response" == "$version" ]]; then
        curl --fail --silent --output /dev/null "http://$port/favicon.ico"
        echo "PHP $version: Nginx/FPM and static requests passed."
        exit 0
    fi
    sleep 1
done
docker logs "$container"
exit 1
