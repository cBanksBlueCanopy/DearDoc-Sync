<?php
/**
 * Sync logic: decide what is new, import it, and link existing posts.
 *
 * Posts are matched by DearDoc's post `id` (stored in post meta), never by
 * slug or by count alone: DearDoc reuses slugs, and counts can't tell which
 * posts are new.
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_Importer {

	const META_ID        = '_deardoc_id';
	const META_UPDATED   = '_deardoc_updated_at';
	const META_SRC       = '_deardoc_source_hash';
	const OPT_LAST_COUNT = 'dds_last_count';
	const OPT_LAST_CHECK = 'dds_last_check';
	const OPT_IGNORED    = 'dds_ignored_ids';
	const OPT_FAILURES   = 'dds_failures';
	const LOCK           = 'dds_sync_lock';
	const ALERT_THROTTLE = 'dds_alert_sent';
	const MAX_ATTEMPTS   = 3;

	/**
	 * Run a sync.
	 *
	 * @param bool $force   Skip the quick "has the count changed?" check.
	 * @param bool $dry_run Report what would be imported without changing anything.
	 * @return array{status:string,message:string,items:string[]}
	 */
	public static function run( bool $force = false, bool $dry_run = false ): array {
		$settings = DDS_Settings::get();

		if ( '' === $settings['blog_id'] ) {
			return self::result( 'error', __( 'Set a DearDoc Blog ID first.', 'deardoc-sync' ) );
		}
		if ( ! $dry_run && ! $settings['author_id'] ) {
			return self::result( 'error', __( 'Choose a post author first.', 'deardoc-sync' ) );
		}

		if ( ! $dry_run ) {
			if ( get_transient( self::LOCK ) ) {
				return self::result( 'warning', __( 'A sync is already running. Try again in a few minutes.', 'deardoc-sync' ) );
			}
			set_transient( self::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}

		try {
			return self::sync( $settings, $force, $dry_run );
		} finally {
			if ( ! $dry_run ) {
				delete_transient( self::LOCK );
			}
		}
	}

	private static function sync( array $settings, bool $force, bool $dry_run ): array {
		$started = microtime( true );
		$api     = new DDS_API( $settings['blog_id'] );
		$count = $api->get_count();
		update_option( self::OPT_LAST_CHECK, time(), false );

		if ( is_wp_error( $count ) ) {
			return self::fail( $count->get_error_message(), $dry_run );
		}

		// Quick check: only fetch everything when DearDoc's total has grown.
		$last = (int) get_option( self::OPT_LAST_COUNT, 0 );
		if ( ! $force && ! $dry_run && $count <= $last ) {
			/* translators: %d: number of posts in DearDoc. */
			return self::result( 'ok', sprintf( __( 'No new posts. DearDoc total: %d.', 'deardoc-sync' ), $count ) );
		}

		$data = $api->get_all_posts();
		if ( is_wp_error( $data ) ) {
			return self::fail( $data->get_error_message(), $dry_run );
		}

		$queue = self::pending_posts( $data['posts'], $settings );

		if ( $dry_run ) {
			$items = array();
			foreach ( $queue as $post ) {
				$items[] = gmdate( 'Y-m-d', $post['_ts'] ) . ' — ' . self::clean_title( $post['title'] ?? '' );
			}
			return self::result(
				'ok',
				/* translators: %d: number of posts. */
				sprintf( _n( '%d post would be imported.', '%d posts would be imported.', count( $queue ), 'deardoc-sync' ), count( $queue ) ),
				$items
			);
		}

		// Small batches with a pause between posts keep each run light:
		// every post means image downloads, resizing and a cache purge.
		$limit = max( 1, (int) apply_filters( 'dds_max_posts_per_run', 3 ) );
		$pause = max( 0, (int) apply_filters( 'dds_pause_seconds', 2 ) );
		$batch = array_slice( $queue, 0, $limit, true );

		$imported = array();
		$errors   = array();
		$skipped  = 0;
		$i        = 0;
		foreach ( $batch as $post ) {
			if ( $i++ > 0 && $pause ) {
				sleep( $pause );
			}
			$title  = self::clean_title( $post['title'] ?? $post['id'] );
			$result = self::import_post( $post, $settings );
			if ( is_wp_error( $result ) ) {
				if ( self::record_failure( $post['id'], $title, $result->get_error_message() ) ) {
					++$skipped;
				} else {
					$errors[] = $title . ': ' . $result->get_error_message();
				}
			} else {
				self::clear_failure( $post['id'] );
				$imported[] = (string) get_post_field( 'post_title', $result, 'raw' );
			}
		}

		$remaining = count( $queue ) - count( $batch );

		// Save the new total only once everything is in, so a failed or
		// partial run is picked up again on the next run.
		if ( ! $errors && 0 === $remaining ) {
			update_option( self::OPT_LAST_COUNT, $count, false );
		}

		/* translators: 1: posts imported, 2: posts found. */
		$message = sprintf( __( 'Imported %1$d of %2$d new posts.', 'deardoc-sync' ), count( $imported ), count( $queue ) );
		if ( $remaining > 0 ) {
			/* translators: %d: posts left for the next run. */
			$message .= ' ' . sprintf( __( '%d more will be imported on the next run.', 'deardoc-sync' ), $remaining );
		}
		if ( $errors ) {
			/* translators: 1: number of failed posts, 2: attempts allowed. */
			$message .= ' ' . sprintf( __( '%1$d failed and will be retried (up to %2$d attempts).', 'deardoc-sync' ), count( $errors ), self::MAX_ATTEMPTS );
		}
		if ( $skipped ) {
			/* translators: %d: number of posts skipped permanently. */
			$message .= ' ' . sprintf( __( '%d skipped permanently after repeated failures.', 'deardoc-sync' ), $skipped );
		}
		/* translators: %s: run time in seconds. */
		$message .= ' ' . sprintf( __( 'Run time: %ss.', 'deardoc-sync' ), number_format_i18n( microtime( true ) - $started, 1 ) );

		if ( $imported || $errors || $skipped ) {
			DDS_Logger::add( $errors ? 'error' : 'info', $message . ( $imported ? ' ' . implode( '; ', $imported ) : '' ) );
			foreach ( $errors as $error ) {
				DDS_Logger::add( 'error', $error );
			}
		}
		if ( $errors ) {
			self::alert( $message . "\n\n" . implode( "\n", $errors ) );
		}

		$status = $errors ? ( $imported ? 'warning' : 'error' ) : 'ok';
		return self::result( $status, $message, array_merge( $imported, $errors ) );
	}

	/**
	 * New, published DearDoc posts after the cutoff, oldest first.
	 */
	private static function pending_posts( array $posts, array $settings ): array {
		$known  = self::known_ids();
		$cutoff = $settings['cutoff_date'] ? strtotime( $settings['cutoff_date'] . ' 23:59:59 UTC' ) : 0;
		$queue  = array();

		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$id = self::valid_id( $post['id'] ?? '' );
			if ( '' === $id || isset( $known[ $id ] ) || isset( $queue[ $id ] ) ) {
				continue;
			}
			if ( 'published' !== ( $post['status'] ?? '' ) ) {
				continue;
			}
			$ts = strtotime( (string) ( $post['published_at'] ?? '' ) );
			if ( ! $ts || $ts > time() + HOUR_IN_SECONDS ) {
				continue; // Unparseable or scheduled for later: pick it up on a later run.
			}
			if ( $cutoff && $ts <= $cutoff ) {
				continue;
			}
			$post['id']   = $id;
			$post['_ts']  = $ts;
			$queue[ $id ] = $post;
		}

		uasort(
			$queue,
			static function ( $a, $b ) {
				return $a['_ts'] <=> $b['_ts'];
			}
		);

		return $queue;
	}

	/**
	 * Create one WordPress post from a DearDoc post.
	 *
	 * @return int|WP_Error Post ID.
	 */
	private static function import_post( array $post, array $settings ) {
		$title = self::clean_title( $post['title'] ?? '' );
		if ( '' === $title ) {
			return new WP_Error( 'dds_title', __( 'The post has no title.', 'deardoc-sync' ) );
		}

		$body = self::clean_body( (string) ( $post['content'] ?? '' ), (bool) $settings['demote_h1'] );
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			return new WP_Error( 'dds_body', __( 'The post has no content.', 'deardoc-sync' ) );
		}

		$slug = sanitize_title( (string) ( $post['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}
		$date_gmt = gmdate( 'Y-m-d H:i:s', $post['_ts'] );

		$meta = array(
			self::META_ID      => $post['id'],
			self::META_UPDATED => sanitize_text_field( (string) ( $post['updated_at'] ?? '' ) ),
		) + self::seo_meta( $post );

		// Created as a draft first so images can be attached to it.
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $title,
					'post_name'     => $slug,
					'post_content'  => $body,
					'post_excerpt'  => sanitize_textarea_field( html_entity_decode( (string) ( $post['summary'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ),
					'post_author'   => (int) $settings['author_id'],
					'post_date_gmt' => $date_gmt,
					'post_date'     => get_date_from_gmt( $date_gmt ),
					'post_category' => $settings['category_ids'] ? $settings['category_ids'] : array( (int) get_option( 'default_category' ) ),
					'meta_input'    => $meta,
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $settings['import_images'] ) {
			$body = self::localize_images( $body, $post_id, $slug );

			$featured = (string) ( $post['featured_image'] ?? '' );
			if ( '' !== $featured ) {
				$alt = self::clean_title( $post['featured_image_alt'] ?? '' );
				$att = self::sideload_image( $featured, $post_id, '' !== $alt ? $alt : $title, $slug );
				if ( is_wp_error( $att ) ) {
					/* translators: 1: post title, 2: error message. */
					DDS_Logger::add( 'warning', sprintf( __( 'Featured image for "%1$s" was not imported: %2$s', 'deardoc-sync' ), $title, $att->get_error_message() ) );
				} else {
					set_post_thumbnail( $post_id, $att );
				}
			}
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $body,
					'post_status'  => $settings['post_status'],
				)
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			// Remove the half-made post without marking its DearDoc id as deliberately deleted.
			delete_post_meta( $post_id, self::META_ID );
			wp_delete_post( $post_id, true );
			return $updated;
		}

		return $post_id;
	}

	/**
	 * Rank Math fields from DearDoc's SEO data.
	 */
	private static function seo_meta( array $post ): array {
		$meta = array();

		$title = self::clean_title( $post['seo_title'] ?? '' );
		if ( '' !== $title ) {
			$meta['rank_math_title'] = $title;
		}

		$description = self::clean_title( $post['seo_description'] ?? '' );
		if ( '' !== $description ) {
			$meta['rank_math_description'] = $description;
		}

		$secondary = $post['secondary_keywords'] ?? array();
		$keywords  = array_merge(
			array( $post['keyword'] ?? '' ),
			is_array( $secondary ) ? $secondary : explode( ',', (string) $secondary )
		);
		$clean     = array();
		foreach ( $keywords as $keyword ) {
			$keyword = sanitize_text_field( is_scalar( $keyword ) ? (string) $keyword : '' );
			if ( '' !== $keyword && ! in_array( $keyword, $clean, true ) ) {
				$clean[] = $keyword;
			}
		}
		if ( $clean ) {
			$meta['rank_math_focus_keyword'] = implode( ',', array_slice( $clean, 0, 5 ) );
		}

		return $meta;
	}

	/**
	 * Keep only the article body. DearDoc sends a full HTML document with
	 * its own <head> and <style>, which must not end up in the post.
	 */
	private static function clean_body( string $html, bool $demote_h1 ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return wp_kses_post( $html );
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_HTML_NODEFDTD );

		foreach ( array( 'head', 'script', 'style', 'noscript', 'iframe', 'form', 'link', 'meta' ) as $tag ) {
			$nodes = $doc->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				$node->parentNode->removeChild( $node );
			}
		}

		// The theme already prints the post title as the page's H1.
		if ( $demote_h1 ) {
			$headings = $doc->getElementsByTagName( 'h1' );
			for ( $i = $headings->length - 1; $i >= 0; $i-- ) {
				$h1 = $headings->item( $i );
				$h2 = $doc->createElement( 'h2' );
				while ( $h1->firstChild ) {
					$h2->appendChild( $h1->firstChild );
				}
				$h1->parentNode->replaceChild( $h2, $h1 );
			}
		}

		$out  = '';
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( $body ) {
			foreach ( $body->childNodes as $child ) {
				$out .= $doc->saveHTML( $child );
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return wp_kses_post( trim( $out ) );
	}

	/**
	 * Copy inline images into the Media Library and point the HTML at them.
	 * Images that fail keep their original URL.
	 */
	private static function localize_images( string $html, int $post_id, string $slug ): string {
		$n = 0;
		return (string) preg_replace_callback(
			'/(<img\b[^>]*?\bsrc=)(["\'])([^"\']+)\2/i',
			static function ( $m ) use ( $post_id, $slug, &$n ) {
				$url = html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' );
				++$n;
				$att = self::sideload_image( $url, $post_id, '', $slug . '-' . $n );
				if ( is_wp_error( $att ) ) {
					/* translators: 1: image URL, 2: error message. */
					DDS_Logger::add( 'warning', sprintf( __( 'Inline image %1$s was not imported: %2$s', 'deardoc-sync' ), $url, $att->get_error_message() ) );
					return $m[0];
				}
				$local = wp_get_attachment_url( $att );
				return $local ? $m[1] . $m[2] . esc_url( $local ) . $m[2] : $m[0];
			},
			$html
		);
	}

	/**
	 * Download an image from an allowed host into the Media Library.
	 * The same source URL is only ever downloaded once.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload_image( string $url, int $post_id, string $alt, string $name ) {
		$url   = esc_url_raw( $url, array( 'https' ) );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts = (array) apply_filters( 'dds_image_hosts', array( 'firebasestorage.googleapis.com', 'storage.googleapis.com' ) );

		if ( '' === $url || ! in_array( $host, $hosts, true ) ) {
			/* translators: %s: image host or URL. */
			return new WP_Error( 'dds_image_host', sprintf( __( 'Images from %s are not allowed.', 'deardoc-sync' ), '' !== $host ? $host : $url ) );
		}

		$hash     = md5( $url );
		$existing = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'meta_key'      => self::META_SRC, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'    => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'        => 'ids',
				'numberposts'   => 1,
				'no_found_rows' => true,
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$types = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		$mime  = wp_get_image_mime( $tmp );
		if ( ! $mime || ! isset( $types[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'dds_image_type', __( 'The file is not a supported image.', 'deardoc-sync' ) );
		}

		// DearDoc serves large PNGs (often 1–3 MB). Convert them to a
		// right-sized WebP once, before WordPress makes its resized copies,
		// so every later step works on a small file.
		$converted = self::optimize_image( $tmp, $mime );
		if ( $converted ) {
			wp_delete_file( $tmp );
			$tmp  = $converted;
			$mime = 'image/webp';
		}

		$file = array(
			'name'     => sanitize_file_name( substr( $name, 0, 80 ) . '.' . $types[ $mime ] ),
			'tmp_name' => $tmp,
		);

		$att = media_handle_sideload( $file, $post_id, '' !== $alt ? $alt : null );
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $tmp );
			return $att;
		}

		update_post_meta( $att, self::META_SRC, $hash );
		if ( '' !== $alt ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $alt );
		}

		return (int) $att;
	}

	/**
	 * Resize a PNG/JPEG to at most `dds_max_image_width` pixels wide and save
	 * it as WebP. Returns the new file path, or '' to keep the original
	 * (unsupported server, already small, or conversion failed).
	 */
	private static function optimize_image( string $file, string $mime ): string {
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			return '';
		}
		if ( ! (bool) apply_filters( 'dds_convert_to_webp', true ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return '';
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$max  = max( 320, (int) apply_filters( 'dds_max_image_width', 1600 ) );
		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > $max ) {
			$editor->resize( $max, null, false );
		}
		$editor->set_quality( (int) apply_filters( 'dds_webp_quality', 82 ) );

		$saved = $editor->save( $file . '.webp', 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return '';
		}

		// Keep the original if the WebP somehow came out larger.
		if ( filesize( $saved['path'] ) >= filesize( $file ) ) {
			wp_delete_file( $saved['path'] );
			return '';
		}

		return $saved['path'];
	}

	/**
	 * One-time setup: tag posts that were imported before this plugin with
	 * their DearDoc id, so the sync never imports them again.
	 *
	 * Matches by slug, then by title, and only to a site post published
	 * within two days of the DearDoc post (DearDoc reuses slugs).
	 */
	public static function link_existing( bool $dry_run ): array {
		$settings = DDS_Settings::get();
		if ( '' === $settings['blog_id'] ) {
			return self::result( 'error', __( 'Set a DearDoc Blog ID first.', 'deardoc-sync' ) );
		}

		$data = ( new DDS_API( $settings['blog_id'] ) )->get_all_posts();
		if ( is_wp_error( $data ) ) {
			return self::result( 'error', $data->get_error_message() );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_name, p.post_title, p.post_date
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'post'
				AND p.post_status IN ('publish','future','draft','pending','private','trash')
				AND m.meta_id IS NULL",
				self::META_ID
			)
		);

		$by_slug  = array();
		$by_title = array();
		foreach ( (array) $rows as $row ) {
			$row->ts                                       = (int) strtotime( $row->post_date . ' UTC' );
			$by_slug[ $row->post_name ][]                  = $row;
			$by_title[ self::norm( $row->post_title ) ][] = $row;
		}

		$api_posts = array();
		foreach ( $data['posts'] as $post ) {
			$id = is_array( $post ) ? self::valid_id( $post['id'] ?? '' ) : '';
			$ts = $id ? strtotime( (string) ( $post['published_at'] ?? '' ) ) : false;
			if ( $id && $ts ) {
				$post['id']  = $id;
				$post['_ts'] = $ts;
				$api_posts[] = $post;
			}
		}
		usort(
			$api_posts,
			static function ( $a, $b ) {
				return $a['_ts'] <=> $b['_ts'];
			}
		);

		$known     = self::known_ids();
		$used      = array();
		$linked    = 0;
		$unmatched = array();

		foreach ( $api_posts as $post ) {
			if ( isset( $known[ $post['id'] ] ) ) {
				continue;
			}
			$match = self::closest( $by_slug[ sanitize_title( (string) ( $post['slug'] ?? '' ) ) ] ?? array(), $post['_ts'], $used );
			if ( ! $match ) {
				$match = self::closest( $by_title[ self::norm( (string) ( $post['title'] ?? '' ) ) ] ?? array(), $post['_ts'], $used );
			}
			if ( ! $match ) {
				$unmatched[] = gmdate( 'Y-m-d', $post['_ts'] ) . ' — ' . self::clean_title( $post['title'] ?? '' );
				continue;
			}
			$used[ $match->ID ] = true;
			++$linked;
			if ( ! $dry_run ) {
				add_post_meta( $match->ID, self::META_ID, $post['id'], true );
			}
		}

		$message = sprintf(
			/* translators: 1: posts linked, 2: posts without a match. */
			$dry_run
				? __( '%1$d existing posts would be linked to DearDoc. %2$d DearDoc posts have no match on the site; any published after the cutoff date will be imported by the sync.', 'deardoc-sync' )
				: __( '%1$d existing posts were linked to DearDoc. %2$d DearDoc posts have no match on the site; any published after the cutoff date will be imported by the sync.', 'deardoc-sync' ),
			$linked,
			count( $unmatched )
		);

		if ( ! $dry_run ) {
			DDS_Logger::add( 'info', $message );
		}

		return self::result( 'ok', $message, array_slice( $unmatched, 0, 100 ) );
	}

	/**
	 * When a synced post is permanently deleted, remember its DearDoc id so
	 * the sync doesn't bring it back.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function remember_deleted( $post_id ): void {
		$id = get_post_meta( (int) $post_id, self::META_ID, true );
		if ( $id ) {
			self::ignore_id( (string) $id );
		}
	}

	/**
	 * Count a failed import. After MAX_ATTEMPTS failures the post is skipped
	 * for good, so one broken DearDoc post can't make every run fail.
	 *
	 * @return bool True when the post is now skipped permanently.
	 */
	private static function record_failure( string $id, string $title, string $error ): bool {
		$failures        = (array) get_option( self::OPT_FAILURES, array() );
		$failures[ $id ] = (int) ( $failures[ $id ] ?? 0 ) + 1;

		if ( $failures[ $id ] < self::MAX_ATTEMPTS ) {
			update_option( self::OPT_FAILURES, $failures, false );
			return false;
		}

		unset( $failures[ $id ] );
		update_option( self::OPT_FAILURES, $failures, false );
		self::ignore_id( $id );
		/* translators: 1: post title, 2: attempts, 3: error message. */
		DDS_Logger::add( 'warning', sprintf( __( 'Skipping "%1$s" permanently after %2$d failed attempts. Last error: %3$s', 'deardoc-sync' ), $title, self::MAX_ATTEMPTS, $error ) );
		return true;
	}

	private static function clear_failure( string $id ): void {
		$failures = (array) get_option( self::OPT_FAILURES, array() );
		if ( isset( $failures[ $id ] ) ) {
			unset( $failures[ $id ] );
			update_option( self::OPT_FAILURES, $failures, false );
		}
	}

	private static function ignore_id( string $id ): void {
		$ignored   = (array) get_option( self::OPT_IGNORED, array() );
		$ignored[] = $id;
		update_option( self::OPT_IGNORED, array_values( array_unique( $ignored ) ), false );
	}

	/**
	 * Clear the skipped list so permanently skipped posts are tried again.
	 * Posts that were permanently deleted are also on this list, so they
	 * will be imported again. Trashed posts keep their DearDoc id and stay
	 * skipped.
	 */
	public static function reset_skipped(): array {
		$count = count( (array) get_option( self::OPT_IGNORED, array() ) );
		delete_option( self::OPT_IGNORED );
		delete_option( self::OPT_FAILURES );
		update_option( self::OPT_LAST_COUNT, 0, false );
		/* translators: %d: number of posts. */
		$message = sprintf( __( 'Cleared %d skipped DearDoc posts. They will be tried again on the next sync.', 'deardoc-sync' ), $count );
		DDS_Logger::add( 'info', $message );
		return self::result( 'ok', $message );
	}

	public static function skipped_count(): int {
		return count( (array) get_option( self::OPT_IGNORED, array() ) );
	}

	public static function linked_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_ID ) );
	}

	private static function known_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_ID ) );
		$ids = array_merge( (array) $ids, (array) get_option( self::OPT_IGNORED, array() ) );
		return array_fill_keys( array_map( 'strval', $ids ), true );
	}

	private static function closest( array $candidates, int $ts, array $used ) {
		$best      = null;
		$best_diff = PHP_INT_MAX;
		foreach ( $candidates as $candidate ) {
			if ( isset( $used[ $candidate->ID ] ) ) {
				continue;
			}
			$diff = abs( $candidate->ts - $ts );
			if ( $diff <= 2 * DAY_IN_SECONDS && $diff < $best_diff ) {
				$best      = $candidate;
				$best_diff = $diff;
			}
		}
		return $best;
	}

	private static function norm( string $title ): string {
		$title = strtolower( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
		return (string) preg_replace( '/[^a-z0-9]+/', '', $title );
	}

	/**
	 * @param mixed $value Raw text from the API.
	 */
	private static function clean_title( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) : '';
	}

	/**
	 * @param mixed $id Raw id from the API.
	 */
	private static function valid_id( $id ): string {
		$id = strtolower( trim( is_scalar( $id ) ? (string) $id : '' ) );
		return preg_match( DDS_Settings::UUID_PATTERN, $id ) ? $id : '';
	}

	private static function fail( string $message, bool $dry_run ): array {
		if ( ! $dry_run ) {
			DDS_Logger::add( 'error', $message );
			self::alert( $message );
		}
		return self::result( 'error', $message );
	}

	/**
	 * Email the alert address, at most once every 12 hours.
	 */
	private static function alert( string $message ): void {
		if ( get_transient( self::ALERT_THROTTLE ) ) {
			return;
		}
		set_transient( self::ALERT_THROTTLE, 1, 12 * HOUR_IN_SECONDS );

		$to = DDS_Settings::get( 'alert_email' );
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] DearDoc Sync needs attention', 'deardoc-sync' ), $site ),
			$message . "\n\n" . admin_url( 'options-general.php?page=deardoc-sync' )
		);
	}

	private static function result( string $status, string $message, array $items = array() ): array {
		return array(
			'status'  => $status,
			'message' => $message,
			'items'   => $items,
		);
	}
}
