#!/usr/bin/env bash
# Pins a published protocol release: reads the checksum of its archive from the release's SHA256SUMS and writes
# version, asset and sha256 into protocol.lock.json.
#
#   scripts/pin-protocol.sh 0.1.0-alpha.2
#
# Uses the GitHub CLI, so GH_TOKEN must be able to read the (private) protocol repository. Afterwards run
# scripts/fetch-protocol-fixtures.sh and the tests, and fix what the new fixtures expose.
set -euo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: scripts/pin-protocol.sh <version>   (for example 0.1.0-alpha.2, without the v)" >&2
  exit 2
fi

root="$(cd "$(dirname "$0")/.." && pwd)"
lock="$root/protocol.lock.json"
version="$1"
repository="$(php -r '$l = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $l["repository"];' "$lock")"
asset="bitflag-protocol-$version.tar.gz"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
gh release download "v$version" --repo "$repository" --pattern SHA256SUMS --dir "$work"

sha="$(awk -v a="$asset" '$2 == a { print $1 }' "$work/SHA256SUMS")"
if [[ ! "$sha" =~ ^[0-9a-f]{64}$ ]]; then
  echo "SHA256SUMS of v$version does not list $asset." >&2
  exit 1
fi

php -r '
  $lock = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $lock["version"] = $argv[2];
  $lock["asset"] = $argv[3];
  $lock["sha256"] = $argv[4];
  $json = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  file_put_contents($argv[1], str_replace("    ", "  ", $json) . "\n");
' "$lock" "$version" "$asset" "$sha"

echo "Pinned protocol $version ($sha) in protocol.lock.json"
