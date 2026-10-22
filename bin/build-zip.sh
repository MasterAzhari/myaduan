#!/usr/bin/env bash
# Builds an installable plugin zip with only runtime files.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n "s/^define('MYADUAN_VERSION', '\(.*\)');/\1/p" myaduan.php)
rm -rf dist && mkdir -p dist/myaduan
cp -r myaduan.php includes data assets templates README.md dist/myaduan/
(cd dist && zip -qr "myaduan-$version.zip" myaduan && rm -rf myaduan)
echo "dist/myaduan-$version.zip"
