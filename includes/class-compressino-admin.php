<?php
/** Administrator screen and notices. @package Compressino */
defined( 'ABSPATH' ) || exit;

final class Compressino_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_compressino_save', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_compressino_preview', array( __CLASS__, 'preview' ) );
		add_action( 'admin_notices', array( __CLASS__, 'support_notice' ) );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'browser_media' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'browser_block_editor' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( COMPRESSINO_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'wp_ajax_compressino_images', array( __CLASS__, 'ajax_images' ) );
		add_action( 'wp_ajax_compressino_compress', array( __CLASS__, 'ajax_compress' ) );
		add_action( 'wp_ajax_compressino_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_compressino_estimate', array( __CLASS__, 'ajax_estimate' ) );
		add_action( 'wp_ajax_compressino_delete_originals', array( __CLASS__, 'ajax_delete_originals' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'media_field' ), 20, 2 );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'compressino' ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( COMPRESSINO_DIR . 'assets/compressino-sidebar.svg' ) );
		add_menu_page( 'Compressino', 'Compressino', 'manage_options', 'compressino', array( __CLASS__, 'render' ), $icon, 60 );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'compressino' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'post.php' === $hook && 'attachment' === get_post_type() ) {
			self::enqueue_manage();
		}
		if ( 'media-new.php' === $hook ) {
			self::enqueue_browser( 'media-new', array( 'plupload-handlers' ) );
		}
		if ( 'toplevel_page_compressino' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'compressino-admin', COMPRESSINO_URL . 'assets/admin.css', array(), COMPRESSINO_VERSION . '.' . filemtime( COMPRESSINO_DIR . 'assets/admin.css' ) );
		self::register_script();
		wp_enqueue_script( 'compressino' );
		self::enqueue_manage();
	}

	public static function browser_media() {
		self::enqueue_browser( 'media', array( 'wp-plupload' ) );
		self::enqueue_manage();
	}

	public static function browser_block_editor() {
		self::enqueue_browser( 'block-editor', array( 'wp-api-fetch' ) );
	}

	/**
	 * Load browser compression. Runs wherever core loads an uploader: the media window (also on the
	 * front end, for example in a pagebuilder), the block editor and the Add New Media screen.
	 * Developers can call this on their own screens, then use window.compressino.attach( uploader ).
	 *
	 * @param string   $context Where it loads: media, block-editor, media-new, or your own name.
	 * @param string[] $deps    Scripts that must load first.
	 */
	public static function enqueue_browser( $context = 'custom', $deps = array() ) {
		$settings = Compressino::settings();
		/**
		 * Filters whether browser compression loads in a given place.
		 *
		 * @param bool   $enabled True when the setting is on and the user can upload files.
		 * @param string $context media, block-editor, media-new, or the name passed by other code.
		 */
		if ( ! apply_filters( 'compressino_browser_compression', $settings['browser'] && current_user_can( 'upload_files' ), $context ) ) {
			return;
		}
		self::register_script();
		$script = wp_scripts()->registered['compressino'];
		$script->deps = array_values( array_unique( array_merge( $script->deps, (array) $deps ) ) );
		wp_enqueue_script( 'compressino' );
	}

	private static function register_script() {
		if ( wp_script_is( 'compressino', 'registered' ) ) {
			return;
		}
		wp_register_script( 'compressino', COMPRESSINO_URL . 'assets/admin.js', array(), COMPRESSINO_VERSION . '.' . filemtime( COMPRESSINO_DIR . 'assets/admin.js' ), true );
		wp_add_inline_script( 'compressino', 'window.compressinoSettings = ' . wp_json_encode( array( 'quality' => Compressino::settings()['quality'] ) ) . ';', 'before' );
	}

	/** What a quality value means, in plain words. Lowest value of each band first. */
	private static function quality_bands() {
		return array(
			array( 95, __( 'Almost no compression. Files stay large.', 'compressino' ) ),
			array( 85, __( 'Hard to see any difference with the original.', 'compressino' ) ),
			array( 70, __( 'Small loss. Fine detail and small text can look a little soft.', 'compressino' ) ),
			array( 50, __( 'Clear loss. Text and faces can look blurry.', 'compressino' ) ),
			array( 1, __( 'Heavy loss. You will see blocks and smudges.', 'compressino' ) ),
		);
	}

	private static function quality_note( $quality ) {
		foreach ( self::quality_bands() as $band ) {
			if ( $quality >= $band[0] ) {
				return $band[1];
			}
		}
		return '';
	}

	private static function quality_field( $quality ) {
		$preview = array(
			'ids'   => Compressino::preview_candidates(),
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'compressino_preview' ),
			'bands' => self::quality_bands(),
			// Sample photo: Pomeranian by Justin Alvarado, WordPress.org Photo Directory, CC0 (wordpress.org/photos/photo/8866ae956c).
			'sample' => true,
			'labels' => array(
				'sample'  => __( 'Sample photo', 'compressino' ),
				'actual'  => __( 'shown at actual size', 'compressino' ),
				'own'     => __( 'Try one of your own images', 'compressino' ),
				'next'    => __( 'Try another image', 'compressino' ),
				'back'    => __( 'Back to the sample photo', 'compressino' ),
				/* translators: %s: percentage */
				'smaller' => __( '%s%% smaller', 'compressino' ),
			),
		);
		?>
		<div class="cp-quality" data-preview="<?php echo esc_attr( wp_json_encode( $preview ) ); ?>">
			<div class="cp-quality__head">
				<label for="cp-quality"><?php esc_html_e( 'Image quality', 'compressino' ); ?></label>
				<output for="cp-quality" id="cp-quality-value"><?php echo esc_html( $quality ); ?> / 100</output>
			</div>
			<input id="cp-quality" name="quality" type="range" min="1" max="100" step="1" value="<?php echo esc_attr( $quality ); ?>" aria-describedby="cp-quality-note">
			<div class="cp-quality__ends" aria-hidden="true"><span><?php esc_html_e( 'Smaller files', 'compressino' ); ?></span><span><?php esc_html_e( 'Sharper images', 'compressino' ); ?></span></div>
			<p class="cp-quality__note" id="cp-quality-note"><?php echo esc_html( self::quality_note( $quality ) ); ?></p>
			<div class="cp-preview" hidden>
				<figure class="cp-preview__pane"><figcaption><?php esc_html_e( 'Original', 'compressino' ); ?> <strong data-original-size></strong></figcaption><div class="cp-preview__frame"><img data-original alt=""></div></figure>
				<figure class="cp-preview__pane"><figcaption><?php esc_html_e( 'Compressed', 'compressino' ); ?> <strong data-new-size></strong></figcaption><div class="cp-preview__frame"><img data-compressed alt=""></div></figure>
				<div class="cp-preview__bar"><span data-preview-name></span><div class="cp-zoom" role="group" aria-label="<?php esc_attr_e( 'Zoom', 'compressino' ); ?>"><button type="button" data-zoom-out aria-label="<?php esc_attr_e( 'Zoom out', 'compressino' ); ?>">&minus;</button><output data-zoom-value aria-live="polite">50%</output><button type="button" data-zoom-in aria-label="<?php esc_attr_e( 'Zoom in', 'compressino' ); ?>">+</button></div><button type="button" class="cp-text-action" data-preview-next hidden><?php esc_html_e( 'Try another image', 'compressino' ); ?></button></div>
			</div>
			<p class="cp-help" data-preview-empty hidden><?php esc_html_e( 'Upload a PNG or JPG to see a preview of your own image here.', 'compressino' ); ?></p>
		</div>
		<?php
	}

	/** Streams the full-quality original of one image, so the preview can compress it in the browser. */
	public static function preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_ajax_referer( 'compressino_preview' );
		// The sample photo goes through here too, so a host or CDN that re-optimises static images cannot change the original.
		$sample = isset( $_GET['id'] ) && 'sample' === $_GET['id'];
		$path   = $sample ? COMPRESSINO_DIR . 'assets/preview-sample.jpg' : Compressino::original_path( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( ! $path ) {
			wp_die( '', 404 );
		}
		$type = wp_check_filetype( $path );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Compressino-Name: ' . rawurlencode( $sample ? '' : get_the_title( absint( $_GET['id'] ) ) ) );
		readfile( $path );
		exit;
	}

	/** Shown on the plugins screen, the dashboard and the Compressino screen when WebP cannot be written. */
	public static function support_notice() {
		if ( Compressino::supported() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard', 'toplevel_page_compressino' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Compressino:</strong> ' . esc_html__( 'this server cannot create WebP images. Ask your host to enable Imagick or GD with WebP support. Until then, uploads are left as they are.', 'compressino' ) . '</p></div>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change Compressino settings.', 'compressino' ) );
		}
		check_admin_referer( 'compressino_save' );
		$input = array();
		foreach ( array( 'quality', 'browser', 'server', 'keep_originals', 'skip_webp' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		Compressino::save_settings( $input );
		wp_safe_redirect( self::url( array( 'saved' => 1 ) ) );
		exit;
	}

	private static function toggle( $key, $label, $help, $settings ) {
		?>
		<div class="cp-field cp-check">
			<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $settings[ $key ] ); ?>> <?php echo esc_html( $label ); ?></label>
			<p class="cp-help"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab      = isset( $_GET['tab'] ) && 'about' === $_GET['tab'] ? 'about' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = Compressino::settings();
		$stats    = Compressino::stats();
		$engine   = Compressino::engine();
		$icon     = add_query_arg( 'ver', filemtime( COMPRESSINO_DIR . 'assets/compressino-icon.svg' ), COMPRESSINO_URL . 'assets/compressino-icon.svg' );
		?>
		<div class="cp-wrap">
			<header class="cp-header">
				<a class="cp-brand" href="<?php echo esc_url( self::url() ); ?>"><img class="cp-brand__icon" src="<?php echo esc_url( $icon ); ?>" width="32" height="32" alt="" aria-hidden="true"><h1>Compressino</h1></a>
				<?php self::nav( $tab ); ?>
			</header>
			<hr class="wp-header-end">
			<main class="cp-content">
				<?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="cp-notice" role="status"><?php esc_html_e( 'Settings saved.', 'compressino' ); ?></div><?php endif; ?>
				<?php if ( 'about' === $tab ) : ?>
				<?php self::about(); ?>
				<?php else : ?>
				<div class="cp-layout">
					<form class="cp-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="compressino_save">
						<?php wp_nonce_field( 'compressino_save' ); ?>
						<h2><?php esc_html_e( 'Settings', 'compressino' ); ?></h2>
						<?php self::quality_field( $settings['quality'] ); ?>
						<?php
						self::toggle( 'browser', __( 'Convert at upload in the browser', 'compressino' ), __( 'Images are compressed on your computer before they are uploaded.', 'compressino' ), $settings );
						self::toggle( 'server', __( 'Server safety net', 'compressino' ), __( 'PNG and JPG files that still arrive unoptimised are converted on the server, whatever route they take.', 'compressino' ), $settings );
						self::toggle( 'keep_originals', __( 'Keep originals', 'compressino' ), __( 'Each original is copied to wp-content/compressino-backups, so you can restore it.', 'compressino' ), $settings );
						self::toggle( 'skip_webp', __( 'Leave WebP uploads as they are', 'compressino' ), __( 'Images that are already WebP are not compressed a second time.', 'compressino' ), $settings );
						?>
						<button class="cp-button" type="submit"><?php esc_html_e( 'Save settings', 'compressino' ); ?></button>
					</form>
					<aside class="cp-side">
						<?php self::info_panel(); ?>
						<section class="cp-panel">
							<h2><?php esc_html_e( 'Status', 'compressino' ); ?></h2>
							<dl class="cp-stats">
								<div><dt><?php esc_html_e( 'Image engine', 'compressino' ); ?></dt><dd><?php echo Compressino::supported() ? esc_html( sprintf( /* translators: %s: Imagick or GD */ __( '%s with WebP', 'compressino' ), $engine ) ) : '<span class="cp-bad">' . esc_html__( 'No WebP support', 'compressino' ) . '</span>'; ?></dd></div>
								<div><dt><?php esc_html_e( 'Compressed images', 'compressino' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $stats['converted'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Space saved', 'compressino' ); ?></dt><dd><?php echo esc_html( $stats['saved'] ? size_format( $stats['saved'], 1 ) : '0 MB' ); ?><?php if ( $stats['original_size'] ) : ?> <span class="cp-muted">(<?php echo esc_html( number_format_i18n( 100 * $stats['saved'] / $stats['original_size'], 0 ) ); ?>%)</span><?php endif; ?></dd></div>
							</dl>
						</section>
						<?php self::cleanup_panel(); ?>
						<?php Compressino_Updates::screen(); ?>
					</aside>
				</div>
				<?php self::images_section(); ?>
				<?php endif; ?>
			</main>
		</div>
		<?php
	}
	/* ---------- Images: list, bulk run and media popup ---------- */

	/** Script and styles for the Images section and the Compressino line in the media popup. Administrators only. */
	public static function enqueue_manage() {
		if ( ! current_user_can( 'manage_options' ) || wp_script_is( 'compressino-manage', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'compressino-manage', COMPRESSINO_URL . 'assets/manage.css', array(), COMPRESSINO_VERSION . '.' . filemtime( COMPRESSINO_DIR . 'assets/manage.css' ) );
		wp_enqueue_script( 'compressino-manage', COMPRESSINO_URL . 'assets/manage.js', array(), COMPRESSINO_VERSION . '.' . filemtime( COMPRESSINO_DIR . 'assets/manage.js' ), true );
		$keep = Compressino::settings()['keep_originals'];
		wp_add_inline_script(
			'compressino-manage',
			'window.compressinoManage = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'compressino_manage' ),
					'i18n'  => array(
						'compress'    => __( 'Compress', 'compressino' ),
						'image'       => __( 'Image', 'compressino' ),
						'now'         => __( 'Now', 'compressino' ),
						'webp'        => __( 'As WebP', 'compressino' ),
						'saving'      => __( 'Saving', 'compressino' ),
						'original'    => __( 'Original', 'compressino' ),
						'savedCol'    => __( 'Saved', 'compressino' ),
						'reason'      => __( 'Reason', 'compressino' ),
						'estimating'  => __( 'Working it out…', 'compressino' ),
						/* translators: %s: estimated size */
						'about'       => __( 'about %s', 'compressino' ),
						/* translators: 1: size saved, 2: percentage */
						'savingFmt'   => __( '%1$s (%2$s%%)', 'compressino' ),
						'notSmaller'  => __( 'Not smaller', 'compressino' ),
						'noSaving'    => __( 'No saving', 'compressino' ),
						'unknown'     => __( 'Could not estimate', 'compressino' ),
						'deleting'    => __( 'Deleting the originals…', 'compressino' ),
						'again'       => __( 'Compress again', 'compressino' ),
						'restore'     => __( 'Restore original', 'compressino' ),
						'compressing' => __( 'Compressing…', 'compressino' ),
						'restoring'   => __( 'Restoring…', 'compressino' ),
						'failed'      => __( 'Something went wrong. Please try again.', 'compressino' ),
						/* translators: 1: original size, 2: new size, 3: percentage */
						'saved'       => __( '%1$s → %2$s, %3$s%% smaller', 'compressino' ),
						/* translators: %d: number of images */
						'ready'       => __( '%d images are not compressed yet.', 'compressino' ),
						/* translators: 1: images done, 2: total */
						'progress'    => __( 'Compressing %1$d of %2$d…', 'compressino' ),
						/* translators: 1: compressed, 2: skipped, 3: failed */
						'done'        => __( 'Done. %1$d compressed, %2$d skipped, %3$d failed.', 'compressino' ),
						'stopped'     => __( 'Stopped. The remaining images are still in the list.', 'compressino' ),
						'empty'       => array(
							'uncompressed' => __( 'Every image is compressed.', 'compressino' ),
							'compressed'   => __( 'No compressed images yet.', 'compressino' ),
							'skipped'      => __( 'No skipped images.', 'compressino' ),
							'restored'     => __( 'No restored images.', 'compressino' ),
						),
					),
				)
			) . ';',
			'before'
		);
	}

	private static function check_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot compress images.', 'compressino' ) ), 403 );
		}
		check_ajax_referer( 'compressino_manage', 'nonce' );
	}

	private static function posted_ids( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check_manage().
		$raw = isset( $_POST[ $key ] ) ? (array) wp_unslash( $_POST[ $key ] ) : array();
		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ), 0, 25 );
	}

	public static function ajax_images() {
		self::check_manage();
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'uncompressed';
		if ( ! in_array( $status, array( 'uncompressed', 'compressed', 'skipped', 'restored' ), true ) ) {
			$status = 'uncompressed';
		}
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$ids    = Compressino::image_ids( $status );
		wp_send_json_success(
			array(
				'rows'   => array_map( array( 'Compressino', 'image_row' ), array_slice( $ids, $offset, 20 ) ),
				'total'  => count( $ids ),
				'counts' => Compressino::counts(),
			)
		);
	}

	/** Compress chosen images, or with bulk the next few uncompressed ones. Bulk leaves out images that failed earlier in the run. */
	public static function ajax_compress() {
		self::check_manage();
		$bulk = ! empty( $_POST['bulk'] );
		if ( $bulk ) {
			$queue = array_values( array_diff( Compressino::uncompressed_ids(), self::posted_ids( 'skip' ) ) );
			$ids   = array_slice( $queue, 0, 3 );
		} else {
			$ids = self::posted_ids( 'ids' );
		}
		$results = array();
		foreach ( $ids as $id ) {
			$result    = Compressino::convert_attachment( $id, $bulk ? 'bulk' : 'manual' );
			$error     = is_wp_error( $result );
			$code      = $error ? $result->get_error_code() : '';
			$results[] = array(
				'id'      => $id,
				'result'  => ! $error ? 'done' : ( in_array( $code, array( 'compressino_animated', 'compressino_larger' ), true ) ? 'skipped' : 'failed' ),
				'message' => $error ? $result->get_error_message() : '',
				'field'   => self::field_html( $id ),
			);
		}
		$out = array( 'results' => $results, 'counts' => Compressino::counts(), 'originals' => self::originals_payload() );
		if ( $bulk ) {
			$failed           = wp_list_pluck( wp_list_filter( $results, array( 'result' => 'failed' ) ), 'id' );
			$out['remaining'] = count( array_diff( Compressino::uncompressed_ids(), self::posted_ids( 'skip' ), $failed ) );
		}
		wp_send_json_success( $out );
	}

	public static function ajax_restore() {
		self::check_manage();
		$results = array();
		foreach ( self::posted_ids( 'ids' ) as $id ) {
			$result    = Compressino::restore_attachment( $id );
			$results[] = array(
				'id'      => $id,
				'result'  => is_wp_error( $result ) ? 'failed' : 'done',
				'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
				'field'   => self::field_html( $id ),
			);
		}
		wp_send_json_success( array( 'results' => $results, 'counts' => Compressino::counts(), 'originals' => self::originals_payload() ) );
	}

	/** The Compressino line in the media popup and on the Edit Media screen. */
	public static function media_field( $fields, $post ) {
		if ( ! current_user_can( 'manage_options' ) || ! in_array( get_post_mime_type( $post ), array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			return $fields;
		}
		self::enqueue_manage();
		$fields['compressino'] = array(
			'label' => 'Compressino',
			'input' => 'html',
			'html'  => self::field_html( $post->ID ),
		);
		return $fields;
	}

	/** Short estimate line: "As WebP about 240 KB, 87% smaller." */
	public static function estimate_text( $estimate ) {
		if ( empty( $estimate['smaller'] ) ) {
			return __( 'WebP would not make it smaller.', 'compressino' );
		}
		/* translators: 1: estimated size, 2: percentage */
		return sprintf( __( 'As WebP about %1$s, %2$d%% smaller.', 'compressino' ), $estimate['estimate_human'], $estimate['percent'] );
	}

	/** Estimates for a few images, worked out one by one so the server is never flooded. */
	public static function ajax_estimate() {
		self::check_manage();
		$out = array();
		foreach ( array_slice( self::posted_ids( 'ids' ), 0, 3 ) as $id ) {
			$estimate = Compressino::estimate( $id );
			$out[]    = is_wp_error( $estimate )
				? array( 'id' => $id, 'error' => $estimate->get_error_message() )
				: array_merge( $estimate, array( 'id' => $id, 'text' => self::estimate_text( $estimate ) ) );
		}
		wp_send_json_success( array( 'estimates' => $out ) );
	}

	public static function field_html( $id ) {
		$row      = Compressino::image_row( $id );
		$action   = '';
		$label    = '';
		$estimate = '';
		switch ( $row['status'] ) {
			case 'compressed':
				/* translators: 1: original size, 2: new size, 3: percentage */
				$text = sprintf( __( 'Compressed to WebP: %1$s → %2$s, %3$d%% smaller.', 'compressino' ), Compressino::human_size( $row['original_size'] ), Compressino::human_size( $row['new_size'] ), $row['saved_percent'] );
				if ( $row['can_restore'] ) {
					$action = 'restore';
					$label  = __( 'Restore original', 'compressino' );
				} else {
					$text .= ' ' . __( 'No original was kept, so it cannot be restored.', 'compressino' );
				}
				break;
			case 'skipped':
				$text   = $row['reason'];
				$action = 'compress';
				$label  = __( 'Try again', 'compressino' );
				break;
			case 'already_webp':
				$text = __( 'Already WebP.', 'compressino' );
				break;
			default:
				$text   = sprintf(
					/* translators: %s: file size */
					'restored' === $row['status'] ? __( 'Original restored, now %s.', 'compressino' ) : __( 'Now %s.', 'compressino' ),
					Compressino::human_size( $row['file_size'] )
				);
				$action = 'compress';
				$label  = __( 'Compress to WebP', 'compressino' );
				if ( Compressino::supported() ) {
					$cached = Compressino::estimate( $id, false );
					if ( is_array( $cached ) ) {
						$estimate = '<span>' . esc_html( self::estimate_text( $cached ) ) . '</span>';
						if ( ! $cached['smaller'] ) {
							$action = '';
						}
					} elseif ( ! is_wp_error( $cached ) ) {
						$estimate = '<span data-compressino-estimate="' . esc_attr( $id ) . '">' . esc_html__( 'Working out the WebP size…', 'compressino' ) . '</span>';
					}
				}
		}
		if ( 'compress' === $action && ! Compressino::supported() ) {
			$action = '';
			$text  .= ' ' . __( 'This server cannot create WebP images.', 'compressino' );
		}
		$html = '<div class="compressino-field" data-compressino-field="' . esc_attr( $id ) . '"><p class="compressino-field__text">' . esc_html( $text ) . ( $estimate ? ' ' . $estimate : '' ) . '</p>';
		if ( $action ) {
			$html .= '<button type="button" class="button compressino-field__button" data-compressino-action="' . esc_attr( $action ) . '" data-id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</button>';
			$html .= '<p class="compressino-field__help">' . esc_html__( 'The file name changes. Places that use this image from the Media Library update by themselves; links typed into content do not.', 'compressino' ) . '</p>';
		}
		return $html . '<p class="compressino-field__status" data-compressino-status role="status" aria-live="polite"></p></div>';
	}

	/** The Images section below the settings. */
	private static function images_section() {
		$counts = Compressino::counts();
		$keep   = Compressino::settings()['keep_originals'];
		$tabs   = array(
			'uncompressed' => __( 'Not compressed', 'compressino' ),
			'compressed'   => __( 'Compressed', 'compressino' ),
			'skipped'      => __( 'Skipped', 'compressino' ),
			'restored'     => __( 'Restored', 'compressino' ),
		);
		?>
		<section class="cp-images" data-compressino-images>
			<h2><?php esc_html_e( 'Images', 'compressino' ); ?></h2>
			<div class="cp-filters" role="group" aria-label="<?php esc_attr_e( 'Show images', 'compressino' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button type="button" class="cp-filter" data-status="<?php echo esc_attr( $key ); ?>" aria-pressed="<?php echo 'uncompressed' === $key ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?> <span data-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></span></button>
				<?php endforeach; ?>
			</div>
			<div class="cp-bulk" data-bulk<?php echo $counts['uncompressed'] ? '' : ' hidden'; ?>>
				<div class="cp-bulk__row">
					<p class="cp-bulk__text" data-bulk-text role="status" aria-live="polite"></p>
					<div class="cp-bulk__actions">
						<button type="button" class="cp-button" data-bulk-start<?php disabled( ! Compressino::supported() ); ?>><?php esc_html_e( 'Compress all', 'compressino' ); ?></button>
						<button type="button" class="cp-button cp-button--quiet" data-bulk-stop hidden><?php esc_html_e( 'Stop', 'compressino' ); ?></button>
					</div>
				</div>
				<div class="cp-progress" data-bulk-progress hidden><span data-bulk-bar></span></div>
				<p class="cp-help"><?php echo esc_html( $keep ? __( 'Originals are kept, so you can restore any image. File names change to .webp: images used from the Media Library update by themselves, links typed into content do not.', 'compressino' ) : __( 'Keep originals is off, so the originals are deleted and this cannot be undone. File names change to .webp: links typed into content stop working.', 'compressino' ) ); ?></p>
			</div>
			<div class="cp-list" data-list></div>
			<p class="cp-help cp-list__empty" data-empty hidden></p>
			<button type="button" class="cp-button cp-button--quiet cp-list__more" data-more hidden><?php esc_html_e( 'Show more', 'compressino' ); ?></button>
		</section>
		<?php
	}
	/* ---------- Info box, clean-up box and About page ---------- */

	/** Kept originals in the form the screen shows them. */
	public static function originals_payload() {
		$found = Compressino::originals();
		return array(
			'count'      => $found['count'],
			/* translators: %s: number of images */
			'count_text' => sprintf( _n( '%s image', '%s images', $found['count'], 'compressino' ), number_format_i18n( $found['count'] ) ),
			'size'       => Compressino::human_size( $found['bytes'] ),
		);
	}

	public static function ajax_delete_originals() {
		self::check_manage();
		$result = Compressino::delete_originals();
		wp_send_json_success(
			array(
				/* translators: 1: number of images, 2: space freed */
				'message'   => sprintf( _n( 'Deleted the original of %1$s image and freed %2$s.', 'Deleted the originals of %1$s images and freed %2$s.', $result['deleted'], 'compressino' ), number_format_i18n( $result['deleted'] ), Compressino::human_size( $result['bytes'] ) ),
				'originals' => self::originals_payload(),
			)
		);
	}

	private static function info_panel() {
		?>
		<section class="cp-panel">
			<h2><?php esc_html_e( 'What Compressino does', 'compressino' ); ?></h2>
			<p class="cp-panel__text"><?php esc_html_e( 'Compressino turns PNG and JPG images into WebP, a smaller format every modern browser supports.', 'compressino' ); ?></p>
			<ul class="cp-points">
				<li><?php esc_html_e( 'You decide how sharp your images get.', 'compressino' ); ?></li>
				<li><?php esc_html_e( 'Compress images straight from the Media Library.', 'compressino' ); ?></li>
				<li><?php esc_html_e( 'Compress all your images in one go.', 'compressino' ); ?></li>
				<li><?php esc_html_e( 'Images are compressed right in your browser.', 'compressino' ); ?></li>
				<li><?php esc_html_e( 'No external server is used, which is why it is free.', 'compressino' ); ?></li>
				<li><?php printf( /* translators: %s: link to the About page */ esc_html__( 'Made by %s.', 'compressino' ), '<a href="' . esc_url( self::url( array( 'tab' => 'about' ) ) ) . '">Rino de Boer</a>' ); ?></li>
			</ul>
		</section>
		<?php
	}

	private static function cleanup_panel() {
		$originals = self::originals_payload();
		?>
		<section class="cp-panel" data-cleanup<?php echo $originals['count'] ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Clean up originals', 'compressino' ); ?></h2>
			<p class="cp-panel__text"><?php printf( /* translators: 1: number of images, 2: size */ esc_html__( 'Happy with the compression? The originals of %1$s are still kept on your server, using %2$s. Delete them for a lighter site and smaller backups.', 'compressino' ), '<strong data-cleanup-count>' . esc_html( $originals['count_text'] ) . '</strong>', '<strong data-cleanup-size>' . esc_html( $originals['size'] ) . '</strong>' ); ?></p>
			<p class="cp-help cp-cleanup__warning"><?php esc_html_e( 'After this, these images can no longer be restored.', 'compressino' ); ?></p>
			<button type="button" class="cp-button cp-button--danger" data-cleanup-run data-confirm="<?php esc_attr_e( 'Delete the originals of all compressed images? They can no longer be restored after this.', 'compressino' ); ?>"><?php esc_html_e( 'Delete originals', 'compressino' ); ?></button>
			<p class="cp-help" data-cleanup-status role="status" aria-live="polite"></p>
		</section>
		<?php
	}

	private static function nav( $tab ) {
		$tabs = array(
			'settings' => __( 'Settings', 'compressino' ),
			'about'    => __( 'About', 'compressino' ),
		);
		echo '<nav class="cp-nav" aria-label="Compressino">';
		foreach ( $tabs as $key => $label ) {
			$url = 'settings' === $key ? self::url() : self::url( array( 'tab' => $key ) );
			echo '<a href="' . esc_url( $url ) . '"' . ( $tab === $key ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private static function about() {
		?>
		<div class="cp-about">
			<span class="cp-eyebrow"><?php esc_html_e( 'About', 'compressino' ); ?></span>
			<h2><?php esc_html_e( 'The story behind Compressino', 'compressino' ); ?></h2>
			<img src="<?php echo esc_url( COMPRESSINO_URL . 'assets/rino-profile.jpg' ); ?>" alt="Rino de Boer" class="cp-about__photo" width="80" height="80">

			<p>Hey, my name is Rino. Every website I build needs compressed images, so over the years I have tried a lot of compression plugins.</p>

			<p>Almost all of them work the same way. The free version does a little, and then the plugin keeps asking you to upgrade. Bulk compression is paid. Your images are sent to their servers. And there are notices everywhere.</p>

			<p>Then I learned that compression doesn't need any of that. Your browser can turn an image into WebP by itself, with open-source compression that is built right in. Your own server can do the same.</p>

			<p>So I thought: wait, I can just make this myself. A free plugin that doesn't annoy me, is really simple, and just does the basics well.</p>

			<p>That is what Compressino became. It doesn't use an external server, so it costs nothing to run. That is why I can keep it completely free. No pro version, no limits, no upsells.</p>

			<p>To keep it safe, I also built in a restore feature. Compressino keeps the original of every image, so you can always go back. And once you are happy with the result, you can clean up the originals with one click.</p>

			<p>I hope Compressino makes your websites a little faster without the hassle. That is really all it is supposed to do.</p>

			<img src="<?php echo esc_url( COMPRESSINO_URL . 'assets/rino-signature.svg' ); ?>" alt="Rino" class="cp-about__signature" width="60" height="32" aria-hidden="true">
		</div>
		<?php
	}
}
