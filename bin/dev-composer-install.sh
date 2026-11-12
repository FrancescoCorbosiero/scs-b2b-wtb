#!/bin/sh
# Solo per le sessioni Claude Code web: il proxy GitHub blocca gli archivi (403) dei repository non collegati.
# Le dipendenze si installano da sorgente (git); PHPStan però esiste solo come archivio: lo si scarica da
# raw.githubusercontent.com e lo si mette nella cache di Composer, poi "composer install" lo trova lì.
set -e
cd "$(dirname "$0")/.."
export COMPOSER_ALLOW_SUPERUSER=1
REF=$(php -r '$l = json_decode(file_get_contents("composer.lock"), true); foreach ($l["packages-dev"] as $p) { if ($p["name"] === "phpstan/phpstan") { echo $p["dist"]["reference"]; } }')
URL=$(php -r '$l = json_decode(file_get_contents("composer.lock"), true); foreach ($l["packages-dev"] as $p) { if ($p["name"] === "phpstan/phpstan") { echo $p["dist"]["url"]; } }')
CACHE="$(composer config --global cache-files-dir)/phpstan/phpstan"
KEY=$(php -r 'echo sha1($argv[1]);' "$URL")
if [ -n "$REF" ] && [ ! -f "$CACHE/$KEY.zip" ]; then
  TMP=$(mktemp -d)
  mkdir -p "$TMP/pkg/conf"
  for f in composer.json phpstan phpstan.phar bootstrap.php LICENSE README.md conf/bleedingEdge.neon; do
    curl -sSf -o "$TMP/pkg/$f" "https://raw.githubusercontent.com/phpstan/phpstan/$REF/$f"
  done
  (cd "$TMP/pkg" && zip -qr ../phpstan.zip .)
  mkdir -p "$CACHE"
  cp "$TMP/phpstan.zip" "$CACHE/$KEY.zip"
  rm -rf "$TMP"
fi
composer install --prefer-source --no-interaction
