#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="offload-video-to-cloudflare-stream"
DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$DIST_DIR/build"
ZIP_FILE="$DIST_DIR/${PLUGIN_SLUG}.zip"

rm -rf "$BUILD_DIR" "$ZIP_FILE"
mkdir -p "$BUILD_DIR" "$DIST_DIR"
rsync -a --delete --exclude-from="$ROOT_DIR/.distignore" "$ROOT_DIR/$PLUGIN_SLUG/" "$BUILD_DIR/$PLUGIN_SLUG/"
(
  cd "$BUILD_DIR"
  zip -qr "$ZIP_FILE" "$PLUGIN_SLUG"
)
rm -rf "$BUILD_DIR"
echo "Built $ZIP_FILE"
