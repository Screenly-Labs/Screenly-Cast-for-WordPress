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
# readme.txt — the one file WordPress.org needs most — and zipped from inside the
# build directory, producing an archive with no top-level plugin folder. Both are
# addressed below: the exclude list is a short fixed set, the required files are
# asserted, and the zip is created from the parent so it wraps screenly-cast/.

set -euo pipefail

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

# Fail loudly rather than shipping an incomplete plugin.
missing=0
for required in screenly-cast.php readme.txt; do
  if [ ! -f "$BUILD_DIR/$required" ]; then
    echo "error: $required is missing from the build" >&2
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
