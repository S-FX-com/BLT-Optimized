<?php
/**
 * Main orchestrator — wires WordPress hooks for auto-optimize and rewriting.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that registers the plugin's runtime hooks.
 */
class Core {

	/**
	 * Singleton instance.
	 *
	 * @var Core|null
	 */
	private static $instance = null;

	/**
	 * Queue manager.
	 *
	 * @var Queue|null
	 */
	private $queue = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Ensure the log table exists even if the plugin was updated by file copy.
		add_action( 'init', array( $this, 'maybe_upgrade_db' ) );

		// Bulk queue (Action Scheduler) — must register its handler early.
		$this->queue = new Queue();
		$this->queue->register_hooks();

		// Auto-optimize new uploads.
		if ( Settings::get( 'auto_optimize' ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 20, 2 );
		}

		// Front-end URL rewriting to serve .webp where present.
		if ( ! is_admin() ) {
			add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_image_src' ), 10, 4 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_srcset' ), 10, 5 );
			add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 10, 2 );

			// Bricks Builder renders some images outside core filters.
			add_filter( 'bricks/image/attributes', array( $this, 'rewrite_bricks_attributes' ), 10, 1 );

			if ( Settings::get( 'rewrite_content' ) ) {
				add_filter( 'the_content', array( $this, 'rewrite_content_images' ), 999 );
			}
		}
	}

	/**
	 * Run a DB upgrade if the bundled schema version moved.
	 *
	 * @return void
	 */
	public function maybe_upgrade_db() {
		if ( get_option( Logger::DB_VERSION_OPTION ) !== Logger::DB_VERSION ) {
			Logger::install();
		}
	}

	/**
	 * Auto-optimize newly uploaded images.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata (we update meta separately).
	 */
	public function on_generate_metadata( $metadata, $attachment_id ) {
		if ( ! Settings::is_configured() ) {
			return $metadata;
		}

		// Defer the network round-trip to a scheduled action so uploads stay fast.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'blt_optimizer_process_single',
				array( 'attachment_id' => (int) $attachment_id ),
				'blt-optimizer'
			);
		} else {
			// No Action Scheduler: optimize inline as a fallback.
			( new Uploader() )->optimize_attachment( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Rewrite wp_get_attachment_image_src to a .webp when available.
	 *
	 * @param array|false  $image         Array of image data, or false.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested size.
	 * @param bool         $icon          Whether the image is an icon.
	 * @return array|false
	 */
	public function rewrite_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$webp = Attachment_Meta::webp_url_for( $image[0] );

		if ( $webp ) {
			$image[0] = $webp;
		}

		return $image;
	}

	/**
	 * Rewrite srcset entries to .webp where present.
	 *
	 * @param array  $sources       Srcset sources keyed by width/density.
	 * @param array  $size_array    Width/height array.
	 * @param string $image_src     The 'src' of the image.
	 * @param array  $image_meta    Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function rewrite_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $key => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$webp = Attachment_Meta::webp_url_for( $source['url'] );

			if ( $webp ) {
				$sources[ $key ]['url'] = $webp;
			}
		}

		return $sources;
	}

	/**
	 * Rewrite a single attachment URL to .webp when available.
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function rewrite_attachment_url( $url, $attachment_id ) {
		$webp = Attachment_Meta::webp_url_for( $url );
		return $webp ? $webp : $url;
	}

	/**
	 * Rewrite Bricks Builder image attributes (src/srcset).
	 *
	 * @param array $attributes Image tag attributes.
	 * @return array
	 */
	public function rewrite_bricks_attributes( $attributes ) {
		if ( ! is_array( $attributes ) ) {
			return $attributes;
		}

		if ( ! empty( $attributes['src'] ) ) {
			$webp = Attachment_Meta::webp_url_for( $attributes['src'] );
			if ( $webp ) {
				$attributes['src'] = $webp;
			}
		}

		if ( ! empty( $attributes['srcset'] ) ) {
			$attributes['srcset'] = $this->rewrite_srcset_string( $attributes['srcset'] );
		}

		return $attributes;
	}

	/**
	 * Fallback content filter: rewrite hardcoded <img> src/srcset to .webp.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function rewrite_content_images( $content ) {
		if ( false === strpos( $content, '<img' ) ) {
			return $content;
		}

		$content = preg_replace_callback(
			'/\b(src|srcset)=("|\')(.*?)\2/i',
			function ( $matches ) {
				$attr  = strtolower( $matches[1] );
				$quote = $matches[2];
				$value = $matches[3];

				if ( 'srcset' === $attr ) {
					$value = $this->rewrite_srcset_string( $value );
				} else {
					$webp  = Attachment_Meta::webp_url_for( $value );
					$value = $webp ? $webp : $value;
				}

				return $attr . '=' . $quote . $value . $quote;
			},
			$content
		);

		return $content;
	}

	/**
	 * Rewrite each URL in a srcset attribute string.
	 *
	 * @param string $srcset Srcset attribute value.
	 * @return string
	 */
	private function rewrite_srcset_string( $srcset ) {
		$parts = explode( ',', $srcset );

		foreach ( $parts as $i => $part ) {
			$part    = trim( $part );
			$segments = preg_split( '/\s+/', $part, 2 );
			$url     = $segments[0];
			$descr   = isset( $segments[1] ) ? ' ' . $segments[1] : '';

			$webp = Attachment_Meta::webp_url_for( $url );

			$parts[ $i ] = ( $webp ? $webp : $url ) . $descr;
		}

		return implode( ', ', $parts );
	}
}
