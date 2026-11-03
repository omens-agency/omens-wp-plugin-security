#!/usr/bin/env bash
# Publish a release: bump the version, tag, build the zip, upload it to GitHub.
#   tools/release.sh 1.1.0 ["Release notes"]
# Sites running the plugin see the update within twelve hours (WordPress's
# update cron) or on the next visit to Plugins > Updates.
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=$(basename "$PWD")
VER="${1:?version, e.g. 1.1.0}"
NOTES="${2:-Release $VER}"
[ -z "$(git status --porcelain)" ] || { echo "Commit or stash your changes first."; exit 1; }
sed -i '' -E "s/^( \* Version:[[:space:]]+).*/\1$VER/" "$SLUG.php"
sed -i '' -E "s/(define\( 'OMENS_[A-Z_]*VERSION', ')[^']*(' \);)/\1$VER\2/" "$SLUG.php"
git add "$SLUG.php" && git commit -q -m "Release $VER" && git tag "v$VER"
rm -f "/tmp/$SLUG.zip"
git archive --format=zip --prefix="$SLUG/" -o "/tmp/$SLUG.zip" "v$VER"
git push -q origin main --tags
gh release create "v$VER" "/tmp/$SLUG.zip" --title "v$VER" --notes "$NOTES"
echo "Released $SLUG v$VER"
