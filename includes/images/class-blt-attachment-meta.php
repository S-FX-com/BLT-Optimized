<?php
/**
 * Updates WordPress attachment metadata after optimization.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks which sizes were converted to .webp and records that mapping in
 * dedicated postmeta so URL-rewriting filters can swap extensions safely.
 *
 * We intentionally do NOT mutate the core `_wp_attachment_metadata` `file`
 * fields away from the originals — keeping the original references intact
 * preserves the ability to revert and avoids breaking other plugins that
 * read the canonical metadata. Instead we store a parallel webp map and let
 * the rewrite filters serve .webp where a file exists on disk.
 */
class Attachment_Meta {

	/**
	 * Postmeta key holding the size => webp-filename map.
	 */
	const WEBP_MAP_META = '_blt_webp_sizes';

	/**
	 * Postmeta flag marking an attachment as optimized.
	 */
	const OPTIMIZED_META = '_blt_optimized';

	/**
	 * Accumulated changes for the current attachment, keyed by size name.
	 *
	 * @var array<string,array{file:string,filesize:int}>
	 */
	private $pending = array();

	/**
	 * Record that a size was optimized; buffered until flush().
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name     Size name.
	 * @param string $webp_path     Absolute path to the .webp file.
	 * @param int    $filesize      Optimized byte size.
	 * @return void
	 */
	public function register_optimized_size( $attachment_id, $size_name, $webp_path, $filesize ) {
		$this->pending[ $size_name ] = array(
			'file'     => basename( $webp_path ),
			'filesize' => (int) $filesize,
		);
	}

	/**
	 * Persist buffered changes to postmeta for the attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function flush( $attachment_id ) {
		if ( empty( $this->pending ) ) {
			return;
		}

		$attachment_id = absint( $attachment_id );
		$existing      = get_post_meta( $attachment_id, self::WEBP_MAP_META, true );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$map = array_merge( $existing, $this->pending );

		update_post_meta( $attachment_id, self::WEBP_MAP_META, $map );
		update_post_meta( $attachment_id, self::OPTIMIZED_META, current_time( 'mysql' ) );

		$this->pending = array();
	}

	/**
	 * Whether an attachment has any optimized sizes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_optimized( $attachment_id ) {
		return (bool) get_post_meta( absint( $attachment_id ), self::OPTIMIZED_META, true );
	}

	/**
	 * Get the webp size map for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,array{file:string,filesize:int}>
	 */
	public static function webp_map( $attachment_id ) {
		$map = get_post_meta( absint( $attachment_id ), self::WEBP_MAP_META, true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Resolve the optimized .webp URL for a given original image URL, if one
	 * exists on disk in the same directory.
	 *
	 * @param string $original_url Original image URL.
	 * @return string|false Rewritten URL or false when no webp is present.
	 */
	public static function webp_url_for( $original_url ) {
		if ( ! is_string( $original_url ) || '' === $original_url ) {
			return false;
		}

		// Already webp.
		if ( preg_match( '/\.webp(\?.*)?$/i', $original_url ) ) {
			return false;
		}

		if ( ! preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $original_url ) ) {
			return false;
		}

		$webp_url = preg_replace( '/\.(jpe?g|png|gif)(\?.*)?$/i', '.webp$2', $original_url );

		if ( null === $webp_url || $webp_url === $original_url ) {
			return false;
		}

		$path = self::url_to_path( $webp_url );

		if ( false === $path || ! file_exists( $path ) ) {
			return false;
		}

		return $webp_url;
	}

	/**
	 * Map an uploads URL to a local filesystem path.
	 *
	 * @param string $url URL to map.
	 * @return string|false
	 */
	public static function url_to_path( $url ) {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		// Strip any query string before path mapping.
		$url = preg_replace( '/\?.*$/', '', $url );

		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			return $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		}

		// Protocol-relative / scheme mismatch fallback.
		$relative = wp_make_link_relative( $url );
		$base_rel = wp_make_link_relative( $uploads['baseurl'] );

		if ( $base_rel && 0 === strpos( $relative, $base_rel ) ) {
			return $uploads['basedir'] . substr( $relative, strlen( $base_rel ) );
		}

		return false;
	}

	/**
	 * Remove optimization metadata for an attachment (used on revert).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function clear( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		delete_post_meta( $attachment_id, self::WEBP_MAP_META );
		delete_post_meta( $attachment_id, self::OPTIMIZED_META );
	}
}
