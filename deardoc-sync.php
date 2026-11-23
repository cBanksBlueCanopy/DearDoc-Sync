<?php
/**
 * Plugin Name:       DearDoc Sync
 * Description:       Automatically imports new posts from a DearDoc blog into WordPress on a schedule, including images and Rank Math SEO fields.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Mahoney Marketing LLC
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deardoc-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'DDS_VERSION', '1.1.0' );
define( 'DDS_FILE', __FILE__ );
define( 'DDS_DIR', plugin_dir_path( __FILE__ ) );

require_once DDS_DIR . 'includes/class-dds-settings.php';
require_once DDS_DIR . 'includes/class-dds-logger.php';
require_once DDS_DIR . 'includes/class-dds-api.php';
require_once DDS_DIR . 'includes/class-dds-importer.php';
require_once DDS_DIR . 'includes/class-dds-cron.php';

register_activation_hook( __FILE__, array( 'DDS_Cron', 'reschedule' ) );
register_deactivation_hook( __FILE__, array( 'DDS_Cron', 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function () {
		DDS_Cron::init();

		if ( is_admin() ) {
			require_once DDS_DIR . 'includes/class-dds-admin.php';
			DDS_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once DDS_DIR . 'includes/class-dds-cli.php';
			WP_CLI::add_command( 'deardoc', 'DDS_CLI' );
		}
	}
);
