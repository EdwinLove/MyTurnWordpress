#!/usr/bin/env bash
#
# Build an installable, self-contained zip of the MyTurn WordPress plugin.
#
# Builds in a temporary directory, installs production dependencies only
# (including edwinlove/myturn-core from https://github.com/EdwinLove/MyTurn, as
# pinned in composer.lock), strips development files and zips the result.
#
# Usage: bin/build-zip.sh [output.zip]
# Default output: <repo>/build/myturn.zip

set -euo pipefail

PLUGIN_SLUG=myturn
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

OUTPUT="${1:-$PLUGIN_DIR/build/$PLUGIN_SLUG.zip}"
mkdir -p "$(dirname "$OUTPUT")"
OUTPUT="$(cd "$(dirname "$OUTPUT")" && pwd)/$(basename "$OUTPUT")"

for cmd in composer php rsync zip; do
    command -v "$cmd" >/dev/null || { echo "error: '$cmd' is required" >&2; exit 1; }
done

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
BUILD_DIR="$WORK_DIR/$PLUGIN_SLUG"

echo "Copying plugin sources..."
rsync -a \
    --exclude '/vendor/' \
    --exclude '/bin/' \
    --exclude '/build/' \
    --exclude '/.idea/' \
    --exclude '.git*' \
    --exclude '.DS_Store' \
    "$PLUGIN_DIR/" "$BUILD_DIR/"

echo "Installing production dependencies..."
(
    cd "$BUILD_DIR"
    composer install \
        --no-dev --no-plugins --no-interaction --no-progress --prefer-dist \
        --optimize-autoloader --classmap-authoritative
)
rm -f "$BUILD_DIR/composer.lock"

# Strip development files from the core package.
CORE_VENDOR="$BUILD_DIR/vendor/edwinlove/myturn-core"
rm -rf \
    "$CORE_VENDOR/tests" \
    "$CORE_VENDOR/vendor" \
    "$CORE_VENDOR/.phpunit.cache" \
    "$CORE_VENDOR/phpunit.xml" \
    "$CORE_VENDOR/composer.lock" \
    "$CORE_VENDOR/.gitignore"

if [ -n "$(find "$BUILD_DIR" -type l)" ]; then
    echo "error: build contains symlinks:" >&2
    find "$BUILD_DIR" -type l >&2
    exit 1
fi

echo "Checking autoloading..."
php -r '
    require $argv[1] . "/vendor/autoload.php";
    foreach (["EdwinLove\\MyTurn\\WordPress\\Plugin", "EdwinLove\\MyTurn\\Core\\Infrastructure\\Http\\GuzzleMyTurnPageClient"] as $class) {
        if (!class_exists($class)) {
            fwrite(STDERR, "error: cannot autoload $class\n");
            exit(1);
        }
    }
' "$BUILD_DIR"

echo "Creating zip..."
rm -f "$OUTPUT"
(cd "$WORK_DIR" && zip -qr "$OUTPUT" "$PLUGIN_SLUG")

VERSION="$(sed -n 's/^ \* Version: *//p' "$PLUGIN_DIR/$PLUGIN_SLUG.php" | head -1)"
echo "Built $PLUGIN_SLUG ${VERSION:-(unknown version)}: $OUTPUT ($(du -h "$OUTPUT" | cut -f1 | tr -d ' '))"
