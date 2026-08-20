<?php
/**
 * The shared credential groups and their canonical fields.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definitions for everything the family layer is willing to share.
 *
 * Every group here was derived from what the plugins actually store and read —
 * not from what looks shareable by name. Two traps drove the shape of this
 * list, and both are load-bearing:
 *
 *   1. Identical field names, different services. BLT Documents and the image
 *      optimizers all store `worker_url` + `worker_secret`, but they address
 *      two completely different Cloudflare Workers. A single canonical pair
 *      would point document delivery at the image Worker the moment a local
 *      field went blank. The 'image_worker' group is therefore scoped to the
 *      optimizers, and nothing from BLT Documents is shared at all.
 *   2. Same vendor, different API. BLT Events' Google key is a
 *      referrer-restricted browser key for Maps JS; BLT Tube's is an
 *      IP-restricted server key for YouTube Data v3. They can never be the
 *      same string, so the Google group gives each API its own field and never
 *      cross-feeds them. Sharing here unifies the *screen*, not the key.
 *
 * Field names are canonical, not per-plugin: each plugin keeps its own option
 * keys and maps them onto these when it reads a fallback. See
 * includes/blt-family/README.md for what was deliberately left out and why.
 */
class BLT_Family_Groups {

	/**
	 * All group definitions.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$groups = array(
			'github'       => array(
				'label'       => __( 'GitHub', 'blt-family' ),
				'description' => __( 'A personal access token used when BLT plugins check for updates. Optional — a token raises the GitHub API rate limit from 60 to 5,000 requests an hour, which matters on a site running several BLT plugins. Read-only access is enough.', 'blt-family' ),
				'help_url'    => 'https://github.com/settings/tokens',
				'fields'      => array(
					'token' => array(
						'label'  => __( 'Personal access token', 'blt-family' ),
						'secret' => true,
						'help'   => __( 'Where a plugin also supports a wp-config.php constant, the constant still wins over this.', 'blt-family' ),
					),
				),
			),
			'cloudflare'   => array(
				'label'       => __( 'Cloudflare', 'blt-family' ),
				'description' => __( 'Which Cloudflare account and zone this site belongs to, and a scoped API token for plugins that deploy rules or lists.', 'blt-family' ),
				'help_url'    => 'https://dash.cloudflare.com/profile/api-tokens',
				'fields'      => array(
					'account_id' => array(
						'label'  => __( 'Account ID', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'The 32-character ID in the Cloudflare dashboard sidebar. Note this is the account that owns the resources a plugin addresses, which is not always the account that owns this site\'s zone.', 'blt-family' ),
					),
					'zone_host'  => array(
						'label'  => __( 'Zone hostname', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'The zone this site is served from, e.g. example.com.', 'blt-family' ),
					),
					'api_token'  => array(
						'label'  => __( 'API token', 'blt-family' ),
						'secret' => true,
						'help'   => __( 'A scoped API token — never your global API key.', 'blt-family' ),
					),
				),
			),
			'r2'           => array(
				'label'       => __( 'R2 / S3 storage', 'blt-family' ),
				'description' => __( 'S3-compatible object storage for media offloading. Cloudflare R2, AWS S3, or any S3-compatible endpoint.', 'blt-family' ),
				'help_url'    => 'https://developers.cloudflare.com/r2/api/tokens/',
				'fields'      => array(
					'endpoint'          => array(
						'label'  => __( 'S3 endpoint', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'For R2: https://ACCOUNT_ID.r2.cloudflarestorage.com', 'blt-family' ),
					),
					'region'            => array(
						'label'  => __( 'Region', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'R2 uses "auto". S3 uses the bucket\'s region.', 'blt-family' ),
					),
					'bucket'            => array(
						'label'  => __( 'Default bucket', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'A plugin that needs its own bucket overrides this locally.', 'blt-family' ),
					),
					'access_key_id'     => array(
						'label'  => __( 'Access key ID', 'blt-family' ),
						'secret' => false,
						'help'   => '',
					),
					'secret_access_key' => array(
						'label'  => __( 'Secret access key', 'blt-family' ),
						'secret' => true,
						'help'   => '',
					),
					'public_url'        => array(
						'label'  => __( 'Public base URL', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'The CDN or custom domain objects are served from, if any.', 'blt-family' ),
					),
				),
			),
			'google'       => array(
				'label'       => __( 'Google', 'blt-family' ),
				'description' => __( 'Google API credentials. Keys are per-API on purpose: a Maps browser key and a YouTube Data server key carry incompatible restrictions, so they are never shared with each other.', 'blt-family' ),
				'help_url'    => 'https://console.cloud.google.com/apis/credentials',
				'fields'      => array(
					'maps_api_key'        => array(
						'label'  => __( 'Maps API key', 'blt-family' ),
						'secret' => true,
						'help'   => __( 'Maps JavaScript API + Places API. Printed into page HTML, so restrict it by HTTP referrer.', 'blt-family' ),
					),
					'youtube_api_key'     => array(
						'label'  => __( 'YouTube Data API key', 'blt-family' ),
						'secret' => true,
						'help'   => __( 'YouTube Data API v3. Used server-side, so restrict it by IP, not referrer.', 'blt-family' ),
					),
					'oauth_client_id'     => array(
						'label'  => __( 'OAuth client ID', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'For flows that sign a user in with Google.', 'blt-family' ),
					),
					'oauth_client_secret' => array(
						'label'  => __( 'OAuth client secret', 'blt-family' ),
						'secret' => true,
						'help'   => '',
					),
				),
			),
			'microsoft'    => array(
				'label'       => __( 'Microsoft Entra ID', 'blt-family' ),
				'description' => __( 'The Entra ID (Azure AD) directory this site authenticates against. The tenant is one value per site and is the field worth sharing hardest; the app registration is only shared when one app really does cover both uses.', 'blt-family' ),
				'help_url'    => 'https://entra.microsoft.com/',
				'fields'      => array(
					'tenant_id'     => array(
						'label'  => __( 'Directory (tenant) ID', 'blt-family' ),
						'secret' => false,
						'help'   => '',
					),
					'client_id'     => array(
						'label'  => __( 'Application (client) ID', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'Only share this when one app registration holds every scope the consuming plugins need — a delegated sign-in app has no OnlineMeetings permission, and an app-only meetings app has no sign-in redirect URI.', 'blt-family' ),
					),
					'client_secret' => array(
						'label'  => __( 'Client secret', 'blt-family' ),
						'secret' => true,
						'help'   => '',
					),
				),
			),
			'stripe'       => array(
				'label'       => __( 'Stripe', 'blt-family' ),
				'description' => __( 'The Stripe account this site charges against.', 'blt-family' ),
				'help_url'    => 'https://dashboard.stripe.com/apikeys',
				'fields'      => array(
					'publishable_key' => array(
						'label'  => __( 'Publishable key', 'blt-family' ),
						'secret' => false,
						'help'   => __( 'Starts with pk_. This value is sent to the browser.', 'blt-family' ),
					),
					'secret_key'      => array(
						'label'  => __( 'Secret key', 'blt-family' ),
						'secret' => true,
						'help'   => __( 'Starts with sk_ or rk_. Never leaves the server.', 'blt-family' ),
					),
				),
			),
			'surecart'     => array(
				'label'       => __( 'SureCart', 'blt-family' ),
				'description' => __( 'The SureCart store this site sells through. Webhook signing secrets are endpoint-specific, not account-specific, so they are never shared.', 'blt-family' ),
				'help_url'    => 'https://app.surecart.com/',
				'fields'      => array(
					'api_token' => array(
						'label'  => __( 'API token', 'blt-family' ),
						'secret' => true,
						'help'   => '',
					),
				),
			),
			'image_worker' => array(
				'label'       => __( 'Image optimization Worker', 'blt-family' ),
				'description' => __( 'The S-FX.com-hosted Cloudflare Worker that compresses and converts images. This is only the image Worker — it is deliberately not shared with any other Worker a BLT plugin talks to.', 'blt-family' ),
				'help_url'    => '',
				'fields'      => array(
					'worker_url'    => array(
						'label'  => __( 'Worker URL', 'blt-family' ),
						'secret' => false,
						'help'   => '',
					),
					'worker_secret' => array(
						'label'  => __( 'Worker shared secret', 'blt-family' ),
						'secret' => true,
						'help'   => '',
					),
				),
			),
		);

		/**
		 * Filter the shared credential groups.
		 *
		 * Adding a group here makes it storable and renders it on the family
		 * screen; it does not make any plugin read it. A plugin opts in to a
		 * group by naming it in blt_family_register() and by calling
		 * BLT_Family::get() at its own accessor.
		 *
		 * @param array<string,array> $groups Group definitions.
		 */
		return apply_filters( 'blt_family_groups', $groups );
	}

	/**
	 * One group definition, or null when unknown.
	 *
	 * @param string $group Group key.
	 * @return array|null
	 */
	public static function get( $group ) {
		$all = self::all();

		return isset( $all[ $group ] ) ? $all[ $group ] : null;
	}

	/**
	 * Whether a field exists in a group.
	 *
	 * @param string $group Group key.
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function has_field( $group, $field ) {
		$definition = self::get( $group );

		return null !== $definition && isset( $definition['fields'][ $field ] );
	}

	/**
	 * Whether a field must be encrypted at rest and masked in the UI.
	 *
	 * Unknown fields are treated as secret: failing closed means a typo gets a
	 * value encrypted rather than left in the clear.
	 *
	 * @param string $group Group key.
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function is_secret( $group, $field ) {
		$definition = self::get( $group );

		if ( null === $definition || ! isset( $definition['fields'][ $field ] ) ) {
			return true;
		}

		return ! empty( $definition['fields'][ $field ]['secret'] );
	}

	/**
	 * Reject a value that is obviously the wrong kind of credential.
	 *
	 * Narrow on purpose — this catches the one mistake with a real blast
	 * radius, a Stripe secret key pasted into the publishable field, which
	 * would then be localized into the page for every anonymous visitor.
	 *
	 * @param string $group Group key.
	 * @param string $field Field key.
	 * @param string $value Submitted plaintext.
	 * @return string|WP_Error The value, or an error explaining the rejection.
	 */
	public static function validate( $group, $field, $value ) {
		if ( 'stripe' === $group && '' !== $value ) {
			if ( 'publishable_key' === $field && 0 === strpos( $value, 'sk_' ) ) {
				return new WP_Error(
					'blt_family_secret_in_public_field',
					__( 'That looks like a Stripe secret key (sk_). The publishable key is sent to the browser — paste the pk_ key here.', 'blt-family' )
				);
			}

			if ( 'secret_key' === $field && 0 === strpos( $value, 'pk_' ) ) {
				return new WP_Error(
					'blt_family_public_in_secret_field',
					__( 'That looks like a Stripe publishable key (pk_). Paste the secret key (sk_ or rk_) here.', 'blt-family' )
				);
			}
		}

		return $value;
	}
}
