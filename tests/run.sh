#!/usr/bin/env bash
# Run the FilaWarden engine checks and, when WordPress is installed beside
# this plugin, the live HTTP checks. FW_SKIP_LIVE=1 stops after the engine.
set -euo pipefail

plugin="$(cd "$(dirname "$0")/.." && pwd)"
cd "$plugin"

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$plugin" -name '*.php' -print0)

php "$plugin/tests/engine-test.php"
php "$plugin/tests/config-constant-test.php"

if [[ "${FW_SKIP_LIVE:-}" == "1" ]]; then
  echo "live tests skipped"
  exit 0
fi

public="$(cd "$plugin/../../.." && pwd)"
project="$(cd "$public/.." && pwd)"
if [[ ! -f "$public/wp-load.php" ]]; then
  echo "WordPress was not found at $public" >&2
  exit 1
fi

if [[ -f "$project/bin/wp" ]]; then
  php "$project/bin/wp" --path="$public" eval-file "$plugin/tests/live-test.php"
elif command -v wp >/dev/null 2>&1; then
  wp --path="$public" eval-file "$plugin/tests/live-test.php"
else
  echo "wp-cli is not available for live tests" >&2
  exit 1
fi
