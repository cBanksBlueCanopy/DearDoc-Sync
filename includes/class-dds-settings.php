<?php
/**
 * Plugin settings: defaults, retrieval and sanitization.
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_Settings {

	const OPTION = 'dds_settings';

	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * Default settings. Automatic sync stays off until it is explicitly enabled.
	 */
	public static function defaults(): array {
		return array(
			'blog_id'       => '',
			'enabled'       => false,
			'interval'      => 'hourly',
			'post_status'   => 'draft',
			'author_id'     => 0,
			'category_ids'  => array(),
			'cutoff_date'   => '',
			'import_images' => true,
			'demote_h1'     => true,
			'alert_email'   => '',
		);
	}

	/**
	 * Get all settings, or one setting by key.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $settings;
		}
		return $settings[ $key ] ?? null;
	}

	public static function intervals(): array {
		return array(
			'hourly'     => __( 'Every hour', 'deardoc-sync' ),
			'twicedaily' => __( 'Twice a day', 'deardoc-sync' ),
			'daily'      => __( 'Once a day', 'deardoc-sync' ),
		);
	}

	public static function statuses(): array {
		return array(
			'publish' => __( 'Publish immediately', 'deardoc-sync' ),
			'draft'   => __( 'Save as draft for review', 'deardoc-sync' ),
			'pending' => __( 'Pending review', 'deardoc-sync' ),
		);
	}

	/**
	 * Sanitize the settings array submitted from the settings screen.
	 *
	 * @param mixed $input Raw input.
	 */
	public static function sanitize( $input ): array {
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$blog_id        = strtolower( trim( (string) ( $in['blog_id'] ?? '' ) ) );
		$out['blog_id'] = preg_match( self::UUID_PATTERN, $blog_id ) ? $blog_id : '';
		if ( '' !== $blog_id && '' === $out['blog_id'] ) {
			add_settings_error( self::OPTION, 'dds_blog_id', __( 'The DearDoc Blog ID must be a UUID, for example 245542db-d5ed-42e8-816b-3cda26bb3073.', 'deardoc-sync' ) );
		}

		$interval        = (string) ( $in['interval'] ?? '' );
		$out['interval'] = array_key_exists( $interval, self::intervals() ) ? $interval : 'hourly';

		$status             = (string) ( $in['post_status'] ?? '' );
		$out['post_status'] = array_key_exists( $status, self::statuses() ) ? $status : 'draft';

		$author            = absint( $in['author_id'] ?? 0 );
		$out['author_id']  = ( $author && user_can( $author, 'publish_posts' ) ) ? $author : 0;

		$categories          = array_map( 'absint', (array) ( $in['category_ids'] ?? array() ) );
		$out['category_ids'] = array_values(
			array_filter(
				$categories,
				static function ( $id ) {
					return $id && term_exists( $id, 'category' );
				}
			)
		);

		$cutoff             = (string) ( $in['cutoff_date'] ?? '' );
		$out['cutoff_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cutoff ) ? $cutoff : '';

		$out['import_images'] = ! empty( $in['import_images'] );
		$out['demote_h1']     = ! empty( $in['demote_h1'] );

		$email              = sanitize_email( (string) ( $in['alert_email'] ?? '' ) );
		$out['alert_email'] = is_email( $email ) ? $email : '';

		$out['enabled'] = ! empty( $in['enabled'] );
		if ( $out['enabled'] && ( '' === $out['blog_id'] || ! $out['author_id'] ) ) {
			$out['enabled'] = false;
			add_settings_error( self::OPTION, 'dds_enabled', __( 'Automatic sync was left off: it needs both a Blog ID and an author.', 'deardoc-sync' ) );
		}

		return $out;
	}
}
