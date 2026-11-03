# Changelog

## 1.0.6 (2026-11-03)

- **Lost-password guard actually fires.** 1.0.5's hook looked for core's "no account" error code, but core adds that code after `lostpassword_post` runs, so the guard never matched (found on the staging smoke; 1.0.5 was never promoted). It now keys on no account having been found, and only on wp-login.php, so WooCommerce and other front-end account forms keep their own notices.
- Everything listed under 1.0.5 below ships in this release.

## 1.0.5 (2026-11-03, pre-release only, superseded by 1.0.6)

Hardening round from the 2026-11-03 Dev Team audit. No new guards are on by default except the lost-password one; the rest fixes edges of the existing ones and the update pipeline.

- **Updates require the release zip.** The update checker now only accepts the `omens-wp-plugin-security.zip` asset of a release and skips a release that lacks it. Before, a release with no zip (an upload that failed after the release was created, or one made in the GitHub UI) would have fallen back to GitHub's source tarball, which unpacks to a folder named after the commit; auto-installing that would have deactivated the plugin on every site within a day.
- **Auto-update switch anchored and named.** `omens_secplugin_auto_update()` accepts only this plugin's own release-asset URLs (`/omens-agency/omens-wp-plugin-security/releases/download/` on github.com, `/repos/.../releases/assets/` on api.github.com) instead of any github.com path containing the name. New `omens/security/auto_update` filter lets a site opt out of unattended updates.
- **Bylines no longer point at a redirect.** With author archives off, `author_link` now returns the redirect target, so Astra, Elementor and WPBakery bylines link where the request would land instead of to an `/author/<login>/` URL that 302s.
- **Login errors keep core's codes.** The generic "username or password is incorrect" message now replaces only the text of `invalid_username`, `invalid_email` and `incorrect_password`, not the codes. Limit Login Attempts Reloaded and similar plugins only append their lockout text when they see those codes, so 1.0.0 to 1.0.4 silenced it.
- **Lost-password enumeration closed.** An unknown username or email on the lost-password form gets the same "Check your email" confirmation a known one gets, instead of "There is no account with that username or email address".
- Constants are defined behind `defined()` guards, so a second copy of the plugin in a `-main` folder warns about nothing.
- `tools/release.sh` lints every shipped PHP file, requires a `## <version>` CHANGELOG section (used as the release notes), refuses to run off `main` or out of sync with origin, builds the zip in a temp dir and checks its contents, pushes only `main` and the new tag, and publishes draft-then-upload-then-pre-release. New `tools/promote.sh` flips a verified pre-release to the full release and refuses one without its zip asset.
- README: install lines use `releases/latest/download/`, counts and timing corrected, the new checks added to "Verifying a site".

## 1.0.4 (2026-11-03)

- Plugin icon (`assets/icon-128x128.png`, `icon-256x256.png`, `icon.svg`), read by the update checker from the installed plugin's own folder, so it shows on the Updates screen from the next release on.

## 1.0.3 (2026-11-03)

- Releases apply themselves: an `auto_update_plugin` filter answers yes for this slug when the package is one of our GitHub release assets, so the purchased-theme sites that do not run the starter theme's filter still take updates unattended. `PRERELEASE=1 tools/release.sh` for the staging-first cadence.

## 1.0.2 (2026-11-02)

- Every function renamed to `omens_secplugin_*`. 1.0.1 reused the starter theme's `omens_security_*` names, and a theme that declared one of them (the-peak on idypeak.com) fataled for about eight minutes until the plugin was deactivated through the Pressable API.

## 1.0.1 (2026-11-02)

- Author sitemaps of Yoast SEO, Rank Math and All in One SEO emptied through each plugin's own filters; Yoast's `author-sitemap.xml` still listed every login after 1.0.0.
- Author redirects are 302, not 301, so a browser does not cache the bounce for an editor who later logs in.

## 1.0.0 (2026-11-02)

- Port of the starter theme's `library/php/security.php` (1.11.0) plus the `omens/security/author_redirect` filter: author archive and `?author=` guard, `/wp/v2/users` refused for anonymous requests, core users sitemap off, oEmbed author fields dropped, generic login errors; security headers, HSTS, pingback removal and `DISALLOW_FILE_EDIT` behind opt-in filters. Self-updating from GitHub releases through Plugin Update Checker.
