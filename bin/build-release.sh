#!/usr/bin/env bash
#
# Builds the distributable Folio Drawbridge plugin zip.
#
# Files come from git rather than the working directory, so nothing uncommitted,
# ignored, or merely lying around can end up in a release. Exclusions live in
# .gitattributes as export-ignore entries.
#
# Usage:
#   bin/build-release.sh            # build from HEAD
#   bin/build-release.sh v1.2.0     # build from a tag or any other committish
#
set -euo pipefail

REF="${1:-HEAD}"
SLUG="folio-drawbridge"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Version comes from the plugin header, which is the value WordPress and the
# plugin directory actually read.
VERSION="$(git show "${REF}:${SLUG}.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "error: could not read Version from ${SLUG}.php at ${REF}" >&2; exit 1; }

# readme.txt's Stable tag must match the plugin header, or the directory will
# serve a different version than the one being uploaded.
STABLE="$(git show "${REF}:readme.txt" | sed -n 's/^Stable tag:[[:space:]]*//p' | tr -d '[:space:]')"
if [ "$STABLE" != "$VERSION" ]; then
	echo "error: readme.txt Stable tag ($STABLE) does not match plugin header Version ($VERSION)" >&2
	exit 1
fi

OUT_DIR="${ROOT}/build"
ZIP="${OUT_DIR}/${SLUG}-${VERSION}.zip"
mkdir -p "$OUT_DIR"
rm -f "$ZIP"

# --prefix puts everything under a folio-drawbridge/ directory, which is what
# WordPress expects when a zip is installed through the admin uploader.
git archive --format=zip --prefix="${SLUG}/" --output="$ZIP" "$REF"

echo "built: ${ZIP}"
echo "version: ${VERSION} (stable tag matches)"
echo
echo "contents:"
unzip -Z1 "$ZIP" | sed 's/^/  /'
echo
echo "size: $(du -h "$ZIP" | cut -f1)"

# Anything here would be rejected or flagged during review.
echo
echo "sanity checks:"
PROBLEMS=0
for pattern in '\.git' '\.DS_Store' 'assets/' 'docs/' 'images/' 'node_modules' '\.claude'; do
	if unzip -Z1 "$ZIP" | grep -qE "$pattern"; then
		echo "  ✗ contains ${pattern}"
		PROBLEMS=1
	fi
done
unzip -Z1 "$ZIP" | grep -q "^${SLUG}/${SLUG}.php$" || { echo "  ✗ main plugin file missing"; PROBLEMS=1; }
unzip -Z1 "$ZIP" | grep -q "^${SLUG}/readme.txt$"   || { echo "  ✗ readme.txt missing"; PROBLEMS=1; }
unzip -Z1 "$ZIP" | grep -q "^${SLUG}/uninstall.php$" || { echo "  ✗ uninstall.php missing"; PROBLEMS=1; }
[ "$PROBLEMS" -eq 0 ] && echo "  ✓ all clear"

exit "$PROBLEMS"
