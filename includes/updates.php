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
 * Publishing: tools/release.sh <version> bumps, tags and uploads the zip.
 * PRERELEASE=1 publishes a pre-release, which the checker ignores; install it
 * by hand on a staging site, then promote it (gh release edit vX --prerelease=false)
 * and the fleet picks it up.
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
		// The release asset (the zip release.sh uploads), not GitHub's source tarball,
		// so the folder inside is the plugin slug and vendor/ is included.
		$checker->getVcsApi()->enableReleaseAssets();
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
add_filter(
	'auto_update_plugin',
	function ( $update, $item ) {
		if ( empty( $item->slug ) || 'omens-wp-plugin-security' !== $item->slug ) {
			return $update;
		}
		$package = ! empty( $item->package ) ? wp_parse_url( $item->package ) : array();
		$host    = strtolower( $package['host'] ?? '' );
		$path    = $package['path'] ?? '';
		if ( in_array( $host, array( 'github.com', 'api.github.com' ), true ) && false !== strpos( $path, '/omens-agency/omens-wp-plugin-security/' ) ) {
			return true;
		}
		return $update;
	},
	10,
	2
);
