<?php
/**
 * Self-updating from GitHub releases.
 *
 * Every site running this plugin sees new releases in Plugins > Updates, the
 * way a wordpress.org plugin does, through Plugin Update Checker (MIT, vendored
 * in vendor/). The repo is private, so the site needs a read-only GitHub token
 * in wp-config: define( 'OMENS_PLUGIN_UPDATE_TOKEN', '...' ). One token covers
 * every omens-wp-plugin-* repo. Without it the plugin still works; it just does
 * not know about new versions.
 *
 * Publishing: tools/release.sh <version> bumps, tags and uploads the zip.
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
