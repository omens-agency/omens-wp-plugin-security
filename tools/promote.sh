#!/usr/bin/env bash
# Promote a pre-release to the full release that every site applies unattended.
#   VERIFIED=1 tools/promote.sh 1.1.0
# VERIFIED=1 is you saying the pre-release zip was installed on a staging site
# (wp plugin install <asset-url> --force) and the README's checks were run there.
# The script refuses without it, and refuses a release that has no zip asset,
# because a release without its zip would make the update checker fall back to
# GitHub's source tarball, which unpacks to the wrong folder name.
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=$(basename "$PWD")
VER="${1:?version, e.g. 1.1.0}"
[ "${VERIFIED:-0}" = "1" ] || { echo "Install v$VER on a staging site and run the checks first, then: VERIFIED=1 tools/promote.sh $VER"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "gh is not signed in."; exit 1; }
JSON=$(gh release view "v$VER" --json isDraft,isPrerelease,assets)
[ "$(printf '%s' "$JSON" | jq -r .isDraft)" = "false" ] || { echo "v$VER is still a draft."; exit 1; }
[ "$(printf '%s' "$JSON" | jq -r .isPrerelease)" = "true" ] || { echo "v$VER is already a full release."; exit 1; }
[ "$(printf '%s' "$JSON" | jq -r --arg n "$SLUG.zip" '[.assets[] | select(.name == $n)] | length')" = "1" ] || { echo "v$VER has no $SLUG.zip asset; do not promote it."; exit 1; }
gh release edit "v$VER" --prerelease=false --latest >/dev/null
echo "Promoted $SLUG v$VER. Sites running the plugin apply it within about a day; to hurry one along:"
echo "  wp eval '\$GLOBALS[\"omens_security_updater\"]->checkForUpdates();' && wp plugin update $SLUG"
