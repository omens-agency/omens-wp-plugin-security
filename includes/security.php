<?php
/**
 * Hardening, ported from omens-wp-starter-theme library/php/security.php
 * (1.11.0, with the author_redirect filter from starter PR #2) so it can run
 * on sites built on a purchased theme, where there is no theme repo to put it in.
 *
 * Two groups of behavior:
 *
 * Default on (the username-enumeration fixes from the 2026-11-02 audit):
 *   - ?author=N and ?author_name=x probes and author archives redirect (302)
 *     to the front page for logged-out visitors. Existing and non-existent
 *     authors get the same answer, so there is nothing to compare.
 *   - /wp/v2/users (list and single) is refused to anonymous REST requests.
 *   - oEmbed responses lose their author name and archive URL.
 *   - Core's users sitemap is dropped, and so are the author sitemaps of
 *     Yoast SEO, Rank Math and All in One SEO, which replace core's.
 *   - Login errors stop saying whether the username exists.
 *
 * Default off, each behind its own filter, because these were written for new
 * builds. On an inherited site with unknown iframe embeds and host-sent
 * headers they need a per-site look before they go on:
 *   - Front-end security headers.
 *   - HSTS.
 *   - XML-RPC pingback removal.
 *   - DISALLOW_FILE_EDIT.
 *
 * Filters, set from a child theme or a small site plugin (never by editing
 * this file, which is replaced on every update):
 *
 *   omens/security/author_archives    bool, default false. True keeps author
 *                                     archives and the users sitemap for a
 *                                     blog that uses bylines. Then every
 *                                     author's nicename must differ from
 *                                     their login.
 *   omens/security/author_redirect    string, default home_url( '/' ). Where
 *                                     a logged-out author request is sent.
 *                                     Same host only; anything else falls
 *                                     back to the front page.
 *   omens/security/send_headers       bool, default false. Sends the house
 *                                     header set on front-end responses.
 *   omens/security/headers            array of header => value, applied when
 *                                     send_headers is on. A false or empty
 *                                     value removes that header.
 *   omens/security/send_hsts          bool, default false. Sends HSTS on
 *                                     production over HTTPS.
 *   omens/security/hsts               string|false, default
 *                                     'max-age=15552000'.
 *   omens/security/disable_pingbacks  bool, default false. Removes the
 *                                     pingback XML-RPC methods and the
 *                                     X-Pingback header. The endpoint itself
 *                                     stays, because Jetpack depends on it.
 *   omens/security/disallow_file_edit bool, default false. Defines
 *                                     DISALLOW_FILE_EDIT when wp-config has
 *                                     not.
 *
 * Every function here carries the omens_secplugin_ prefix, which no theme
 * uses. The 1.0.x releases used the starter's own omens_security_ names, and
 * since plugins load before themes, a theme that declared any one of those
 * names afterward fataled on the redeclaration (idypeak.com, 2026-11-02,
 * on its theme-local omens_security_headers()). The function_exists() wrappers
 * stay as a belt-and-braces guard, and the main plugin file defines
 * OMENS_SECURITY_PLUGIN so the starter's loader can skip its own security.php
 * and not hook the same guards twice. Filter names are the public contract
 * and have not changed.
 *
 * Notes carried over from the starter:
 *
 * - Pressable runs NGINX, so .htaccess rules are inert. Every guard here is
 *   PHP for that reason.
 * - No Content-Security-Policy. GTM, CookieYes, accessWidget and Gravity
 *   Forms all inject scripts, and a policy that blocks the consent banner is
 *   a compliance failure. A site can add a report-only policy through the
 *   headers filter.
 * - HSTS goes out without includeSubDomains or preload: both need facts about
 *   the client's whole domain (and, for preload, their written agreement)
 *   that a plugin cannot know.
 * - Two-factor is not here: it is WP 2FA (plugin baseline tier 2), enforced
 *   for the Administrator role at deploy.
 *
 * @package OmensSecurity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*----------------------------------------
	Opt-in switches
----------------------------------------*/

if ( ! function_exists( 'omens_secplugin_enabled' ) ) {
	/**
	 * Read one of the opt-in switches. Each is a filter named
	 * omens/security/<switch> that defaults to false.
	 *
	 * @param string $switch send_headers, send_hsts, disable_pingbacks or disallow_file_edit.
	 * @return bool
	 */
	function omens_secplugin_enabled( $switch ) {
		return (bool) apply_filters( 'omens/security/' . $switch, false );
	}
}

/*----------------------------------------
	File editor (opt in)
----------------------------------------*/
add_action( 'init', 'omens_secplugin_file_edit', 1 );

if ( ! function_exists( 'omens_secplugin_file_edit' ) ) {
	/**
	 * The dashboard theme and plugin editor is the first thing an attacker
	 * uses after a login compromise. wp-config.php is the preferred home for
	 * this constant; this is the fallback for hosts where wp-config is
	 * host-managed. Deliberately NOT DISALLOW_FILE_MODS, which also blocks
	 * plugin updates. Runs at init so a theme's filter is already in place.
	 */
	function omens_secplugin_file_edit() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) || ! omens_secplugin_enabled( 'disallow_file_edit' ) ) {
			return;
		}
		define( 'DISALLOW_FILE_EDIT', true );
	}
}

/*----------------------------------------
	XML-RPC: pingbacks off, endpoint kept (opt in)
----------------------------------------*/
add_filter( 'xmlrpc_methods', 'omens_secplugin_xmlrpc_methods' );

if ( ! function_exists( 'omens_secplugin_xmlrpc_methods' ) ) {
	/**
	 * Remove the pingback methods, which let anyone use the site to send
	 * requests to a third party. Jetpack's own methods are untouched.
	 *
	 * @param array $methods XML-RPC method name => callback.
	 * @return array
	 */
	function omens_secplugin_xmlrpc_methods( $methods ) {
		if ( omens_secplugin_enabled( 'disable_pingbacks' ) ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		}
		return $methods;
	}
}

/*----------------------------------------
	User enumeration (default on)
----------------------------------------*/

if ( ! function_exists( 'omens_secplugin_author_archives' ) ) {
	/**
	 * Whether this site keeps author archives (see the file header).
	 *
	 * @return bool
	 */
	function omens_secplugin_author_archives() {
		return (bool) apply_filters( 'omens/security/author_archives', false );
	}
}

if ( ! function_exists( 'omens_secplugin_author_redirect' ) ) {
	/**
	 * Where logged-out author requests land (see the file header). The front
	 * page by default; a site with a better destination, such as an About or
	 * Agency page, sets it:
	 *
	 *   add_filter( 'omens/security/author_redirect', fn() => home_url( '/agency/' ) );
	 *
	 * Validated against the site's own host here, with the front page as the
	 * fallback, because wp_safe_redirect() would otherwise fall back to the
	 * admin URL and send a probe to the login screen.
	 *
	 * @return string
	 */
	function omens_secplugin_author_redirect() {
		$url = (string) apply_filters( 'omens/security/author_redirect', home_url( '/' ) );
		return wp_validate_redirect( $url, home_url( '/' ) );
	}
}

add_filter( 'author_link', 'omens_secplugin_author_link' );

if ( ! function_exists( 'omens_secplugin_author_link' ) ) {
	/**
	 * Bylines on purchased themes (Astra's post meta, Elementor's Post Info and
	 * Author Box, WPBakery grids) print <a href="/author/<slug>/">, and with
	 * author archives off the guard below answers that URL with a 302. Point
	 * the markup where the request would land instead, so visitors do not
	 * click a link that bounces and crawlers do not log an internal redirect
	 * on every post. Logged-in users keep the real archive link, because the
	 * guard lets them through. get_the_author_posts_link() reads this too.
	 *
	 * @param string $link Author archive URL.
	 * @return string
	 */
	function omens_secplugin_author_link( $link ) {
		if ( is_user_logged_in() || omens_secplugin_author_archives() ) {
			return $link;
		}
		return omens_secplugin_author_redirect();
	}
}

add_action( 'template_redirect', 'omens_secplugin_author_guard', 1 );

if ( ! function_exists( 'omens_secplugin_author_guard' ) ) {
	/**
	 * Logged out, ?author=N and ?author_name=x never reach WordPress's
	 * canonical redirect (which would answer with /author/<login-derived-slug>/),
	 * and author archives redirect to the front page (or the site's
	 * omens/security/author_redirect target) unless the site opts in.
	 * Existing and non-existent authors get the same response, so there is
	 * nothing to compare.
	 *
	 * Runs at priority 1, ahead of redirect_canonical at 10. The redirect is
	 * a 302, not a 301: a 301 is cached by the browser, so an editor who hits
	 * their own archive logged out, then logs in, would keep being bounced.
	 */
	function omens_secplugin_author_guard() {
		if ( is_user_logged_in() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only probe check, nothing is processed.
		$probe = isset( $_GET['author'] ) || isset( $_GET['author_name'] );

		// Pretty author URLs arrive as a query var, not in $_GET, and core's 404
		// check clears is_author() for an unknown slug before this runs, so read
		// the var itself: an unknown slug and a real one must both redirect.
		$archive = is_author() || '' !== get_query_var( 'author_name' );

		if ( $probe || ( $archive && ! omens_secplugin_author_archives() ) ) {
			wp_safe_redirect( omens_secplugin_author_redirect(), 302 );
			exit;
		}
	}
}

add_filter( 'rest_pre_dispatch', 'omens_secplugin_rest_users', 10, 3 );

if ( ! function_exists( 'omens_secplugin_rest_users' ) ) {
	/**
	 * Refuse /wp/v2/users (list and single) to anonymous requests. Covers the
	 * ?rest_route= form too, since both dispatch here. REST routes match
	 * case-insensitively, so the check does as well.
	 *
	 * @param mixed           $result  Response to short-circuit with, or null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	function omens_secplugin_rest_users( $result, $server, $request ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}
		if ( 0 === stripos( ltrim( (string) $request->get_route(), '/' ), 'wp/v2/users' ) ) {
			return new WP_Error(
				'rest_user_cannot_view',
				__( 'Sorry, you are not allowed to list users.', 'omens-security' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return $result;
	}
}

add_filter( 'oembed_response_data', 'omens_secplugin_oembed_author' );

if ( ! function_exists( 'omens_secplugin_oembed_author' ) ) {
	/**
	 * oEmbed responses carry the author's name and archive URL. Drop both.
	 *
	 * @param array $data oEmbed response data.
	 * @return array
	 */
	function omens_secplugin_oembed_author( $data ) {
		unset( $data['author_name'], $data['author_url'] );
		return $data;
	}
}

add_filter( 'wp_sitemaps_add_provider', 'omens_secplugin_sitemap_users', 10, 2 );

if ( ! function_exists( 'omens_secplugin_sitemap_users' ) ) {
	/**
	 * Core's users sitemap lists every author slug. Off unless the site keeps
	 * author archives.
	 *
	 * @param WP_Sitemaps_Provider|false $provider Provider instance.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	function omens_secplugin_sitemap_users( $provider, $name ) {
		if ( 'users' === $name && ! omens_secplugin_author_archives() ) {
			return false;
		}
		return $provider;
	}
}

/*
 * Yoast SEO, Rank Math and All in One SEO each replace core's sitemaps with
 * their own, and each has an author sitemap that lists /author/<login>/ for
 * every author, so dropping core's users provider is not enough. Each hook
 * below was read in the plugin's source before use: Yoast 28.8
 * (inc/sitemaps/class-author-sitemap-provider.php), Rank Math 1.0.280
 * (includes/modules/sitemap/providers/class-author.php) and AIOSEO 5.0.4
 * (app/Common/Sitemap/Root.php and Content.php), on 2026-11-02. None of them
 * has a filter on its "author sitemap on/off" setting, so this works on the
 * output instead: the index entry and the entries in the author sitemap.
 */
add_filter( 'wpseo_sitemap_exclude_author', 'omens_secplugin_yoast_authors' );

if ( ! function_exists( 'omens_secplugin_yoast_authors' ) ) {
	/**
	 * Yoast: an empty author list drops author-sitemap.xml from the index and
	 * leaves the file itself with no entries. Applied by exclude_users() in
	 * both get_index_links() and get_sitemap_links().
	 *
	 * @param array $users Users that would be listed.
	 * @return array
	 */
	function omens_secplugin_yoast_authors( $users ) {
		return omens_secplugin_author_archives() ? $users : array();
	}
}

add_filter( 'rank_math/sitemap/index/entry', 'omens_secplugin_rank_math_index', 10, 2 );

if ( ! function_exists( 'omens_secplugin_rank_math_index' ) ) {
	/**
	 * Rank Math: a false index entry is skipped. Type is 'author' for the
	 * author sitemap's index lines.
	 *
	 * @param array|false $item Index entry (loc, lastmod).
	 * @param string      $type Provider type.
	 * @return array|false
	 */
	function omens_secplugin_rank_math_index( $item, $type ) {
		if ( 'author' === $type && ! omens_secplugin_author_archives() ) {
			return false;
		}
		return $item;
	}
}

add_filter( 'rank_math/sitemap/entry', 'omens_secplugin_rank_math_entry', 10, 2 );

if ( ! function_exists( 'omens_secplugin_rank_math_entry' ) ) {
	/**
	 * Rank Math: a false entry is left out of author-sitemap.xml when it is
	 * requested directly. Type is 'user' for author entries.
	 *
	 * @param array|false $url  Sitemap entry (loc, mod).
	 * @param string      $type Entry type.
	 * @return array|false
	 */
	function omens_secplugin_rank_math_entry( $url, $type ) {
		if ( 'user' === $type && ! omens_secplugin_author_archives() ) {
			return false;
		}
		return $url;
	}
}

add_filter( 'aioseo_sitemap_indexes', 'omens_secplugin_aioseo_indexes' );

if ( ! function_exists( 'omens_secplugin_aioseo_indexes' ) ) {
	/**
	 * AIOSEO: drop the author-<filename>[N].xml lines from the root index.
	 * The index is built from a user count with no filter of its own, so
	 * this is the only place to remove them.
	 *
	 * @param array $indexes Index entries (loc, lastmod, count).
	 * @return array
	 */
	function omens_secplugin_aioseo_indexes( $indexes ) {
		if ( omens_secplugin_author_archives() ) {
			return $indexes;
		}
		return array_values(
			array_filter(
				(array) $indexes,
				function ( $index ) {
					return empty( $index['loc'] ) || ! preg_match( '#/author-[^/]+\.xml$#i', (string) $index['loc'] );
				}
			)
		);
	}
}

add_filter( 'aioseo_sitemap_author_archives', 'omens_secplugin_aioseo_authors' );

if ( ! function_exists( 'omens_secplugin_aioseo_authors' ) ) {
	/**
	 * AIOSEO: empty the author sitemap itself, for a direct request.
	 *
	 * @param array $entries Author archive entries.
	 * @return array
	 */
	function omens_secplugin_aioseo_authors( $entries ) {
		return omens_secplugin_author_archives() ? $entries : array();
	}
}

add_filter( 'authenticate', 'omens_secplugin_login_errors', 100 );

if ( ! function_exists( 'omens_secplugin_login_errors' ) ) {
	/**
	 * Core's login errors say whether the username exists ("the password you
	 * entered for the username X is incorrect"). Give the three that do the
	 * same message. The error CODES are kept: Limit Login Attempts Reloaded
	 * and its kind hook authenticate after us and only append their "attempts
	 * remaining" and lockout text when they see core's codes, so replacing the
	 * code (as 1.0.0 to 1.0.4 did) silenced them. Brute-force counting
	 * (Jetpack, Wordfence) sees every failure regardless, because
	 * wp_login_failed fires for any error.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	function omens_secplugin_login_errors( $user ) {
		if ( ! is_wp_error( $user ) ) {
			return $user;
		}
		$telling = array( 'invalid_username', 'invalid_email', 'incorrect_password' );
		$generic = __( '<strong>Error:</strong> The username or password you entered is incorrect.', 'omens-security' );
		foreach ( array_intersect( $user->get_error_codes(), $telling ) as $code ) {
			$user->remove( $code );
			$user->add( $code, $generic );
		}
		return $user;
	}
}

add_action( 'lostpassword_post', 'omens_secplugin_lostpassword', 10, 2 );

if ( ! function_exists( 'omens_secplugin_lostpassword' ) ) {
	/**
	 * The lost-password form answers an unknown username or email with "There
	 * is no account with that username or email address", the same tell the
	 * login guard above closes. Send an unknown name to the same "check your
	 * email" confirmation a known one gets; nothing is emailed, nothing is
	 * revealed. Known accounts are untouched, so password resets still work.
	 *
	 * Core adds its "no account" error (invalidcombo) AFTER this hook fires,
	 * so the signal here is $user_data being false with nothing else wrong
	 * (an unknown email arrives as invalid_email, which is added before).
	 * Only wp-login.php is redirected: WooCommerce and other front-end
	 * account forms call retrieve_password() too and render their own
	 * notices, so they are left alone.
	 *
	 * @param WP_Error      $errors    Errors so far.
	 * @param WP_User|false $user_data The account the form named, or false.
	 */
	function omens_secplugin_lostpassword( $errors, $user_data = null ) {
		if ( $user_data instanceof WP_User || ! is_wp_error( $errors ) ) {
			return;
		}
		if ( 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		$codes = $errors->get_error_codes();
		if ( $codes && ! array_intersect( $codes, array( 'invalidcombo', 'invalid_email' ) ) ) {
			return; // Some other problem (empty field, rate limit); let core say so.
		}
		wp_safe_redirect( add_query_arg( 'checkemail', 'confirm', wp_login_url() ) );
		exit;
	}
}

/*----------------------------------------
	Security headers, front end (opt in)
----------------------------------------*/
add_filter( 'wp_headers', 'omens_secplugin_headers' );

if ( ! function_exists( 'omens_secplugin_headers' ) ) {
	/**
	 * The house header set, sent on front-end responses once a site opts in
	 * with omens/security/send_headers. HSTS has its own switch,
	 * omens/security/send_hsts. wp-admin and wp-login.php already send
	 * X-Frame-Options from core. With every switch off this returns the
	 * headers untouched.
	 *
	 * @param array $headers Header name => value.
	 * @return array
	 */
	function omens_secplugin_headers( $headers ) {
		if ( omens_secplugin_enabled( 'disable_pingbacks' ) ) {
			unset( $headers['X-Pingback'] );
		}

		$set = array();

		if ( omens_secplugin_enabled( 'send_headers' ) ) {
			$set = array(
				'X-Content-Type-Options' => 'nosniff',
				'Referrer-Policy'        => 'strict-origin-when-cross-origin',
				'X-Frame-Options'        => 'SAMEORIGIN',
				'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
			);
		}

		if ( omens_secplugin_enabled( 'send_hsts' ) && is_ssl() && 'production' === wp_get_environment_type() ) {
			$hsts = apply_filters( 'omens/security/hsts', 'max-age=15552000' );
			if ( $hsts ) {
				$set['Strict-Transport-Security'] = $hsts;
			}
		}

		if ( ! $set ) {
			return $headers;
		}

		$set = (array) apply_filters( 'omens/security/headers', $set );

		foreach ( $set as $name => $value ) {
			if ( ! $value ) {
				unset( $headers[ $name ] );
				continue;
			}
			$headers[ $name ] = $value;
		}
		return $headers;
	}
}
