#!/usr/bin/env bash
set -euo pipefail
image=${1:?image digest required}
version=${2:?PHP version required}
[[ "$image" =~ ^ghcr\.io/long2ice/epay@sha256:[a-f0-9]{64}$ ]]
[[ "$version" =~ ^8\.2\.[0-9]+$ ]]
containers=(epay epay-alipaycode-1 epay-alipaycode-2 epay-alipaycode-4)
backup=$(mktemp)
trap 'rm -f "$backup"' EXIT
kubectl -n default get pods -l app=epay -o json > "$backup"
previous=()
while IFS= read -r assignment; do previous+=("$assignment"); done < <(python3 - "$backup" <<'PY'
import json, sys
pods = [p for p in json.load(open(sys.argv[1]))['items']
        if not p['metadata'].get('deletionTimestamp')
        and any(c['type'] == 'Ready' and c['status'] == 'True' for c in p['status'].get('conditions', []))]
assert len(pods) == 1, 'Expected one ready epay Pod before upgrading'
for c in pods[0]['status']['containerStatuses']:
    image = c['imageID'].removeprefix('docker-pullable://')
    assert image.startswith('ghcr.io/long2ice/epay@sha256:'), image
    print(c['name'] + '=' + image)
PY
)
[[ ${#previous[@]} == 4 ]]
printf 'Rollback image: %s\n' "${previous[@]}"
rollback() {
    echo 'Deployment verification failed; restoring previous image digests.' >&2
    kubectl -n default set image deployment/epay "${previous[@]}"
    kubectl -n default rollout status deployment/epay --timeout=300s
}
updates=()
for container in "${containers[@]}"; do updates+=("$container=$image"); done
trap 'rollback' ERR
kubectl -n default set image deployment/epay "${updates[@]}"
kubectl -n default rollout status deployment/epay --timeout=300s
for container in "${containers[@]}"; do
    actual=$(kubectl -n default exec deployment/epay -c "$container" -- php -r 'echo PHP_VERSION;')
    [[ "$actual" == "$version" ]]
done
kubectl -n default exec deployment/epay -c epay -- php-fpm -v
healthy=false
for attempt in {1..15}; do
if kubectl -n default exec deployment/epay -c epay -- php -r '
$body = file_get_contents("http://127.0.0.1/api.php");
if ($body === false || !preg_match("~^HTTP/\\S+ 200~", $http_response_header[0])
    || !is_array(json_decode($body, true))
    || preg_match("/Fatal error|Parse error|SQLSTATE|数据库连接失败/i", $body)) { exit(1); }
echo "Application HTTP check passed\n";'; then
    healthy=true
    break
fi
sleep 2
done
[[ "$healthy" == true ]]
trap - ERR
