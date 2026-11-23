<?php
/**
 * WP-CLI commands: `wp deardoc sync` and `wp deardoc link`.
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_CLI {

	/**
	 * Import new DearDoc posts.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Fetch all posts even if DearDoc's total hasn't changed.
	 *
	 * [--dry-run]
	 * : Show what would be imported without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp deardoc sync
	 *     wp deardoc sync --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function sync( $args, $assoc_args ) {
		self::output( DDS_Importer::run( ! empty( $assoc_args['force'] ), ! empty( $assoc_args['dry-run'] ) ) );
	}

	/**
	 * Link posts already on the site to their DearDoc ids (one-time setup).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be linked without changing anything.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function link( $args, $assoc_args ) {
		self::output( DDS_Importer::link_existing( ! empty( $assoc_args['dry-run'] ) ) );
	}

	private static function output( array $result ): void {
		foreach ( $result['items'] as $item ) {
			WP_CLI::log( ' - ' . $item );
		}
		if ( 'error' === $result['status'] ) {
			WP_CLI::error( $result['message'] );
		} elseif ( 'warning' === $result['status'] ) {
			WP_CLI::warning( $result['message'] );
		} else {
			WP_CLI::success( $result['message'] );
		}
	}
}
