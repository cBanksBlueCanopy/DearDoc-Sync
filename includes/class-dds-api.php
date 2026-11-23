<?php
/**
 * DearDoc publish API client (read-only).
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_API {

	const BASE      = 'https://publish-api.getdeardoc.com/api/v2/blog/';
	const PAGE_SIZE = 100; // The API caps page_size at 100.
	const MAX_PAGES = 100; // Safety stop: 10,000 posts.

	/** @var string */
	private $blog_id;

	public function __construct( string $blog_id ) {
		$this->blog_id = $blog_id;
	}

	/**
	 * Total number of posts DearDoc reports for this blog.
	 *
	 * @return int|WP_Error
	 */
	public function get_count() {
		$data = $this->request( 1, 1 );
		return is_wp_error( $data ) ? $data : (int) $data['count'];
	}

	/**
	 * Every post, across all pages.
	 *
	 * @return array{count:int,posts:array}|WP_Error
	 */
	public function get_all_posts() {
		$posts = array();
		$count = 0;
		$page  = 1;
		$total = 1;

		do {
			$data = $this->request( $page, self::PAGE_SIZE );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$count = (int) $data['count'];
			$total = max( 1, (int) ( $data['total_pages'] ?? 1 ) );
			$posts = array_merge( $posts, $data['posts'] );
			++$page;
		} while ( $page <= $total && $page <= self::MAX_PAGES );

		return array(
			'count' => $count,
			'posts' => $posts,
		);
	}

	/**
	 * @return array|WP_Error The response's `data` object.
	 */
	private function request( int $page, int $page_size ) {
		$url = add_query_arg(
			array(
				'page_size' => $page_size,
				'page'      => $page,
			),
			self::BASE . rawurlencode( $this->blog_id ) . '/posts'
		);

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'DearDoc-Sync/' . DDS_VERSION . ' (WordPress)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'dds_http', sprintf( __( 'The DearDoc API returned HTTP %d.', 'deardoc-sync' ), $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['data']['count'] ) || ! isset( $body['data']['posts'] ) || ! is_array( $body['data']['posts'] ) ) {
			return new WP_Error( 'dds_shape', __( 'The DearDoc API returned an unexpected response.', 'deardoc-sync' ) );
		}

		return $body['data'];
	}
}
