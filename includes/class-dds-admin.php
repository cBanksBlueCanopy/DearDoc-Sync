<?php
/**
 * Settings screen (Settings → DearDoc Sync) and its action buttons.
 *
 * @package DearDocSync
 */

defined( 'ABSPATH' ) || exit;

final class DDS_Admin {

	const SLUG   = 'deardoc-sync';
	const GROUP  = 'dds_settings_group';
	const ACTION = 'dds_action';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DDS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'DearDoc Sync', 'deardoc-sync' ),
			__( 'DearDoc Sync', 'deardoc-sync' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			DDS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'DDS_Settings', 'sanitize' ),
				'default'           => DDS_Settings::defaults(),
			)
		);
	}

	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'deardoc-sync' ) )
		);
		return $links;
	}

	/**
	 * Handle the action buttons (preview, sync, link, clear log).
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'deardoc-sync' ), 403 );
		}
		check_admin_referer( self::ACTION );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$do = isset( $_POST['dds_do'] ) ? sanitize_key( wp_unslash( $_POST['dds_do'] ) ) : '';

		switch ( $do ) {
			case 'preview':
				$result = DDS_Importer::run( true, true );
				break;
			case 'sync':
				$result = DDS_Importer::run( true, false );
				break;
			case 'link_preview':
				$result = DDS_Importer::link_existing( true );
				break;
			case 'link':
				$result = DDS_Importer::link_existing( false );
				break;
			case 'reset_skipped':
				$result = DDS_Importer::reset_skipped();
				break;
			case 'clear_log':
				DDS_Logger::clear();
				$result = array(
					'status'  => 'ok',
					'message' => __( 'Log cleared.', 'deardoc-sync' ),
					'items'   => array(),
				);
				break;
			default:
				$result = array(
					'status'  => 'error',
					'message' => __( 'Unknown action.', 'deardoc-sync' ),
					'items'   => array(),
				);
		}

		set_transient( 'dds_notice_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s          = DDS_Settings::get();
		$name       = DDS_Settings::OPTION;
		$next       = wp_next_scheduled( DDS_Cron::HOOK );
		$last_check = (int) get_option( DDS_Importer::OPT_LAST_CHECK, 0 );
		$last_count = (int) get_option( DDS_Importer::OPT_LAST_COUNT, 0 );
		$fmt        = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap dds-wrap">
			<h1><?php esc_html_e( 'DearDoc Sync', 'deardoc-sync' ); ?></h1>

			<?php self::render_notice(); ?>

			<h2><?php esc_html_e( 'Status', 'deardoc-sync' ); ?></h2>
			<table class="widefat striped dds-status" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic sync', 'deardoc-sync' ); ?></th>
						<td><?php echo $s['enabled'] ? esc_html__( 'On', 'deardoc-sync' ) : esc_html__( 'Off', 'deardoc-sync' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next run', 'deardoc-sync' ); ?></th>
						<td><?php echo $next ? esc_html( wp_date( $fmt, $next ) ) : '—'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last check', 'deardoc-sync' ); ?></th>
						<td><?php echo $last_check ? esc_html( wp_date( $fmt, $last_check ) ) : '—'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'DearDoc total at last complete sync', 'deardoc-sync' ); ?></th>
						<td><?php echo $last_count ? esc_html( number_format_i18n( $last_count ) ) : '—'; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site posts linked to DearDoc', 'deardoc-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( DDS_Importer::linked_count() ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'DearDoc posts skipped', 'deardoc-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( DDS_Importer::skipped_count() ) ); ?> <span class="description"><?php esc_html_e( '(failed 3 times, or permanently deleted on the site)', 'deardoc-sync' ); ?></span></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Actions', 'deardoc-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dds-actions">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p class="description"><?php esc_html_e( 'First-time setup: preview, then link existing posts, so posts already on the site are never imported twice.', 'deardoc-sync' ); ?></p>
				<p>
					<button type="submit" name="dds_do" value="link_preview" class="button"><?php esc_html_e( '1. Preview linking existing posts', 'deardoc-sync' ); ?></button>
					<button type="submit" name="dds_do" value="link" class="button"><?php esc_html_e( '2. Link existing posts', 'deardoc-sync' ); ?></button>
				</p>
				<p>
					<button type="submit" name="dds_do" value="preview" class="button"><?php esc_html_e( 'Preview sync', 'deardoc-sync' ); ?></button>
					<button type="submit" name="dds_do" value="sync" class="button button-primary"><?php esc_html_e( 'Sync now', 'deardoc-sync' ); ?></button>
				</p>
				<?php if ( DDS_Importer::skipped_count() ) : ?>
					<p>
						<button type="submit" name="dds_do" value="reset_skipped" class="button-link" onclick="return window.confirm('<?php echo esc_js( __( 'Try skipped posts again? Posts that were permanently deleted on the site will be imported again.', 'deardoc-sync' ) ); ?>');"><?php esc_html_e( 'Retry skipped posts', 'deardoc-sync' ); ?></button>
					</p>
				<?php endif; ?>
			</form>

			<h2><?php esc_html_e( 'Settings', 'deardoc-sync' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dds-blog-id"><?php esc_html_e( 'DearDoc Blog ID', 'deardoc-sync' ); ?></label></th>
						<td>
							<input type="text" id="dds-blog-id" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[blog_id]" value="<?php echo esc_attr( $s['blog_id'] ); ?>" spellcheck="false" autocomplete="off" aria-describedby="dds-blog-id-desc">
							<p class="description" id="dds-blog-id-desc"><?php esc_html_e( 'The UUID from DearDoc, for example 245542db-d5ed-42e8-816b-3cda26bb3073.', 'deardoc-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic sync', 'deardoc-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Check DearDoc for new posts on a schedule', 'deardoc-sync' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dds-interval"><?php esc_html_e( 'How often', 'deardoc-sync' ); ?></label></th>
						<td>
							<select id="dds-interval" name="<?php echo esc_attr( $name ); ?>[interval]">
								<?php foreach ( DDS_Settings::intervals() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dds-status"><?php esc_html_e( 'New posts are', 'deardoc-sync' ); ?></label></th>
						<td>
							<select id="dds-status" name="<?php echo esc_attr( $name ); ?>[post_status]">
								<?php foreach ( DDS_Settings::statuses() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['post_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dds-author"><?php esc_html_e( 'Author', 'deardoc-sync' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'capability'        => array( 'publish_posts' ),
									'name'              => $name . '[author_id]',
									'id'                => 'dds-author',
									'selected'          => (int) $s['author_id'],
									'show_option_none'  => __( '— Select —', 'deardoc-sync' ),
									'option_none_value' => 0,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Shown publicly as the byline and in Google structured data. Use a client-branded author.', 'deardoc-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Categories', 'deardoc-sync' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Categories', 'deardoc-sync' ); ?></legend>
								<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) : ?>
									<label class="dds-cat"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[category_ids][]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( (int) $cat->term_id, $s['category_ids'], true ) ); ?>> <?php echo esc_html( $cat->name ); ?></label><br>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'None selected uses the default category.', 'deardoc-sync' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dds-cutoff"><?php esc_html_e( 'Only import posts published after', 'deardoc-sync' ); ?></label></th>
						<td>
							<input type="date" id="dds-cutoff" name="<?php echo esc_attr( $name ); ?>[cutoff_date]" value="<?php echo esc_attr( $s['cutoff_date'] ); ?>" aria-describedby="dds-cutoff-desc">
							<p class="description" id="dds-cutoff-desc"><?php esc_html_e( 'Older DearDoc posts are never imported. Set this to the date of the last post imported by hand.', 'deardoc-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content', 'deardoc-sync' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Content', 'deardoc-sync' ); ?></legend>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[import_images]" value="1" <?php checked( $s['import_images'] ); ?>> <?php esc_html_e( 'Copy images into the Media Library and set the featured image', 'deardoc-sync' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[demote_h1]" value="1" <?php checked( $s['demote_h1'] ); ?>> <?php esc_html_e( 'Change the H1 inside the article to H2 (the theme already shows the title as H1)', 'deardoc-sync' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dds-email"><?php esc_html_e( 'Alert email', 'deardoc-sync' ); ?></label></th>
						<td>
							<input type="email" id="dds-email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" aria-describedby="dds-email-desc">
							<p class="description" id="dds-email-desc"><?php esc_html_e( 'Where to send failure alerts (at most one every 12 hours). Empty uses the site admin email.', 'deardoc-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Activity log', 'deardoc-sync' ); ?></h2>
			<?php self::render_log( $fmt ); ?>
		</div>
		<?php
	}

	private static function render_notice(): void {
		$key    = 'dds_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );

		$classes = array(
			'ok'      => 'notice-success',
			'warning' => 'notice-warning',
			'error'   => 'notice-error',
		);
		$class   = $classes[ $notice['status'] ?? '' ] ?? 'notice-info';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible" role="status">
			<p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p>
			<?php if ( ! empty( $notice['items'] ) ) : ?>
				<ul class="ul-disc">
					<?php foreach ( (array) $notice['items'] as $item ) : ?>
						<li><?php echo esc_html( (string) $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_log( string $fmt ): void {
		$log = DDS_Logger::all();
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'Nothing logged yet. Checks that find no new posts are not logged.', 'deardoc-sync' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'deardoc-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Level', 'deardoc-sync' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'deardoc-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( $fmt, (int) ( $entry['t'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( ucfirst( (string) ( $entry['l'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['m'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<p><button type="submit" name="dds_do" value="clear_log" class="button-link"><?php esc_html_e( 'Clear log', 'deardoc-sync' ); ?></button></p>
		</form>
		<?php
	}

	private static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}
}
