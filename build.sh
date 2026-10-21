#!/bin/sh
set -e
cd "$(dirname "$0")/.."
VERSION=$(sed -n 's/^ \* Version: *//p' inpulsia-medios/inpulsia-medios.php)
rm -f inpulsia-medios.zip
zip -rq inpulsia-medios.zip inpulsia-medios -x "*/.git/*" "*/.DS_Store" "*/build.sh" "*/.gitattributes" "*/README.md"
echo "Generado $(pwd)/inpulsia-medios.zip (v$VERSION)"
