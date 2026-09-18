#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"

VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
    VERSION="$(
        grep -m1 '^ \* Version:' "$BASE_DIR/paykassa.php" \
        | sed -E 's/^ \* Version:[[:space:]]*//'
    )"
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Invalid version: $VERSION"
    exit 1
fi

if [[ ! -f "$BASE_DIR/assets/admin-settings.css" || ! -f "$BASE_DIR/assets/build/blocks.js" ]]; then
    echo "Compiled front-end assets are missing. Run 'npm run build' first."
    exit 1
fi

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT

PLUGIN_DIR="$STAGE_DIR/paykassa"

mkdir -p "$PLUGIN_DIR"
mkdir -p "$BASE_DIR/dist"

cp "$BASE_DIR/paykassa.php" "$PLUGIN_DIR/"
cp "$BASE_DIR/readme.txt" "$PLUGIN_DIR/"
cp "$BASE_DIR/uninstall.php" "$PLUGIN_DIR/"

cp -a "$BASE_DIR/src" "$PLUGIN_DIR/"
cp -a "$BASE_DIR/assets" "$PLUGIN_DIR/"
cp -a "$BASE_DIR/languages" "$PLUGIN_DIR/"

ZIP="$BASE_DIR/dist/paykassa-$VERSION.zip"

rm -f "$ZIP"

(
    cd "$STAGE_DIR"
    zip -qr "$ZIP" paykassa
)

echo "Created: $ZIP"