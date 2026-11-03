<?php
/**
 * Self-updating from GitHub releases.
 *
 * Every site running this plugin sees new releases in Plugins > Updates, the
 * way a wordpress.org plugin does, through Plugin Update Checker (MIT, vendored
 * in vendor/). The repo is public, so no token is needed. If the site defines
 * OMENS_PLUGIN_UPDATE_TOKEN (the shared read-only token for the private
 * omens-wp-plugin-* repos) it is still sent, which also lifts GitHub's
 * anonymous API rate limit.
 *
 * Releases also APPLY themselves: the auto_update_plugin filter below answers
 * yes for this plugin whenever the package is one of our GitHub release
 * assets. The starter theme does the same for every omens-wp-plugin-* slug,
 * but the sites this plugin exists for do not run the starter, so the plugin
 * answers for itself. WordPress applies it on its twice-daily update run.
 *
 * Publishing: tools/release.sh <version> lints, bumps, tags and uploads the
 * zip as a PRE-RELEASE, which the checker ignores. Install that zip by hand on
 * a staging site, run the README checks, then VERIFIED=1 tools/promote.sh
 * <version> makes it the full release and the fleet picks it up. Only the
 * release asset named after the slug is ever accepted; a release without it
 * is skipped, never replaced by GitHub's source tarball.
 *
 * @package OmensSecurity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			$loader = OMENS_SECURITY_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
			if ( ! file_exists( $loader ) ) {
				return;
			}
			require_once $loader;
		}
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/omens-agency/omens-wp-plugin-security/',
			OMENS_SECURITY_FILE,
			'omens-wp-plugin-security'
		);
		// Only the release asset release.sh uploads (the zip named after the slug),
		// never GitHub's source tarball: that unpacks to a folder named after the
		// commit, so installing it would deactivate the plugin. REQUIRE means a
		// release with no matching zip is skipped rather than falling back.
		$api = $checker->getVcsApi();
		$api->enableReleaseAssets( '/^omens-wp-plugin-security\.zip$/', $api::REQUIRE_RELEASE_ASSETS );
		$checker->setBranch( 'main' );
		if ( defined( 'OMENS_PLUGIN_UPDATE_TOKEN' ) && OMENS_PLUGIN_UPDATE_TOKEN ) {
			$checker->setAuthentication( OMENS_PLUGIN_UPDATE_TOKEN );
		}
		// For a manual probe: wp eval '$GLOBALS["omens_security_updater"]->checkForUpdates();'
		$GLOBALS['omens_security_updater'] = $checker;
	}
);

/**
 * GitHub's "Download ZIP" unpacks to omens-wp-plugin-security-main. WordPress
 * runs it, but a later install from the release zip lands beside it as a second
 * copy. Say so on the Plugins screen so the folder gets fixed before that happens.
 */
add_action(
	'admin_notices',
	function () {
		$folder = basename( dirname( OMENS_SECURITY_FILE ) );
		if ( 'omens-wp-plugin-security' === $folder || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: 1: plugin name, 2: folder it is in, 3: folder it should be in */
				'%1$s is installed in the "%2$s" folder instead of "%3$s". Deactivate it, rename the folder to %3$s, and activate it again, so the next update replaces it instead of installing a second copy.',
				'Omens Security',
				$folder,
				'omens-wp-plugin-security'
			)
		) . '</p></div>';
	}
);

if ( ! function_exists( 'omens_secplugin_auto_update' ) ) {
	/**
	 * Apply our own releases automatically.
	 *
	 * The slug alone is not proof: the package has to be one of our GitHub
	 * release assets, so a same-named plugin from anywhere else is not waved
	 * through. Returns the site's own answer for everything else.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   The update offer (slug, package, ...).
	 * @return bool|null
	 */
	function omens_secplugin_auto_update( $update, $item ) {
		if ( empty( $item->slug ) || 'omens-wp-plugin-security' !== $item->slug ) {
			return $update;
		}
		/**
		 * Let a site opt out of unattended updates for this plugin (it still
		 * sees them in Plugins > Updates). Default true.
		 *
		 * @param bool $auto Apply our releases automatically.
		 */
		if ( ! apply_filters( 'omens/security/auto_update', true ) ) {
			return $update;
		}
		// Only the two URL shapes the update checker writes for a release asset:
		//   https://github.com/omens-agency/omens-wp-plugin-security/releases/download/<tag>/omens-wp-plugin-security.zip
		//   https://api.github.com/repos/omens-agency/omens-wp-plugin-security/releases/assets/<id>
		// Checked segment by segment over https with no userinfo or port, so a
		// source zipball, a path that merely contains our name, or a ".." that
		// GitHub would resolve elsewhere all get the site's own answer.
		return omens_secplugin_auto_update_asset_url( (string) $item->package, 'omens-wp-plugin-security' ) ? true : $update;
	}

	/**
	 * Whether a package URL is one of this plugin's own GitHub release assets.
	 *
	 * @param string $url  Package URL from the update offer.
	 * @param string $slug Plugin slug (folder and repo name).
	 * @return bool
	 */
	function omens_secplugin_auto_update_asset_url( $url, $slug ) {
		$package = '' !== $url ? wp_parse_url( $url ) : false;
		if ( ! is_array( $package ) || 'https' !== strtolower( $package['scheme'] ?? '' ) || isset( $package['user'] ) || isset( $package['pass'] ) || isset( $package['port'] ) ) {
			return false;
		}
		$parts = explode( '/', trim( (string) ( $package['path'] ?? '' ), '/' ) );
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $part ) ) {
				return false; // Empty, dot, dot-dot or percent-encoded segment.
			}
		}
		$host = strtolower( $package['host'] ?? '' );
		if ( 'github.com' === $host ) {
			return 6 === count( $parts )
				&& array( 'omens-agency', $slug, 'releases', 'download' ) === array_slice( $parts, 0, 4 )
				&& $slug . '.zip' === $parts[5];
		}
		if ( 'api.github.com' === $host ) {
			return 6 === count( $parts )
				&& array( 'repos', 'omens-agency', $slug, 'releases', 'assets' ) === array_slice( $parts, 0, 5 )
				&& ctype_digit( $parts[5] );
		}
		return false;
	}
}
add_filter( 'auto_update_plugin', 'omens_secplugin_auto_update', 10, 2 );
