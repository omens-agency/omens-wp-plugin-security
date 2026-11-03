#!/usr/bin/env bash
# Cut a release: lint, bump, tag, build the zip, publish it on GitHub as a
# PRE-RELEASE. The update checker ignores pre-releases, so nothing reaches a
# site until tools/promote.sh <version> flips it to the full release. Full
# releases APPLY themselves on every site within about a day (WordPress's update
# cron offers them, our auto_update_plugin filter accepts them), which is why
# the gate is the default and not an option.
#
#   tools/release.sh 1.1.0          # pre-release: install by hand on staging, probe, then promote
#   FULL=1 tools/release.sh 1.1.0   # publish as the full release at once (only for a fix already
#                                   # run on a staging site; the fleet applies it unattended)
#
# Needs a "## 1.1.0" section in CHANGELOG.md: it becomes the release notes that
# WordPress shows under "View version details". Release assets are created as a
# draft, the zip is uploaded, then the draft is published, so a release without
# its zip never exists for the update checker to see.
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=$(basename "$PWD")
VER="${1:?version, e.g. 1.1.0}"
BRANCH=$(git branch --show-current)
[ "$BRANCH" = "main" ] || { echo "Release from main (you are on $BRANCH)."; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Commit or stash your changes first."; exit 1; }
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "main and origin/main differ; pull or push first."; exit 1; }
git rev-parse -q --verify "refs/tags/v$VER" >/dev/null && { echo "Tag v$VER already exists."; exit 1; }
/usr/bin/grep -Eq "^## $VER( |$)" CHANGELOG.md || { echo "CHANGELOG.md has no \"## $VER\" section; write the notes first."; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "gh is not signed in."; exit 1; }

# Syntax-check everything we ship. vendor/ is upstream code and is not edited here.
while IFS= read -r f; do php -l "$f" >/dev/null || { echo "Syntax error in $f"; exit 1; }; done < <(git ls-files '*.php' ':!vendor/**')

# Release notes = the CHANGELOG section for this version.
NOTES=$(awk -v v="$VER" '$0 ~ "^## "v"( |$)" {p=1; next} /^## / {p=0} p' CHANGELOG.md | sed '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')
[ -n "$NOTES" ] || { echo "The ## $VER section in CHANGELOG.md is empty."; exit 1; }

# Bump the header and the constant, and check both took.
sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$VER/" "$SLUG.php"
sed -i.bak -E "s/(define\( 'OMENS_[A-Z_]*VERSION', ')[^']*(' \);)/\1$VER\2/" "$SLUG.php"
rm -f "$SLUG.php.bak"
/usr/bin/grep -Eq "^ \* Version:[[:space:]]+$VER$" "$SLUG.php" || { echo "Header bump failed."; git checkout -- "$SLUG.php"; exit 1; }
/usr/bin/grep -Eq "VERSION', '$VER' \);" "$SLUG.php" || { echo "Constant bump failed."; git checkout -- "$SLUG.php"; exit 1; }
php -l "$SLUG.php" >/dev/null

git add "$SLUG.php" && git commit -q -m "Release $VER" && git tag "v$VER"

TMP=$(mktemp -d)
ZIP="$TMP/$SLUG.zip"
git archive --format=zip --prefix="$SLUG/" -o "$ZIP" "v$VER"
# grep -c, not -q: under pipefail, -q exits on the first match and unzip dies of SIGPIPE.
ZIPLIST=$(unzip -Z1 "$ZIP")
/usr/bin/grep -qx "$SLUG/$SLUG.php" <<<"$ZIPLIST" || { echo "Zip is missing $SLUG/$SLUG.php."; exit 1; }
/usr/bin/grep -q "^$SLUG/vendor/plugin-update-checker/" <<<"$ZIPLIST" || { echo "Zip is missing vendor/plugin-update-checker."; exit 1; }

git push -q origin main "v$VER"

trap 'echo; echo "Publishing failed after the push. Finish by hand:"; echo "  gh release create v$VER --draft --title v$VER --notes-file <notes>"; echo "  gh release upload v$VER $ZIP"; echo "  gh release edit v$VER --draft=false --prerelease"' ERR
gh release create "v$VER" --draft --title "v$VER" --notes "$NOTES" >/dev/null
gh release upload "v$VER" "$ZIP"
if [ "${FULL:-0}" = "1" ]; then
	gh release edit "v$VER" --draft=false --prerelease=false --latest >/dev/null
	echo "Released $SLUG v$VER as the full release. The fleet applies it within about a day."
else
	gh release edit "v$VER" --draft=false --prerelease >/dev/null
	echo "Released $SLUG v$VER as a pre-release. Install it on a staging site, probe, then: tools/promote.sh $VER"
	echo "  https://github.com/omens-agency/$SLUG/releases/download/v$VER/$SLUG.zip"
fi
trap - ERR
