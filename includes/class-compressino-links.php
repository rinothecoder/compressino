<?php
/** Keeps links to an image working when Compressino changes its file (.jpg to .webp and back). @package Compressino */
defined( 'ABSPATH' ) || exit;

final class Compressino_Links {
	/** Files of images about to be restored, keyed by attachment ID. */
	private static $before = array();

	public static function init() {
		add_action( 'compressino_converted', array( __CLASS__, 'converted' ), 10, 2 );
		add_action( 'compressino_before_restore', array( __CLASS__, 'before_restore' ) );
		add_action( 'compressino_restored', array( __CLASS__, 'restored' ) );
	}

	/** After a compress: point links to the old PNG or JPG at the new WebP. Fresh uploads have no links yet. */
	public static function converted( $attachment_id, $record ) {
		if ( in_array( $record['source'] ?? '', array( 'server', 'browser' ), true ) || empty( $record['previous']['file'] ) ) {
			return;
		}
		self::run( $attachment_id, self::files( $record['previous']['file'], $record['previous']['metadata'] ?? array() ) );
	}

	public static function before_restore( $attachment_id ) {
		self::$before[ $attachment_id ] = self::state( $attachment_id );
	}

	/** After a restore: point links to the WebP back at the original. */
	public static function restored( $attachment_id ) {
		if ( empty( self::$before[ $attachment_id ] ) ) {
			return;
		}
		$old = self::$before[ $attachment_id ];
		unset( self::$before[ $attachment_id ] );
		self::run( $attachment_id, $old );
	}

	private static function run( $attachment_id, $old ) {
		$count = self::update( self::map( $old, self::state( $attachment_id ) ) );
		$flag  = get_post_meta( $attachment_id, Compressino::META, true );
		if ( is_array( $flag ) ) {
			$flag['links_updated'] = $count;
			update_post_meta( $attachment_id, Compressino::META, $flag );
		}
	}

	private static function state( $attachment_id ) {
		return self::files( get_post_meta( $attachment_id, '_wp_attached_file', true ), wp_get_attachment_metadata( $attachment_id ) );
	}

	/** Main file, full original and every size, as paths inside the uploads folder. */
	private static function files( $file, $meta ) {
		if ( ! $file || '/' === $file[0] ) {
			return array();
		}
		$dir  = dirname( $file );
		$dir  = '.' === $dir ? '' : $dir . '/';
		$meta = is_array( $meta ) ? $meta : array();
		$out  = array( 'main' => $file );
		if ( ! empty( $meta['original_image'] ) ) {
			$out['original'] = $dir . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$out[ 'size:' . $name ] = $dir . $size['file'];
			}
		}
		return $out;
	}

	/** Old path => new path. A size or original that no longer exists points at the main file. */
	private static function map( $old, $new ) {
		$map = array();
		if ( empty( $new['main'] ) ) {
			return $map;
		}
		foreach ( $old as $key => $path ) {
			$target = $new[ $key ] ?? $new['main'];
			if ( $path !== $target && ! isset( $map[ $path ] ) ) {
				$map[ $path ] = $target;
			}
		}
		return $map;
	}

	/**
	 * Swap the old file names for the new ones in posts, meta and options. Matching starts at the uploads
	 * folder name, so full URLs, relative paths, srcset lists and pagebuilder JSON are all caught.
	 * Serialized data is opened up first so its lengths stay correct.
	 *
	 * @param array $map Old path => new path, inside the uploads folder.
	 * @return int Number of rows changed.
	 */
	public static function update( $map ) {
		global $wpdb;
		if ( ! $map ) {
			return 0;
		}
		$folder = wp_basename( wp_get_upload_dir()['basedir'] ) . '/';
		$pairs  = array();
		$likes  = array();
		foreach ( $map as $from => $to ) {
			$pairs[ $folder . $from ] = $folder . $to;
			$pairs[ str_replace( '/', '\/', $folder . $from ) ] = str_replace( '/', '\/', $folder . $to );
			$stem = $folder . preg_replace( '/(-scaled|-\d+x\d+)?\.[a-z0-9]+$/i', '', $from );
			$likes[ $stem ] = true;
			$likes[ str_replace( '/', '\/', $stem ) ] = true;
		}
		$where = function ( $column ) use ( $wpdb, $likes ) {
			$parts = array();
			foreach ( array_keys( $likes ) as $like ) {
				$parts[] = $wpdb->prepare( $column . ' LIKE %s', '%' . $wpdb->esc_like( $like ) . '%' );
			}
			return '(' . implode( ' OR ', $parts ) . ')';
		};
		$count = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- the parts are prepared above.
		$rows = $wpdb->get_results( 'SELECT ID, post_content, post_excerpt FROM ' . $wpdb->posts . ' WHERE ' . $wpdb->prepare( 'post_type <> %s', 'revision' ) . ' AND (' . $where( 'post_content' ) . ' OR ' . $where( 'post_excerpt' ) . ')' );
		foreach ( $rows as $row ) {
			$data    = array();
			$content = self::swap( $row->post_content, $pairs );
			$excerpt = self::swap( $row->post_excerpt, $pairs );
			if ( null !== $content ) {
				$data['post_content'] = $content;
			}
			if ( null !== $excerpt ) {
				$data['post_excerpt'] = $excerpt;
			}
			if ( $data ) {
				$wpdb->update( $wpdb->posts, $data, array( 'ID' => $row->ID ) );
				clean_post_cache( (int) $row->ID );
				++$count;
			}
		}

		$keys = $wpdb->prepare( 'meta_key NOT IN (%s, %s, %s, %s)', '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', Compressino::META );
		foreach ( array( 'post' => $wpdb->postmeta, 'term' => $wpdb->termmeta ) as $type => $table ) {
			$rows = $wpdb->get_results( 'SELECT meta_id, ' . $type . '_id AS object_id, meta_value FROM ' . $table . ' WHERE ' . $keys . ' AND ' . $where( 'meta_value' ) );
			foreach ( $rows as $row ) {
				$value = self::swap( $row->meta_value, $pairs );
				if ( null !== $value ) {
					$wpdb->update( $table, array( 'meta_value' => $value ), array( 'meta_id' => $row->meta_id ) );
					wp_cache_delete( (int) $row->object_id, $type . '_meta' );
					++$count;
				}
			}
		}

		$rows    = $wpdb->get_results( 'SELECT option_id, option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name NOT LIKE ' . $wpdb->prepare( '%s', '%' . $wpdb->esc_like( '_transient' ) . '%' ) . ' AND ' . $where( 'option_value' ) );
		$options = false;
		foreach ( $rows as $row ) {
			$value = self::swap( $row->option_value, $pairs );
			if ( null !== $value ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_id' => $row->option_id ) );
				wp_cache_delete( $row->option_name, 'options' );
				$options = true;
				++$count;
			}
		}
		// phpcs:enable
		if ( $options ) {
			wp_cache_delete( 'alloptions', 'options' );
		}

		// Pagebuilders keep their own CSS files with image URLs in them. Let Elementor rebuild them.
		if ( $count && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		/**
		 * Fires after links to a compressed or restored image were updated.
		 *
		 * @param array $map   Old path => new path, inside the uploads folder.
		 * @param int   $count Number of rows changed.
		 */
		do_action( 'compressino_links_updated', $map, $count );
		return $count;
	}

	/** The value with the names swapped, or null when nothing changed or the data cannot be opened safely. */
	private static function swap( $value, $pairs ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
			if ( false === $data && 'b:0;' !== trim( $value ) ) {
				return null;
			}
			$ok  = true;
			$new = self::walk( $data, $pairs, $ok );
			if ( ! $ok ) {
				return null;
			}
			$out = serialize( $new ); // phpcs:ignore
			return serialize( $data ) === $out ? null : $out; // phpcs:ignore
		}
		$out = strtr( $value, $pairs );
		return $out === $value ? null : $out;
	}

	/** Swap inside arrays and plain objects. Sets $ok to false for objects of unknown classes, so the row is left alone. */
	private static function walk( $data, $pairs, &$ok ) {
		if ( is_string( $data ) ) {
			$swapped = self::swap( $data, $pairs );
			return null === $swapped ? $data : $swapped;
		}
		if ( $data instanceof __PHP_Incomplete_Class ) {
			$ok = false;
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $item ) {
				$data[ $key ] = self::walk( $item, $pairs, $ok );
			}
		} elseif ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $item ) {
				$data->$key = self::walk( $item, $pairs, $ok );
			}
		}
		return $data;
	}
}