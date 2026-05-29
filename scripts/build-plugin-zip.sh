#!/bin/bash
# Build a clean WordPress plugin zip from the /plugin directory only.
# Output: dist/wp-audio-buddy.zip

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)/plugin"
DIST_DIR="$(cd "$(dirname "$0")/.." && pwd)/dist"
ZIP_OUTPUT="$DIST_DIR/wp-audio-buddy.zip"

if [ ! -f "$PLUGIN_DIR/wp-audio-buddy.php" ]; then
  echo "Error: plugin/wp-audio-buddy.php not found. Run this script from the repo root."
  exit 1
fi

mkdir -p "$DIST_DIR"
rm -f "$ZIP_OUTPUT"

cd "$PLUGIN_DIR"
zip -r "$ZIP_OUTPUT" . \
  -x "vendor/composer/installers/**" \
  -x "tests-plugin/**" \
  -x ".gitattributes" \
  -x "*.log" \
  -x ".DS_Store" \
  -x "__pycache__/**" \
  -x "*.pyc"

echo "✅ Plugin zip built: $ZIP_OUTPUT"
echo "   Size: $(du -h "$ZIP_OUTPUT" | cut -f1)"