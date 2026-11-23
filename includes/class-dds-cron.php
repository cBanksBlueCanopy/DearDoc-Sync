<?php
/**
 * WP-Cron scheduling.
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_Cron {

	const HOOK = 'dds_sync_event';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'add_option_' . DDS_Settings::OPTION, array( __CLASS__, 'reschedule' ), 10, 0 );
		add_action( 'update_option_' . DDS_Settings::OPTION, array( __CLASS__, 'reschedule' ), 10, 0 );
		add_action( 'before_delete_post', array( 'DDS_Importer', 'remember_deleted' ) );
	}

	public static function run(): void {
		if ( DDS_Settings::get( 'enabled' ) ) {
			DDS_Importer::run( false, false );
		}
	}

	/**
	 * Clear and re-create the schedule from the current settings.
	 */
	public static function reschedule(): void {
		self::unschedule();
		$settings = DDS_Settings::get();
		if ( $settings['enabled'] ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $settings['interval'], self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
