<?php
/** Core: settings, WebP conversion, backups and the server safety net. @package Compressino */
defined( 'ABSPATH' ) || exit;

final class Compressino {
	const OPTION = 'compressino_settings';
	const META   = '_compressino';
	const BACKUP = 'compressino-backups';

	/** Files converted during this request by the upload filter, keyed by their new path. */
	private static $uploaded = array();
	/** Attachments that arrived unoptimised through a route that skipped the upload filter. */
	private static $pending = array();
	private static $supported = null;
	/** True while the REST API adds sub-sizes to an existing image. */
	private static $sideload = false;

	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_upload' ), 20, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'add_attachment' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'after_metadata' ), 99, 3 );
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'note_route' ), 10, 3 );
		add_filter( 'wp_client_side_media_processing_enabled', array( __CLASS__, 'client_side_processing' ), 20 );
	}

	public static function activate() {
		add_option( self::OPTION, self::defaults() );
	}

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'quality'        => 90,
			'browser'        => true,
			'server'         => true,
			'keep_originals' => true,
			'skip_webp'      => true,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array(), true );
	}

	/** Clean user input. With $fill, missing values fall back to the defaults instead of off. */
	public static function sanitize( $input, $fill = false ) {
		$defaults = self::defaults();
		$clean    = array(
			'quality' => isset( $input['quality'] ) ? max( 1, min( 100, (int) $input['quality'] ) ) : $defaults['quality'],
		);
		foreach ( array( 'browser', 'server', 'keep_originals', 'skip_webp' ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? (bool) $input[ $key ] : ( $fill ? $defaults[ $key ] : false );
		}
		return $clean;
	}

	public static function save_settings( $input ) {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean );
		return $clean;
	}

	/* ---------- Environment ---------- */

	/** True when Imagick or GD can write WebP on this server. */
	public static function supported() {
		if ( null === self::$supported ) {
			self::$supported = wp_image_editor_supports( array( 'mime_type' => 'image/webp', 'methods' => array( 'resize', 'save' ) ) );
		}
		return self::$supported;
	}

	/** Name of the image library WordPress uses for WebP: Imagick, GD or an empty string. */
	public static function engine() {
		if ( ! function_exists( '_wp_image_editor_choose' ) ) {
			require_once ABSPATH . WPINC . '/media.php';
		}
		$class = _wp_image_editor_choose( array( 'mime_type' => 'image/webp', 'methods' => array( 'resize', 'save' ) ) );
		if ( ! $class ) {
			return '';
		}
		if ( 'WP_Image_Editor_Imagick' === $class || is_subclass_of( $class, 'WP_Image_Editor_Imagick' ) ) {
			return 'Imagick';
		}
		if ( 'WP_Image_Editor_GD' === $class || is_subclass_of( $class, 'WP_Image_Editor_GD' ) ) {
			return 'GD';
		}
		return $class;
	}

	/* ---------- Server safety net ---------- */

	/**
	 * The block editor can make thumbnails in the browser and send them one by one. Those files are
	 * made from the original upload, so they would not match a WebP main image. While Compressino
	 * converts uploads, the block editor uses the standard upload and the server builds the thumbnails.
	 */
	public static function client_side_processing( $enabled ) {
		if ( ! $enabled || ! self::supported() ) {
			return $enabled;
		}
		$settings = self::settings();
		return ! ( $settings['browser'] || $settings['server'] );
	}

	/** Thumbnails sent to an existing image are never converted on their own. */
	public static function note_route( $response, $handler, $request ) {
		self::$sideload = (bool) preg_match( '#^/wp/v2/media/\d+/sideload#', $request->get_route() );
		return $response;
	}

	/**
	 * Whether the server should convert this file. Developers can return false to leave a file alone.
	 *
	 * @param string $file          Absolute path.
	 * @param string $mime          MIME type.
	 * @param int    $attachment_id Attachment ID, or 0 while the file is still being uploaded.
	 */
	private static function should_convert( $file, $mime, $attachment_id ) {
		$settings = self::settings();
		if ( ! $settings['server'] || ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) || ! self::supported() ) {
			return false;
		}
		/**
		 * Filters whether the server safety net converts a PNG or JPG upload to WebP.
		 *
		 * @param bool   $convert       Default true.
		 * @param string $file          Absolute path of the uploaded file.
		 * @param string $mime          image/png or image/jpeg.
		 * @param int    $attachment_id 0 during the upload itself, otherwise the attachment ID.
		 */
		return (bool) apply_filters( 'compressino_server_convert', true, $file, $mime, (int) $attachment_id );
	}

	/**
	 * Every core upload route (media window, REST API, sideloads) passes through wp_handle_upload.
	 * Converting here means WordPress builds its thumbnails from the WebP file straight away.
	 */
	public static function handle_upload( $upload, $context = 'upload' ) {
		if ( self::$sideload || ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! self::should_convert( $upload['file'], $upload['type'], 0 ) ) {
			return $upload;
		}
		$record = self::convert_file( $upload['file'], $upload['type'], 'server' );
		if ( is_wp_error( $record ) ) {
			$skipped = $record->get_error_data();
			if ( is_array( $skipped ) ) {
				self::$uploaded[ wp_normalize_path( $upload['file'] ) ] = $skipped;
			}
			return $upload;
		}
		$new_file = $record['file'];
		unset( $record['file'] );
		self::$uploaded[ wp_normalize_path( $new_file ) ] = $record;
		$upload['file'] = $new_file;
		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . rawurlencode( wp_basename( $new_file ) );
		$upload['type'] = 'image/webp';
		return $upload;
	}

	/** Write the flag, or queue files that arrived some other way for conversion. */
	public static function add_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id, true );
		if ( ! $file ) {
			return;
		}
		$key = wp_normalize_path( $file );
		if ( isset( self::$uploaded[ $key ] ) ) {
			update_post_meta( $attachment_id, self::META, self::$uploaded[ $key ] );
			unset( self::$uploaded[ $key ] );
			return;
		}
		if ( get_post_meta( $attachment_id, self::META, true ) ) {
			return;
		}
		if ( self::browser_flag( $attachment_id, $file ) ) {
			return;
		}
		if ( self::should_convert( $file, get_post_mime_type( $attachment_id ), $attachment_id ) ) {
			self::$pending[ $attachment_id ] = true;
		}
	}

	/**
	 * The browser script sends the original size with each file it converted. Only used for the savings shown.
	 */
	private static function browser_flag( $attachment_id, $file ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the upload request itself is verified by WordPress.
		if ( 'image/webp' !== get_post_mime_type( $attachment_id ) || empty( $_POST['compressino_original_size'] ) || ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		$original = absint( wp_unslash( $_POST['compressino_original_size'] ) );
		$type     = isset( $_POST['compressino_original_type'] ) ? sanitize_mime_type( wp_unslash( $_POST['compressino_original_type'] ) ) : '';
		// phpcs:enable
		$size = (int) filesize( $file );
		if ( $original <= $size || $original > 2 * GB_IN_BYTES ) {
			return false;
		}
		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'status'        => 'converted',
				'source'        => 'browser',
				'original_size' => $original,
				'new_size'      => $size,
				'date'          => time(),
				'backup'        => '',
				'previous'      => array( 'file' => '', 'mime' => in_array( $type, array( 'image/png', 'image/jpeg' ), true ) ? $type : '' ),
			)
		);
		return true;
	}

	/** Convert queued attachments once WordPress has finished their metadata. */
	public static function after_metadata( $metadata, $attachment_id, $context = 'create' ) {
		if ( empty( self::$pending[ $attachment_id ] ) ) {
			return $metadata;
		}
		unset( self::$pending[ $attachment_id ] );
		$result = self::convert_attachment( $attachment_id, 'server', $metadata );
		return is_wp_error( $result ) ? $metadata : wp_get_attachment_metadata( $attachment_id );
	}

	/** Some code inserts attachments without ever generating metadata. Catch those at the end of the request. */
	public static function shutdown() {
		foreach ( array_keys( self::$pending ) as $attachment_id ) {
			unset( self::$pending[ $attachment_id ] );
			if ( get_post( $attachment_id ) ) {
				self::convert_attachment( $attachment_id, 'server' );
			}
		}
	}

	/* ---------- Conversion ---------- */

	/**
	 * Convert a freshly uploaded file that has no attachment yet. The original is backed up, then removed.
	 *
	 * @return array|WP_Error Record for the attachment meta, plus 'file'. A skip returns WP_Error with the skip record as data.
	 */
	private static function convert_file( $file, $mime, $source ) {
		$result = self::make_webp( $file, $mime );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$backup = self::backup( $file );
		if ( is_wp_error( $backup ) ) {
			wp_delete_file( $result['file'] );
			return $backup;
		}
		wp_delete_file( $file );
		return array(
			'file'          => $result['file'],
			'status'        => 'converted',
			'source'        => $source,
			'original_size' => $result['original_size'],
			'new_size'      => $result['size'],
			'date'          => time(),
			'backup'        => $backup,
			'previous'      => array(
				'file' => self::relative( $file ),
				'mime' => $mime,
			),
		);
	}

	/**
	 * Convert an existing attachment to WebP and rebuild its thumbnails.
	 * This is the one routine behind the safety net, the media popup button, the bulk run and the abilities.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $source        server, manual, bulk or ability.
	 * @param array|null $metadata      Metadata when it is not saved yet.
	 * @return array|WP_Error The saved flag.
	 */
	public static function convert_attachment( $attachment_id, $source = 'manual', $metadata = null ) {
		$attachment_id = (int) $attachment_id;
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'compressino_missing', __( 'This image does not exist.', 'compressino' ) );
		}
		$flag = get_post_meta( $attachment_id, self::META, true );
		if ( is_array( $flag ) && 'converted' === ( $flag['status'] ?? '' ) ) {
			return new WP_Error( 'compressino_done', __( 'This image is already compressed.', 'compressino' ) );
		}
		$settings = self::settings();
		$mime     = get_post_mime_type( $attachment_id );
		$allowed  = array( 'image/png', 'image/jpeg' );
		if ( ! $settings['skip_webp'] ) {
			$allowed[] = 'image/webp';
		}
		if ( ! in_array( $mime, $allowed, true ) ) {
			return new WP_Error( 'compressino_type', __( 'Only PNG and JPG images can be compressed.', 'compressino' ) );
		}
		if ( ! self::supported() ) {
			return new WP_Error( 'compressino_unsupported', __( 'This server cannot create WebP images.', 'compressino' ) );
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'compressino_missing_file', __( 'The image file is missing on the server.', 'compressino' ) );
		}
		$meta   = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );
		$meta   = is_array( $meta ) ? $meta : array();
		$dir    = dirname( $file );
		$origin = $file;
		// Start from the full original when WordPress made a -scaled copy.
		if ( ! empty( $meta['original_image'] ) && file_exists( path_join( $dir, $meta['original_image'] ) ) ) {
			$origin = path_join( $dir, $meta['original_image'] );
		}

		$result = self::make_webp( $origin, $mime );
		if ( is_wp_error( $result ) ) {
			$skipped = $result->get_error_data();
			if ( is_array( $skipped ) ) {
				update_post_meta( $attachment_id, self::META, $skipped );
			}
			return $result;
		}
		$backup = self::backup( $origin );
		if ( is_wp_error( $backup ) ) {
			wp_delete_file( $result['file'] );
			return $backup;
		}

		$old = array( $file, $origin );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$old[] = path_join( $dir, $size['file'] );
			}
		}
		$previous = array(
			'file'     => get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'mime'     => $mime,
			'metadata' => $meta,
		);

		global $wpdb;
		update_attached_file( $attachment_id, $result['file'] );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/webp' ), array( 'ID' => $attachment_id ) );
		clean_post_cache( $attachment_id );

		foreach ( array_unique( $old ) as $path ) {
			if ( $path !== $result['file'] && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $result['file'] ) );

		$record = array(
			'status'        => 'converted',
			'source'        => sanitize_key( $source ),
			'original_size' => $result['original_size'],
			'new_size'      => $result['size'],
			'date'          => time(),
			'backup'        => $backup,
			'previous'      => $previous,
		);
		update_post_meta( $attachment_id, self::META, $record );
		/**
		 * Fires after an attachment is converted to WebP.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $record        The saved flag: sizes, date, backup and previous file.
		 */
		do_action( 'compressino_converted', $attachment_id, $record );
		return $record;
	}

	/**
	 * Put the backed-up original back in place of the WebP version and rebuild its thumbnails.
	 * The image keeps its ID, so places that use the attachment ID show the original again.
	 * The flag is set to restored, so bulk runs leave the image alone; an explicit compress still works.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error The saved flag.
	 */
	public static function restore_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'compressino_missing', __( 'This image does not exist.', 'compressino' ) );
		}
		$flag = self::flag( $attachment_id );
		if ( ! $flag || 'converted' !== ( $flag['status'] ?? '' ) ) {
			return new WP_Error( 'compressino_not_compressed', __( 'This image was not compressed by Compressino, so there is nothing to restore.', 'compressino' ) );
		}
		if ( empty( $flag['backup'] ) ) {
			return new WP_Error( 'compressino_no_backup', __( 'There is no original to restore. It was compressed in the browser before upload, or Keep originals was off.', 'compressino' ) );
		}
		$backup = self::original_path( $attachment_id );
		if ( ! $backup ) {
			return new WP_Error( 'compressino_backup_missing', __( 'The backup of the original is missing on the server.', 'compressino' ) );
		}
		$mime = $flag['previous']['mime'] ?? '';
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			$mime = wp_check_filetype( $backup )['type'];
		}
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			return new WP_Error( 'compressino_type', __( 'The backup is not a PNG or JPG image.', 'compressino' ) );
		}

		$relative   = preg_replace( '#^site-\d+/#', '', $flag['backup'] );
		$uploads    = wp_normalize_path( wp_get_upload_dir()['basedir'] );
		$target_dir = dirname( path_join( $uploads, $relative ) );
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'compressino_restore', __( 'Could not create the uploads folder. Nothing was changed.', 'compressino' ) );
		}
		$target = $target_dir . '/' . wp_unique_filename( $target_dir, wp_basename( $relative ) );
		if ( ! @copy( $backup, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'compressino_restore', __( 'Could not copy the original back. Nothing was changed.', 'compressino' ) );
		}

		$file = get_attached_file( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$old  = $file ? array( $file ) : array();
		if ( $file && is_array( $meta ) ) {
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$old[] = path_join( dirname( $file ), $size['file'] );
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$old[] = path_join( dirname( $file ), $meta['original_image'] );
			}
		}

		do_action( 'compressino_before_restore', $attachment_id );
		global $wpdb;
		update_attached_file( $attachment_id, $target );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime ), array( 'ID' => $attachment_id ) );
		clean_post_cache( $attachment_id );
		foreach ( array_unique( $old ) as $path ) {
			if ( wp_normalize_path( $path ) !== wp_normalize_path( $target ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );
		wp_delete_file( $backup );

		$record = array(
			'status'        => 'restored',
			'original_size' => (int) filesize( $target ),
			'date'          => time(),
		);
		update_post_meta( $attachment_id, self::META, $record );
		/**
		 * Fires after an attachment is restored to its original file.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $record        The saved flag.
		 */
		do_action( 'compressino_restored', $attachment_id, $record );
		return $record;
	}

	/**
	 * Images Compressino has not handled yet: PNG and JPG (and WebP when Skip WebP is off) without a flag.
	 *
	 * @return int[] Attachment IDs, newest first.
	 */
	public static function uncompressed_ids( $limit = 0, $offset = 0 ) {
		global $wpdb;
		$mimes = array( 'image/png', 'image/jpeg' );
		if ( ! self::settings()['skip_webp'] ) {
			$mimes[] = 'image/webp';
		}
		$in   = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$sql  = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ( $in ) AND m.meta_id IS NULL ORDER BY p.ID DESC";
		$args = array_merge( array( self::META ), $mimes );
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $limit;
			$args[] = (int) $offset;
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Write a WebP copy next to the source file. Large images are scaled by WordPress afterwards, as usual.
	 *
	 * @return array|WP_Error file, size, original_size. A skip carries its flag as error data.
	 */
	private static function make_webp( $source, $mime ) {
		$original_size = (int) filesize( $source );
		if ( self::animated( $source, $mime ) ) {
			return new WP_Error( 'compressino_animated', __( 'Animated images are left as they are.', 'compressino' ), self::skip( 'animated', $original_size ) );
		}
		$settings = self::settings();
		$editor   = wp_get_image_editor( $source, array( 'mime_type' => 'image/webp' ) );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$editor->set_quality( $settings['quality'] );
		$dir   = dirname( $source );
		$base  = preg_replace( '/-scaled$/', '', pathinfo( $source, PATHINFO_FILENAME ) );
		$name  = wp_unique_filename( $dir, $base . '.webp' );
		$saved = $editor->save( path_join( $dir, $name ), 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		clearstatcache( true, $saved['path'] );
		$new_size = (int) filesize( $saved['path'] );
		if ( ! $new_size || $new_size >= $original_size ) {
			wp_delete_file( $saved['path'] );
			return new WP_Error( 'compressino_larger', __( 'WebP would not make this image smaller, so it was left as it is.', 'compressino' ), self::skip( 'larger', $original_size ) );
		}
		return array(
			'file'          => $saved['path'],
			'size'          => $new_size,
			'original_size' => $original_size,
		);
	}

	private static function skip( $reason, $original_size ) {
		return array(
			'status'        => 'skipped',
			'reason'        => $reason,
			'original_size' => $original_size,
			'date'          => time(),
		);
	}

	/** Animated PNG and WebP files would lose their animation. */
	private static function animated( $file, $mime ) {
		if ( ! in_array( $mime, array( 'image/png', 'image/webp' ), true ) ) {
			return false;
		}
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		$head = fread( $handle, 65536 );
		fclose( $handle );
		if ( 'image/webp' === $mime ) {
			return false !== strpos( $head, 'ANIM' );
		}
		$actl = strpos( $head, 'acTL' );
		$idat = strpos( $head, 'IDAT' );
		return false !== $actl && ( false === $idat || $actl < $idat );
	}

	/* ---------- Backups ---------- */

	/** Originals live in wp-content/compressino-backups, outside the media and plugin folders. */
	public static function backup_dir() {
		return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . self::BACKUP );
	}

	/**
	 * Copy an original into the backup folder, keeping its uploads path.
	 *
	 * @return string|WP_Error Path relative to the backup folder, or '' when keeping originals is off.
	 */
	private static function backup( $file ) {
		if ( ! self::settings()['keep_originals'] ) {
			return '';
		}
		$root = self::backup_dir();
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		$relative = self::relative( $file );
		if ( is_multisite() ) {
			$relative = 'site-' . get_current_blog_id() . '/' . $relative;
		}
		$target_dir = dirname( $root . '/' . $relative );
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error( 'compressino_backup', __( 'Could not create the backup folder. Nothing was changed.', 'compressino' ) );
		}
		$target = $target_dir . '/' . wp_unique_filename( $target_dir, wp_basename( $file ) );
		if ( ! @copy( $file, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'compressino_backup', __( 'Could not back up the original. Nothing was changed.', 'compressino' ) );
		}
		return ltrim( substr( wp_normalize_path( $target ), strlen( $root ) ), '/' );
	}

	/** Path inside the uploads folder, or the file name for anything outside it. */
	public static function relative( $file ) {
		$file = wp_normalize_path( $file );
		$base = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		return 0 === strpos( $file, $base ) ? substr( $file, strlen( $base ) ) : wp_basename( $file );
	}

	/**
	 * The full-quality original of an image: its backup, or the PNG or JPG itself. Empty when there is none,
	 * for example after browser compression, where the original never reached the site.
	 */
	public static function original_path( $attachment_id ) {
		$flag = self::flag( $attachment_id );
		if ( $flag && 'converted' === ( $flag['status'] ?? '' ) ) {
			if ( empty( $flag['backup'] ) ) {
				return '';
			}
			$path = realpath( self::backup_dir() . '/' . $flag['backup'] );
			return $path && 0 === strpos( wp_normalize_path( $path ), self::backup_dir() . '/' ) ? $path : '';
		}
		if ( ! in_array( get_post_mime_type( $attachment_id ), array( 'image/png', 'image/jpeg' ), true ) ) {
			return '';
		}
		$file = wp_get_original_image_path( $attachment_id );
		return $file && file_exists( $file ) ? $file : '';
	}

	/** Recent images that still have a full-quality original, for the quality preview. */
	public static function preview_candidates( $limit = 10 ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND ( p.post_mime_type IN ( 'image/png', 'image/jpeg' ) OR m.meta_value IS NOT NULL )
				ORDER BY p.ID DESC LIMIT 60",
				self::META
			)
		);
		$found = array();
		foreach ( $ids as $id ) {
			if ( self::original_path( $id ) ) {
				$found[] = (int) $id;
			}
			if ( count( $found ) >= $limit ) {
				break;
			}
		}
		return $found;
	}

	/* ---------- Image list ---------- */

	/** Status of one image in lists: uncompressed, compressed, skipped, restored or already_webp. */
	public static function status( $attachment_id ) {
		$flag = self::flag( $attachment_id );
		if ( $flag ) {
			$state = $flag['status'] ?? '';
			return 'converted' === $state ? 'compressed' : ( 'restored' === $state ? 'restored' : 'skipped' );
		}
		if ( 'image/webp' === get_post_mime_type( $attachment_id ) && self::settings()['skip_webp'] ) {
			return 'already_webp';
		}
		return 'uncompressed';
	}

	/** One image for the Compressino screen, the media popup and the abilities. */
	public static function image_row( $attachment_id ) {
		$id     = (int) $attachment_id;
		$flag   = self::flag( $id );
		$status = self::status( $id );
		$file   = get_attached_file( $id );
		$thumb  = wp_get_attachment_image_url( $id, 'thumbnail' );
		$full   = wp_get_original_image_path( $id );
		$row    = array(
			'id'          => $id,
			'title'       => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'filename'    => $file ? wp_basename( $file ) : '',
			'mime'        => get_post_mime_type( $id ),
			'url'         => wp_get_attachment_url( $id ),
			'thumb'       => $thumb ? $thumb : '',
			'status'      => $status,
			// The full-size file, also when WordPress shows a smaller -scaled copy.
			'file_size'   => ( $full && file_exists( $full ) ) ? (int) filesize( $full ) : ( ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0 ),
			'can_restore' => 'compressed' === $status && '' !== self::original_path( $id ),
		);
		if ( 'compressed' === $status ) {
			$row['original_size'] = (int) ( $flag['original_size'] ?? 0 );
			$row['new_size']      = (int) ( $flag['new_size'] ?? 0 );
			$row['saved_percent'] = $row['original_size'] ? (int) round( 100 * ( $row['original_size'] - $row['new_size'] ) / $row['original_size'] ) : 0;
			$row['compressed_by'] = $flag['source'] ?? '';
		}
		if ( 'skipped' === $status ) {
			$row['reason'] = 'animated' === ( $flag['reason'] ?? '' ) ? __( 'Animated image, left as it is.', 'compressino' ) : __( 'WebP would not make it smaller.', 'compressino' );
		}
		return $row;
	}

	/** IDs of images with a status, newest first. all returns every PNG, JPG and WebP image. */
	public static function image_ids( $status = 'all' ) {
		if ( 'uncompressed' === $status ) {
			return self::uncompressed_ids();
		}
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( 'image/png', 'image/jpeg', 'image/webp' ) ORDER BY ID DESC" ) );
		if ( 'all' === $status ) {
			return $ids;
		}
		update_meta_cache( 'post', $ids );
		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $status ) {
					return self::status( $id ) === $status;
				}
			)
		);
	}

	/** Number of images per status. */
	public static function counts() {
		$counts = array_fill_keys( array( 'uncompressed', 'compressed', 'skipped', 'restored', 'already_webp' ), 0 );
		$ids    = self::image_ids( 'all' );
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			$counts[ self::status( $id ) ]++;
		}
		return $counts;
	}

	/** File size as shown in Compressino: whole KB, or MB with one decimal. */
	public static function human_size( $bytes ) {
		$bytes = (int) $bytes;
		return $bytes >= MB_IN_BYTES ? number_format_i18n( $bytes / MB_IN_BYTES, 1 ) . ' MB' : number_format_i18n( max( 1, round( $bytes / KB_IN_BYTES ) ) ) . ' KB';
	}

	/**
	 * What compressing would give: the image is converted to a temporary WebP with the current quality,
	 * measured and deleted. Nothing in the Media Library changes. Kept for a week as a transient, and
	 * worked out again when the file or the quality changes.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $compute       False only returns a cached estimate, or null.
	 * @return array|null|WP_Error original, estimate, saved, percent, smaller.
	 */
	public static function estimate( $attachment_id, $compute = true ) {
		$id = (int) $attachment_id;
		if ( ! in_array( self::status( $id ), array( 'uncompressed', 'restored', 'skipped' ), true ) ) {
			return new WP_Error( 'compressino_estimate', __( 'Only images that are not compressed get an estimate.', 'compressino' ) );
		}
		$mime = get_post_mime_type( $id );
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'compressino_missing_file', __( 'The image file is missing on the server.', 'compressino' ) );
		}
		$meta   = wp_get_attachment_metadata( $id );
		$origin = $file;
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) && file_exists( path_join( dirname( $file ), $meta['original_image'] ) ) ) {
			$origin = path_join( dirname( $file ), $meta['original_image'] );
		}
		$quality = self::settings()['quality'];
		$size    = (int) filesize( $origin );
		$key     = 'compressino_estimate_' . $id;
		$cached  = get_transient( $key );
		if ( is_array( $cached ) && $cached['quality'] === $quality && $cached['original'] === $size && $cached['mtime'] === (int) filemtime( $origin ) ) {
			return $cached;
		}
		if ( ! $compute ) {
			return null;
		}
		if ( ! self::supported() ) {
			return new WP_Error( 'compressino_unsupported', __( 'This server cannot create WebP images.', 'compressino' ) );
		}
		if ( self::animated( $origin, $mime ) ) {
			$webp = $size;
		} else {
			$editor = wp_get_image_editor( $origin, array( 'mime_type' => 'image/webp' ) );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$editor->maybe_exif_rotate();
			}
			$editor->set_quality( $quality );
			$temp  = trailingslashit( get_temp_dir() ) . 'compressino-estimate-' . $id . '-' . wp_generate_password( 8, false ) . '.webp';
			$saved = $editor->save( $temp, 'image/webp' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			clearstatcache( true, $saved['path'] );
			$webp = (int) filesize( $saved['path'] );
			wp_delete_file( $saved['path'] );
		}
		$smaller = $webp > 0 && $webp < $size;
		$result  = array(
			'original'       => $size,
			'estimate'       => $webp,
			'saved'          => $smaller ? $size - $webp : 0,
			'percent'        => $smaller && $size ? (int) round( 100 * ( $size - $webp ) / $size ) : 0,
			'smaller'        => $smaller,
			'original_human' => self::human_size( $size ),
			'estimate_human' => self::human_size( $webp ),
			'saved_human'    => $smaller ? self::human_size( $size - $webp ) : '',
			'quality'        => $quality,
			'mtime'          => (int) filemtime( $origin ),
		);
		set_transient( $key, $result, WEEK_IN_SECONDS );
		return $result;
	}

	/* ---------- Cleaning up originals ---------- */

	/** Compressed images whose original is still in the backup folder, with their total size. */
	public static function originals() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) );
		$out  = array( 'count' => 0, 'bytes' => 0, 'ids' => array() );
		foreach ( $rows as $row ) {
			$flag = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $flag ) || 'converted' !== ( $flag['status'] ?? '' ) || empty( $flag['backup'] ) ) {
				continue;
			}
			$path = self::original_path( (int) $row->post_id );
			if ( ! $path ) {
				continue;
			}
			$out['count']++;
			$out['bytes'] += (int) filesize( $path );
			$out['ids'][]  = (int) $row->post_id;
		}
		return $out;
	}

	/**
	 * Delete the kept originals of compressed images. The images stay compressed and keep working;
	 * only the way back is gone, so they can no longer be restored.
	 *
	 * @return array deleted (number of images) and bytes freed.
	 */
	public static function delete_originals() {
		$deleted = 0;
		$bytes   = 0;
		foreach ( self::originals()['ids'] as $id ) {
			$path = self::original_path( $id );
			if ( ! $path ) {
				continue;
			}
			$size = (int) filesize( $path );
			wp_delete_file( $path );
			clearstatcache( true, $path );
			if ( file_exists( $path ) ) {
				continue;
			}
			$flag                      = self::flag( $id );
			$flag['backup']            = '';
			$flag['originals_deleted'] = time();
			update_post_meta( $id, self::META, $flag );
			$deleted++;
			$bytes += $size;
		}
		/**
		 * Fires after kept originals were deleted.
		 *
		 * @param int $deleted Number of images whose original was deleted.
		 * @param int $bytes   Space freed in bytes.
		 */
		do_action( 'compressino_originals_deleted', $deleted, $bytes );
		return array( 'deleted' => $deleted, 'bytes' => $bytes );
	}

	/* ---------- Reporting ---------- */

	/** Flag for one attachment, or null. */
	public static function flag( $attachment_id ) {
		$flag = get_post_meta( (int) $attachment_id, self::META, true );
		return is_array( $flag ) ? $flag : null;
	}

	/** Totals across the Media Library. */
	public static function stats() {
		global $wpdb;
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) );
		$stats = array(
			'converted'     => 0,
			'skipped'       => 0,
			'original_size' => 0,
			'new_size'      => 0,
		);
		foreach ( $rows as $row ) {
			$flag = maybe_unserialize( $row );
			if ( ! is_array( $flag ) ) {
				continue;
			}
			if ( 'converted' === ( $flag['status'] ?? '' ) ) {
				$stats['converted']++;
				$stats['original_size'] += (int) ( $flag['original_size'] ?? 0 );
				$stats['new_size']      += (int) ( $flag['new_size'] ?? 0 );
			} elseif ( 'skipped' === ( $flag['status'] ?? '' ) ) {
				$stats['skipped']++;
			}
		}
		$stats['saved'] = max( 0, $stats['original_size'] - $stats['new_size'] );
		return $stats;
	}
}
