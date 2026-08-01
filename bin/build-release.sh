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

# Listed once and reused. Piping unzip into `grep -q` would trip pipefail: grep
# exits on its first match, unzip takes SIGPIPE, and the pipeline reports a
# failure even though the file was found.
LISTING="$(unzip -Z1 "$ZIP")"

echo
echo "contents:"
printf '%s\n' "$LISTING" | sed 's/^/  /'
echo
echo "size: $(du -h "$ZIP" | cut -f1)"

echo
echo "sanity checks:"
PROBLEMS=0

# Anything matching these would be flagged in review or is simply dead weight.
for pattern in '(^|/)\.git' '\.DS_Store' '^'"${SLUG}"'/assets/' '^'"${SLUG}"'/docs/' '^'"${SLUG}"'/images/' 'node_modules' '\.claude' '^'"${SLUG}"'/bin/'; do
	if printf '%s\n' "$LISTING" | grep -qE "$pattern"; then
		echo "  ✗ contains ${pattern}"
		PROBLEMS=1
	fi
done

# Everything WordPress needs in order to install and uninstall the plugin.
for required in "${SLUG}/${SLUG}.php" "${SLUG}/readme.txt" "${SLUG}/uninstall.php" "${SLUG}/LICENSE"; do
	if ! printf '%s\n' "$LISTING" | grep -qxF "$required"; then
		echo "  ✗ missing ${required}"
		PROBLEMS=1
	fi
done

if [ "$PROBLEMS" -eq 0 ]; then
	echo "  ✓ all clear"
fi

exit "$PROBLEMS"
