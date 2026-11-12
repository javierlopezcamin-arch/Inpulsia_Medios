#!/bin/sh
set -e
cd "$(dirname "$0")"
VERSION=$(sed -n 's/^ \* Version: *//p' inpulsia-medios.php)
OUT="$(cd .. && pwd)/inpulsia-medios.zip"
TMP=$(mktemp -d)
mkdir "$TMP/inpulsia-medios"
git archive HEAD | tar -x -C "$TMP/inpulsia-medios"
rm -f "$OUT"
(cd "$TMP" && zip -rq "$OUT" inpulsia-medios)
rm -rf "$TMP"
echo "Generado $OUT (v$VERSION, último commit)"
