#!/usr/bin/env bash
#
# Copies the shipped plugin subtree into a local WordPress install for testing.
#
# Since the plugin now lives in a subdirectory, a git clone placed directly in
# wp-content/plugins/ no longer works — WordPress would look for the main file
# one level above where it actually is. A symlink would fix the path but breaks
# plugins_url() on many setups, which matters now that real CSS and JS are
# served from the plugin directory. So we copy.
#
# Usage:
#   bin/deploy-test.sh                       # deploy to the default test site
#   bin/deploy-test.sh /path/to/wp-content/plugins
#
set -euo pipefail

SLUG="folio-drawbridge"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_TARGET="/Users/bordelcl/Local Sites/secure-download/app/public/wp-content/plugins"
TARGET="${1:-${FOLIO_DRAWBRIDGE_TEST_PLUGINS:-$DEFAULT_TARGET}}"

[ -d "$TARGET" ] || { echo "error: plugins directory not found: $TARGET" >&2; exit 1; }

DEST="${TARGET}/${SLUG}"

# --delete keeps the destination an exact mirror, so files removed from the
# source (renamed classes, extracted inline assets) do not linger and shadow
# the new ones.
rsync -a --delete \
	--exclude '.git' \
	--exclude '.DS_Store' \
	"${ROOT}/${SLUG}/" "${DEST}/"

echo "deployed: ${DEST}"
echo "  main file: $( [ -f "${DEST}/${SLUG}.php" ] && echo present || echo MISSING )"
echo "  php files: $(find "$DEST" -name '*.php' | wc -l | tr -d ' ')"
