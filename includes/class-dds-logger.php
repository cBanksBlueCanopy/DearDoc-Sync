<?php
/**
 * Small activity log stored in a non-autoloaded option.
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_Logger {

	const OPTION = 'dds_log';
	const MAX    = 100;

	/**
	 * @param string $level   info|warning|error.
	 * @param string $message Plain-text message.
	 */
	public static function add( string $level, string $message ): void {
		$log = self::all();
		array_unshift(
			$log,
			array(
				't' => time(),
				'l' => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'm' => $message,
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
