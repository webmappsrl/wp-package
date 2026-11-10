#!/bin/sh
# Scarica da GitHub la cartella del componente FeatureCollectionMap di wm-package, al commit scritto in package.json
# (config.wmPackageRef), in vendor/: la build la usa com'è. Non è una dipendenza npm perché wm-package porterebbe
# con sé chartjs-node-canvas e canvas, un modulo nativo che qui non serve.
set -eu
cd "$(dirname "$0")/.."
REF=$(node -p "require('./package.json').config.wmPackageRef")
CARTELLA="src/Nova/Fields/FeatureCollectionMap/resources/js"
DEST="vendor/wm-package"
if [ "$(cat "$DEST/.ref" 2>/dev/null || true)" = "$REF" ]; then
	exit 0
fi
rm -rf "$DEST"
mkdir -p "$DEST"
curl -fsSL "https://codeload.github.com/webmappsrl/wm-package/tar.gz/$REF" | tar -xz -C "$DEST" --strip-components=1 "wm-package-$REF/$CARTELLA"
echo "$REF" > "$DEST/.ref"
echo "componente di wm-package al commit $REF"
