#!/usr/bin/env bash
# Publish a release: bump the version, tag, build the zip, upload it to GitHub.
#   tools/release.sh 1.1.0 ["Release notes"]
#   PRERELEASE=1 tools/release.sh 1.1.0   # pre-release: the update checker ignores it
# Sites running the plugin see a full release within twelve hours (WordPress's
# update cron) and, from 1.0.3, apply it on the same run. Pre-releases are for
# installing by hand on staging first; promote with:
#   gh release edit v1.1.0 --prerelease=false
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
PRE=(); [ "${PRERELEASE:-0}" = "1" ] && PRE=(--prerelease)
gh release create "v$VER" "/tmp/$SLUG.zip" --title "v$VER" --notes "$NOTES" ${PRE[@]+"${PRE[@]}"}
echo "Released $SLUG v$VER${PRE:+ (pre-release)}"
