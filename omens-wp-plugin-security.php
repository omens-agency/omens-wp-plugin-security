<?php
/**
 * Plugin Name:       Omens Security
 * Plugin URI:        https://github.com/omens-agency/omens-wp-plugin-security
 * Update URI:        https://github.com/omens-agency/omens-wp-plugin-security
 * Description:       Closes username enumeration (author probes, the public users endpoint, the users sitemap, oEmbed bylines, telling login errors) on sites that run a purchased theme. Security headers, pingback removal and the file-editor lock are available by filter.
 * Version:           1.0.5
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Omens Agency
 * Author URI:        https://omens.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omens-security
 *
 * @package OmensSecurity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guarded so a second copy (a "-main" folder beside the release folder, the
// case the Plugins-screen notice in includes/updates.php is for) warns about
// nothing and the first copy's values stand.
defined( 'OMENS_SECURITY_VERSION' ) || define( 'OMENS_SECURITY_VERSION', '1.0.5' );
defined( 'OMENS_SECURITY_FILE' ) || define( 'OMENS_SECURITY_FILE', __FILE__ );
defined( 'OMENS_SECURITY_DIR' ) || define( 'OMENS_SECURITY_DIR', plugin_dir_path( __FILE__ ) );

// Themes built on omens-wp-starter-theme 1.11.2+ carry the same guards in
// library/php/security.php and skip that file when this constant is defined,
// so the redirects and refusals are not hooked twice. The plugin's own
// functions are prefixed omens_secplugin_, so there is no name collision
// either way.
defined( 'OMENS_SECURITY_PLUGIN' ) || define( 'OMENS_SECURITY_PLUGIN', true );

require_once OMENS_SECURITY_DIR . 'includes/security.php';
require_once OMENS_SECURITY_DIR . 'includes/updates.php';
