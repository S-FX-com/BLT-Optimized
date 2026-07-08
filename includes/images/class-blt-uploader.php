<?php
/**
 * Sends images to the Cloudflare Worker and writes the optimized result.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles a single attachment: dispatch each size to the Worker, write the
 * resulting .webp to disk, and record outcomes in the log.
 */
class Uploader {

	/**
	 * Mime types we never send to the Worker.
	 *
	 * @return array<string>
	 */
	private static function skipped_mimes() {
		return array( 'image/svg+xml', 'image/svg' );
	}

	/**
	 * Optimize all eligible sizes for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{processed:int,skipped:int,errors:int} Summary counts.
	 */
	public function optimize_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$summary       = array(
			'processed' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);

		$mime = get_post_mime_type( $attachment_id );

		if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
			Logger::set_status( $attachment_id, 'full', 'skipped', 'Not an image.' );
			$summary['skipped']++;
			return $summary;
		}

		if ( in_array( $mime, self::skipped_mimes(), true ) ) {
			Logger::set_status( $attachment_id, 'full', 'skipped', 'SVG images are not optimized.' );
			$summary['skipped']++;
			return $summary;
		}

		if ( 'image/gif' === $mime && ! Settings::get( 'convert_gifs' ) ) {
			Logger::set_status( $attachment_id, 'full', 'skipped', 'GIF conversion disabled.' );
			$summary['skipped']++;
			return $summary;
		}

		$targets = $this->collect_targets( $attachment_id );

		if ( empty( $targets ) ) {
			Logger::set_status( $attachment_id, 'full', 'skipped', 'No source files found on disk.' );
			$summary['skipped']++;
			return $summary;
		}

		$meta_updater = new Attachment_Meta();

		foreach ( $targets as $size_name => $target ) {
			Logger::set_status( $attachment_id, $size_name, 'processing' );

			$result = $this->optimize_file( $target['path'], $target['url'] );

			if ( is_wp_error( $result ) ) {
				Logger::set_status( $attachment_id, $size_name, 'error', $result->get_error_message() );
				$summary['errors']++;
				continue;
			}

			$webp_path = $this->webp_path_for( $target['path'] );
			$written   = $this->write_file( $webp_path, $result['body'] );

			if ( is_wp_error( $written ) ) {
				Logger::set_status( $attachment_id, $size_name, 'error', $written->get_error_message() );
				$summary['errors']++;
				continue;
			}

			$original_size  = (int) ( @filesize( $target['path'] ) ?: 0 );
			$optimized_size = strlen( $result['body'] );
			$savings_pct    = $original_size > 0
				? round( ( ( $original_size - $optimized_size ) / $original_size ) * 100, 2 )
				: 0;

			Logger::upsert(
				$attachment_id,
				$size_name,
				array(
					'original_file'  => $target['path'],
					'optimized_file' => $webp_path,
					'original_size'  => $original_size,
					'optimized_size' => $optimized_size,
					'savings_pct'    => $savings_pct,
					'status'         => 'done',
					'processed_at'   => current_time( 'mysql' ),
					'error_message'  => '',
				)
			);

			$meta_updater->register_optimized_size( $attachment_id, $size_name, $webp_path, $optimized_size );

			if ( ! Settings::get( 'keep_originals' ) && $webp_path !== $target['path'] ) {
				wp_delete_file( $target['path'] );
			}

			$summary['processed']++;
		}

		// Persist accumulated metadata changes once per attachment.
		$meta_updater->flush( $attachment_id );

		return $summary;
	}

	/**
	 * Build the list of size => {path,url} targets for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,array{path:string,url:string}>
	 */
	private function collect_targets( $attachment_id ) {
		$targets       = array();
		$full_path     = get_attached_file( $attachment_id );
		$upload_dir    = wp_get_upload_dir();
		$only_full     = ! Settings::get( 'optimize_existing_sizes' );

		if ( $full_path && file_exists( $full_path ) ) {
			$targets['full'] = array(
				'path' => $full_path,
				'url'  => wp_get_attachment_url( $attachment_id ),
			);
		}

		if ( $only_full ) {
			return $targets;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) || empty( $full_path ) ) {
			return $targets;
		}

		$base_dir = trailingslashit( dirname( $full_path ) );
		$base_url = trailingslashit( dirname( wp_get_attachment_url( $attachment_id ) ) );

		foreach ( $meta['sizes'] as $size_name => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}

			$path = $base_dir . $size_data['file'];

			if ( ! file_exists( $path ) ) {
				continue;
			}

			$targets[ $size_name ] = array(
				'path' => $path,
				'url'  => $base_url . $size_data['file'],
			);
		}

		return $targets;
	}

	/**
	 * Send one file's public URL to the Worker and return the optimized binary.
	 *
	 * @param string $path Local file path (for fallback binary upload).
	 * @param string $url  Public URL the Worker can fetch.
	 * @return array{body:string,content_type:string}|\WP_Error
	 */
	private function optimize_file( $path, $url ) {
		$worker_url = Settings::get( 'worker_url' );
		$secret     = Settings::get( 'worker_secret' );

		if ( '' === $worker_url || '' === $secret ) {
			return new \WP_Error( 'blt_not_configured', __( 'Worker URL or secret is not configured.', 'blt-image-optimizer' ) );
		}

		if ( empty( $url ) ) {
			return new \WP_Error( 'blt_no_url', __( 'No public URL available for this image.', 'blt-image-optimizer' ) );
		}

		$payload = array(
			'image_url' => $url,
			'quality'   => (int) Settings::get( 'webp_quality', 82 ),
			'format'    => 'webp',
			'max_width' => (int) Settings::get( 'max_width', 2400 ),
		);

		$response = wp_remote_post(
			$worker_url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
					'Accept'        => 'image/webp',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			$detail = $this->extract_error_detail( $body );
			/* translators: 1: HTTP status code, 2: error detail. */
			return new \WP_Error(
				'blt_worker_http_' . $code,
				sprintf( __( 'Worker returned HTTP %1$d: %2$s', 'blt-image-optimizer' ), (int) $code, $detail )
			);
		}

		if ( '' === $body ) {
			return new \WP_Error( 'blt_empty_body', __( 'Worker returned an empty response.', 'blt-image-optimizer' ) );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( $content_type && false === strpos( $content_type, 'image/' ) ) {
			$detail = $this->extract_error_detail( $body );
			return new \WP_Error( 'blt_bad_content_type', $detail ? $detail : __( 'Worker did not return an image.', 'blt-image-optimizer' ) );
		}

		return array(
			'body'         => $body,
			'content_type' => $content_type ? $content_type : 'image/webp',
		);
	}

	/**
	 * Pull a human-readable message out of a JSON error body.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private function extract_error_detail( $body ) {
		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) && ! empty( $decoded['error'] ) ) {
			return sanitize_text_field( (string) $decoded['error'] );
		}

		return sanitize_text_field( substr( (string) $body, 0, 200 ) );
	}

	/**
	 * Compute the .webp output path for a source file.
	 *
	 * @param string $source_path Source image path.
	 * @return string
	 */
	private function webp_path_for( $source_path ) {
		$ext = pathinfo( $source_path, PATHINFO_EXTENSION );

		if ( '' === $ext ) {
			return $source_path . '.webp';
		}

		return substr( $source_path, 0, -strlen( $ext ) ) . 'webp';
	}

	/**
	 * Write the optimized binary to disk using WP_Filesystem.
	 *
	 * @param string $path  Destination path.
	 * @param string $bytes File contents.
	 * @return true|\WP_Error
	 */
	private function write_file( $path, $bytes ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		$dir = dirname( $path );

		if ( ! wp_is_writable( $dir ) ) {
			/* translators: %s: directory path. */
			return new \WP_Error( 'blt_not_writable', sprintf( __( 'Directory is not writable: %s', 'blt-image-optimizer' ), $dir ) );
		}

		$ok = $wp_filesystem
			? $wp_filesystem->put_contents( $path, $bytes, FS_CHMOD_FILE )
			: ( false !== file_put_contents( $path, $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( ! $ok ) {
			return new \WP_Error( 'blt_write_failed', __( 'Failed to write the optimized file to disk.', 'blt-image-optimizer' ) );
		}

		return true;
	}

	/**
	 * Lightweight connectivity test against the Worker.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test_connection() {
		$worker_url = Settings::get( 'worker_url' );
		$secret     = Settings::get( 'worker_secret' );

		if ( '' === $worker_url || '' === $secret ) {
			return array(
				'ok'      => false,
				'message' => __( 'Worker URL and secret must both be set.', 'blt-image-optimizer' ),
			);
		}

		$response = wp_remote_get(
			trailingslashit( $worker_url ) . 'health',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array(
				'ok'      => false,
				/* translators: %d: HTTP status code. */
				'message' => sprintf( __( 'Worker health check returned HTTP %d.', 'blt-image-optimizer' ), $code ),
			);
		}

		// Worker reports whether cf.image transforms are available (zone vs workers.dev).
		if ( is_array( $body ) && isset( $body['cf_image'] ) && false === $body['cf_image'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Connected, but cf.image transforms are unavailable. Deploy the Worker to a Cloudflare zone route, not workers.dev.', 'blt-image-optimizer' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connection OK. Worker is reachable and cf.image transforms are available.', 'blt-image-optimizer' ),
		);
	}
}
