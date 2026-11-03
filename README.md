# Omens Security

Closes username enumeration on a WordPress site and ships the rest of the house hardening behind opt-in filters. Built 2026-11-02 for the roughly 54 Pressable client sites that run a purchased theme (Elementor, WPBakery, Astra and friends) with a child theme and no repo, where the starter theme's `library/php/security.php` has nowhere to live. Repo: `github.com/omens-agency/omens-wp-plugin-security` (public, so sites can install and update from it without a token). Plugin folder and slug: `omens-wp-plugin-security`.

Two files of logic: `includes/security.php` (the guards) and `includes/updates.php` (self-updating from GitHub releases). The guards are a port of the starter theme's `security.php` at 1.11.0 plus the `author_redirect` filter from starter PR #2.

## Why it is a plugin, not theme code

On a custom build the hardening belongs in the theme, and the starter already carries it. On a purchased theme the child theme is the only code we own, and a child theme `functions.php` on 54 sites is 54 copies to keep in step. A plugin is one copy that updates itself. Not an mu-plugin: mu-plugins skip activation hooks, are not auto-updated, and are the documented sync trap in `../spec/environment-sync.md`.

## What it does

| Guard | Default | How |
|---|---|---|
| `?author=N`, `?author_name=x` and `/author/<slug>/` for logged-out visitors | on | 302 to the front page at `template_redirect` priority 1, ahead of the canonical redirect that would otherwise reveal `/author/<login>/`. Real and unknown authors get the same answer. A 302 rather than a 301 so a browser does not cache the bounce for an editor who later logs in. |
| `/wp-json/wp/v2/users` (list and single, including `?rest_route=`) for anonymous requests | on | 401 from `rest_pre_dispatch`. Logged-in users keep the endpoint; `/users/me` is untouched. |
| Core users sitemap (`/wp-sitemap-users-1.xml`) | on | provider dropped |
| Author sitemaps of Yoast SEO (`/author-sitemap.xml`), Rank Math (`/author-sitemap.xml`) and All in One SEO (`/author-sitemap.xml`), which replace core's sitemaps | on | index entry removed and the sitemap emptied through each plugin's own filters (`wpseo_sitemap_exclude_author`; `rank_math/sitemap/index/entry` and `rank_math/sitemap/entry`; `aioseo_sitemap_indexes` and `aioseo_sitemap_author_archives`), each read in that plugin's source first: Yoast 28.8, Rank Math 1.0.280, AIOSEO 5.0.4. Rank Math writes generated sitemaps to disk, so after activation clear them with `wp eval 'RankMath\Sitemap\Cache::invalidate_storage();'`. Yoast's sitemap cache is off by default. AIOSEO Pro shares the Lite sitemap code; miresball runs Pro with its author sitemap already off, so the filters there have nothing to remove. |
| oEmbed `author_name` and `author_url` | on | stripped from the response |
| Login errors that say whether the username exists | on | one generic message; Jetpack's brute-force counting still sees every failure |
| Front-end security headers (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`) | **off** | `omens/security/send_headers` |
| HSTS (`max-age=15552000`, production over HTTPS only) | **off** | `omens/security/send_hsts` |
| XML-RPC pingback methods and the `X-Pingback` header (endpoint kept for Jetpack) | **off** | `omens/security/disable_pingbacks` |
| `DISALLOW_FILE_EDIT` when wp-config does not define it | **off** | `omens/security/disallow_file_edit` |

The four opt-in guards are on by default in the starter theme because a new build is checked against them at launch. On an inherited site they need a look first: `X-Frame-Options: SAMEORIGIN` breaks any embed of the site in a third-party iframe, HSTS pins HTTPS for six months on a domain we may not fully control, pingbacks may be in use, and a host may already send its own headers. The plugin changes none of them until a site opts in.

## Configuration

Everything is a filter. There is no settings screen, on purpose: the configuration belongs in code, in the child theme or a small site plugin, not in a database row that does not survive a migration. Never edit the plugin's own files; every update replaces them.

```php
// Land probes on an About page instead of the front page.
// Same-host URLs only; anything else falls back to the front page.
add_filter( 'omens/security/author_redirect', fn() => home_url( '/about/' ) );

// A blog with bylines: keep author archives and the users sitemap.
// Every author's nicename must then differ from their login.
add_filter( 'omens/security/author_archives', '__return_true' );

// Opt in to the rest of the hardening after checking the site.
add_filter( 'omens/security/send_headers', '__return_true' );
add_filter( 'omens/security/send_hsts', '__return_true' );
add_filter( 'omens/security/disable_pingbacks', '__return_true' );
add_filter( 'omens/security/disallow_file_edit', '__return_true' );

// Adjust the header set once send_headers is on. A false value removes one.
add_filter( 'omens/security/headers', function ( $h ) {
	$h['X-Frame-Options'] = false;                       // the site is embedded elsewhere
	$h['Content-Security-Policy-Report-Only'] = '...';   // trial a policy
	return $h;
} );

// Shorter HSTS while a domain settles.
add_filter( 'omens/security/hsts', fn() => 'max-age=86400' );
```

## Themes built on the starter

The starter theme (`omens-wp-starter-theme` 1.10.0+) ships the same guards under the same `omens_security_*` function names, so a starter-based theme does not need this plugin. If one gets it anyway (a blanket rollout, say), nothing breaks: the plugin defines `OMENS_SECURITY_PLUGIN`, every function in it is wrapped in `function_exists()`, and the starter's loader skips its own `security.php` when the constant is defined (starter PR #3). Note the defaults then flip: the plugin's headers, HSTS, pingback removal and file-editor lock are off until the theme opts in with the filters above. Themes scaffolded before that loader change need the one-line guard themselves:

```php
if ( ! defined( 'OMENS_SECURITY_PLUGIN' ) ) {
	require_once get_template_directory() . '/library/php/security.php';
}
```

## Installing on a Pressable site

Through the Pressable MCP (`install_site_plugins`, which installs and activates in the background; check with `list_site_plugins`):

```
site_id: <id>
plugins: [ { "path": "https://github.com/omens-agency/omens-wp-plugin-security/releases/download/v1.0.1/omens-wp-plugin-security.zip" } ]
```

Or through WP-CLI (`run_site_wpcli_commands`, or `wp` over SSH):

```bash
wp plugin install https://github.com/omens-agency/omens-wp-plugin-security/releases/download/v1.0.1/omens-wp-plugin-security.zip --activate
```

Pin the version in the URL deliberately; the latest release is listed on the repo's Releases page.

### Updates

Updates come from GitHub releases through Plugin Update Checker (`includes/updates.php`, library vendored in `vendor/`). The repo is public, so no token is needed. If a site already defines `OMENS_PLUGIN_UPDATE_TOKEN` for the private `omens-wp-plugin-*` repos it is still sent. WordPress checks for updates every twelve hours; to force one:

```bash
wp eval '$GLOBALS["omens_security_updater"]->checkForUpdates();'
```

## Verifying a site

Logged out, after any page cache is purged:

```bash
curl -sI 'https://example.com/?author=1' | grep -i '^location'        # 302 to the front page, not /author/<login>/
curl -s 'https://example.com/wp-json/wp/v2/users' | head -c 200       # rest_user_cannot_view, 401
curl -sI 'https://example.com/wp-sitemap-users-1.xml' | head -1       # 404 (on a Yoast site it 301s to /author-sitemap.xml instead)
curl -s 'https://example.com/author-sitemap.xml' | grep -c '<loc>'    # 0, or a 404 page with no <loc>
```

The `author-sitemap.xml` check is the one that catches the Yoast gap: with the core users sitemap gone, Yoast still listed every `/author/<login>/` there until 1.0.1.

Pressable's edge answers scripted `curl` from some networks with 429; if that happens, probe from a browser.

## Releasing

```bash
tools/release.sh 1.1.0 "What changed"
```

Bumps the version in the header and constant, commits, tags `v1.1.0`, builds the zip from the tag (tools/ and dotfiles excluded by `.gitattributes`), pushes, and creates the GitHub release with the zip attached. That zip is what sites download.
