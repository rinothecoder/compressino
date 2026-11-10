<?php
/** Optional WordPress abilities for Compressino, so AI agents can compress and restore images through MCP. @package Compressino */
defined( 'ABSPATH' ) || exit;

final class Compressino_Abilities {
	/** Images handled per compress or restore call, so a call never runs into a server timeout. */
	const MAX_BATCH = 25;
	/** Seconds after which a batch stops and reports what is left. */
	const TIME_BUDGET = 20;

	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function allowed() {
		return current_user_can( 'manage_options' );
	}

	private static function meta( $readonly, $destructive, $idempotent ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	private static function add( $name, $label, $description, $properties, $required, $meta ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		} else {
			$schema['default'] = array();
		}
		wp_register_ability(
			'compressino/' . $name,
			array(
				'label'               => 'Compressino: ' . $label,
				'description'         => $description,
				'category'            => 'site',
				'input_schema'        => $schema,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, str_replace( '-', '_', $name ) ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'meta'                => $meta,
			)
		);
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$ids   = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'integer', 'minimum' => 1 ),
			'minItems' => 1,
			'maxItems' => self::MAX_BATCH,
		);
		$urls  = ' The file name changes (for example photo.jpg becomes photo.webp). Images placed by attachment ID, such as featured images, ACF and SCF image fields and Pencilino image fields, update by themselves. Image URLs typed into post content or theme files do not: check and update those yourself.';
		$keeps = Compressino::settings()['keep_originals'];

		self::add(
			'get-status',
			'Get Status',
			'Read Compressino status: whether this server can write WebP, the image engine, the settings (quality, browser and server compression, keep originals, skip WebP), totals of compressed, skipped and restored images, space saved, and how many images are still uncompressed. Call this before compressing. Requires manage_options.',
			array(),
			array(),
			self::meta( true, false, true )
		);
		self::add(
			'list-images',
			'List Images',
			'List Media Library images by Compressino status, newest first, with ID, title, file name, type, URL, status, sizes, percentage saved and whether the original can be restored. Status uncompressed means Compressino never handled the image; skipped means compressing would not help (animated, or WebP would be larger); restored means the original was put back; already_webp means the file was WebP before Compressino and Skip WebP is on. Requires manage_options.',
			array(
				'status' => array( 'type' => 'string', 'enum' => array( 'uncompressed', 'compressed', 'skipped', 'restored', 'already_webp', 'all' ), 'description' => 'Which images to list. Defaults to uncompressed.' ),
				'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Defaults to 50.' ),
				'offset' => array( 'type' => 'integer', 'minimum' => 0 ),
				'estimate' => array( 'type' => 'boolean', 'description' => 'Also return the expected WebP size and saving for images that are not compressed (a test conversion, nothing is saved). Slower: limit is capped at 10 when true.' ),
			),
			array(),
			self::meta( true, false, true )
		);
		self::add(
			'compress-images',
			'Compress Images',
			'Convert images to WebP with the quality from the settings and rebuild their thumbnails, using the same routine as the server safety net. Pass ids for specific images (also compresses restored or skipped ones again), or leave ids out to bulk compress the next batch of uncompressed images. Up to ' . self::MAX_BATCH . ' images per call: for a bulk run, call again while remaining is above 0. Images that WebP would not make smaller are left as they are and reported as skipped. ' . ( $keeps ? 'Originals are kept in wp-content/compressino-backups, so restore-images can undo this.' : 'Keep originals is OFF on this site, so originals are deleted and this cannot be undone. Ask the user before running it.' ) . $urls . ' Requires manage_options.',
			array(
				'ids'   => array_merge( $ids, array( 'description' => 'Optional. Attachment IDs to compress. Leave out for a bulk run over uncompressed images.' ) ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_BATCH, 'description' => 'Bulk run only: how many images in this call. Defaults to 10.' ),
			),
			array(),
			self::meta( false, ! $keeps, false )
		);
		self::add(
			'restore-images',
			'Restore Images',
			'Put the original PNG or JPG back for compressed images and rebuild their thumbnails. The WebP files and the backup are removed and the image keeps its ID. Restored images are left out of later bulk runs. Only works for images compressed on the server with Keep originals on; images compressed in the browser before upload have no original on the site.' . $urls . ' Up to ' . self::MAX_BATCH . ' images per call. Requires manage_options.',
			array( 'ids' => array_merge( $ids, array( 'description' => 'Attachment IDs to restore.' ) ) ),
			array( 'ids' ),
			self::meta( false, false, false )
		);
		self::add(
			'delete-originals',
			'Delete Originals',
			'Delete the kept originals (wp-content/' . Compressino::BACKUP . ') of all compressed images to free up server space and make site backups smaller. The images stay compressed and keep working, but can no longer be restored. This cannot be undone: show the user the count and size from get-status and ask before running it. Pass confirm: true. Requires manage_options.',
			array( 'confirm' => array( 'type' => 'boolean', 'enum' => array( true ), 'description' => 'Must be true. Only pass it after the user agreed.' ) ),
			array( 'confirm' ),
			self::meta( false, true, true )
		);
	}

	/** One image as agents see it. */
	private static function present( $id ) {
		$row = Compressino::image_row( $id );
		unset( $row['thumb'] );
		return $row;
	}

	private static function counts() {
		return Compressino::counts();
	}

	public static function get_status( $input ) {
		$stats = Compressino::stats();
		return array(
			'webp_supported' => Compressino::supported(),
			'engine'         => Compressino::engine(),
			'settings'       => Compressino::settings(),
			'images'         => self::counts(),
			'space_saved'    => size_format( $stats['saved'], 1 ),
			'saved_percent'  => $stats['original_size'] ? (int) round( 100 * $stats['saved'] / $stats['original_size'] ) : 0,
			'backup_folder'  => 'wp-content/' . Compressino::BACKUP,
			'originals_kept' => array( 'count' => Compressino::originals()['count'], 'size' => size_format( Compressino::originals()['bytes'], 1 ) ),
		);
	}

	public static function list_images( $input ) {
		$status = $input['status'] ?? 'uncompressed';
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$all    = Compressino::image_ids( $status );
		return array(
			'status' => $status,
			'total'  => count( $all ),
			'images' => array_map(
				function ( $id ) use ( $input ) {
					$row = self::present( $id );
					if ( ! empty( $input['estimate'] ) && in_array( $row['status'], array( 'uncompressed', 'restored' ), true ) ) {
						$e               = Compressino::estimate( $id );
						$row['estimate'] = is_wp_error( $e ) ? array( 'error' => $e->get_error_message() ) : array( 'webp_size' => $e['estimate'], 'saved' => $e['saved'], 'saved_percent' => $e['percent'], 'smaller' => $e['smaller'] );
					}
					return $row;
				},
				array_slice( $all, $offset, empty( $input['estimate'] ) ? $limit : min( $limit, 10 ) )
			),
		);
	}

	/** Run one routine over a list of images within the time budget. */
	private static function run( $ids, $callback ) {
		$start   = microtime( true );
		$results = array();
		$left    = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $i => $id ) {
			if ( $i > 0 && microtime( true ) - $start > self::TIME_BUDGET ) {
				$left[] = $id;
				continue;
			}
			$result = call_user_func( $callback, $id );
			if ( is_wp_error( $result ) ) {
				$code      = $result->get_error_code();
				$results[] = array(
					'id'      => $id,
					'result'  => in_array( $code, array( 'compressino_animated', 'compressino_larger' ), true ) ? 'skipped' : 'failed',
					'problem' => $code,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$results[] = array_merge( array( 'result' => 'done' ), self::present( $id ) );
		}
		return array( $results, $left );
	}

	private static function tally( $results ) {
		$tally = array( 'done' => 0, 'skipped' => 0, 'failed' => 0 );
		foreach ( $results as $row ) {
			$tally[ $row['result'] ]++;
		}
		return $tally;
	}

	public static function compress_images( $input ) {
		if ( ! Compressino::supported() ) {
			return new WP_Error( 'compressino_unsupported', 'This server cannot create WebP images. Ask the host to enable Imagick or GD with WebP support.' );
		}
		$bulk = empty( $input['ids'] );
		$ids  = $bulk ? Compressino::uncompressed_ids( isset( $input['limit'] ) ? (int) $input['limit'] : 10 ) : $input['ids'];
		list( $results, $left ) = self::run(
			$ids,
			function ( $id ) {
				return Compressino::convert_attachment( $id, 'ability' );
			}
		);
		$out = array(
			'mode'    => $bulk ? 'bulk' : 'selected',
			'summary' => self::tally( $results ),
			'results' => $results,
		);
		if ( $left ) {
			$out['not_reached'] = $left;
		}
		if ( $bulk ) {
			$out['remaining'] = count( Compressino::uncompressed_ids() );
		}
		return $out;
	}

	public static function restore_images( $input ) {
		list( $results, $left ) = self::run( $input['ids'], array( 'Compressino', 'restore_attachment' ) );
		$out = array(
			'summary' => self::tally( $results ),
			'results' => $results,
		);
		if ( $left ) {
			$out['not_reached'] = $left;
		}
		return $out;
	}

	public static function delete_originals( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new WP_Error( 'compressino_confirm', 'Pass confirm: true after the user agreed.' );
		}
		$result = Compressino::delete_originals();
		return array( 'deleted' => $result['deleted'], 'freed' => size_format( $result['bytes'], 1 ) );
	}
}
