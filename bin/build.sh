#!/usr/bin/env bash
#
# Package the plugin exactly as it ships.
#
# screenly-cast/ IS the distributable. Development files live outside it, and the
# compiled assets under screenly-cast/assets/dist are committed because
# WordPress.org runs no build step. Packaging is therefore a copy plus a zip,
# with no exclude list that can drift away from the repository layout.
#
# The previous script relied on .distignore, whose "/*.txt" rule excluded
# readme.txt (the one file WordPress.org needs most), and zipped from inside the
# build directory, producing an archive with no top-level plugin folder. Both are
# addressed below: the exclude list is a short fixed set, the required files are
# asserted, and the zip is created from the parent so it wraps screenly-cast/.

set -euo pipefail

# Name the missing tool rather than failing obscurely at the point of use. Without
# this, a machine without `zip` copies and validates the whole plugin and then dies
# on the last line with nothing to indicate why, which is exactly what happened.
missing_tools=()
for tool in rsync zip; do
  command -v "$tool" >/dev/null 2>&1 || missing_tools+=("$tool")
done
if [ "${#missing_tools[@]}" -ne 0 ]; then
  echo "error: required tool(s) not installed: ${missing_tools[*]}" >&2
  echo "       on Debian or Ubuntu: sudo apt-get install -y ${missing_tools[*]}" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="screenly-cast"
BUILD_ROOT="$ROOT/build"
BUILD_DIR="$BUILD_ROOT/$SLUG"
DIST_DIR="$ROOT/dist"

rm -rf "$BUILD_ROOT" "$DIST_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

rsync -a \
  --exclude '.DS_Store' \
  --exclude 'Thumbs.db' \
  --exclude '*.map' \
  "$ROOT/$SLUG/" "$BUILD_DIR/"

# Fail loudly rather than shipping an incomplete plugin. readme.txt is generated
# rather than committed, so its absence means a step was skipped, not that the file
# was lost, say which step.
missing=0
for required in screenly-cast.php readme.txt; do
  if [ ! -f "$BUILD_DIR/$required" ]; then
    echo "error: $required is missing from the build" >&2
    if [ "$required" = "readme.txt" ]; then
      echo "       it is generated: run \`bun run readme\` first" >&2
    fi
    missing=1
  fi
done
if [ "$missing" -ne 0 ]; then
  exit 1
fi

find "$BUILD_DIR" -type d -exec chmod 755 {} +
find "$BUILD_DIR" -type f -exec chmod 644 {} +

# Zip from the parent directory so the archive contains a single top-level
# screenly-cast/ folder, which is what WordPress expects when installing a zip.
(cd "$BUILD_ROOT" && zip -rq "$DIST_DIR/$SLUG.zip" "$SLUG")

echo "Build:   $BUILD_DIR"
echo "Package: $DIST_DIR/$SLUG.zip"
