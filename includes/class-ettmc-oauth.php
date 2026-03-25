<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles EVE SSO authentication for ETT Mineral Compare.
 *
 * SHARED CALLBACK
 * ───────────────
 * EVE developer applications only support one callback URL per app.
 * This plugin shares the same callback URL as ETT Reprocess Trading:
 *   admin-post.php?action=ett_eve_callback
 *
 * To avoid conflicts, this plugin hooks that action at priority 5 (before
 * RT's default priority 10). The handler checks whether the OAuth state
 * belongs to this plugin by looking for a transient prefixed 'ettmc_sso_state_'.
 * If found → process the callback fully and exit, so RT's handler never fires.
 * If not found → return immediately so RT's handler fires normally at priority 10.
 *
 * CHARACTER STORE
 * ───────────────
 * Characters are stored in user meta key 'ettmc_characters', completely
 * separate from RT's 'ett_rt_characters'. Existing RT-authenticated characters
 * are NOT reused; the user must connect via this plugin's own button.
 *
 * CREDENTIALS
 * ───────────
 * Client ID and encrypted client secret are read from ETT Price Helper's
 * options. The same EVE developer app is reused; no second app is needed.
 */
final class ETTMC_OAuth {

	const META_KEY = 'ettmc_characters';

	const SCOPE = 'esi-skills.read_skills.v1 esi-characters.read_standings.v1';

	/** The shared EVE SSO callback URL (same as ETT Reprocess Trading). */
	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=ett_eve_callback' );
	}

	public static function init(): void {
		// Priority 5 — runs before RT's handler at priority 10.
		// This plugin bails early (return without exit) if the state is not its own.
		add_action( 'admin_post_ett_eve_callback',        [ __CLASS__, 'handle_callback' ], 5 );
		add_action( 'admin_post_nopriv_ett_eve_callback', [ __CLASS__, 'handle_callback' ], 5 );
		add_action( 'admin_post_ettmc_disconnect_char',   [ __CLASS__, 'disconnect_character' ] );
	}

	// ── Credential helpers ────────────────────────────────────────────────

	private static function credentials(): array {
		if ( ! class_exists( 'ETT_Crypto' ) ) return [ '', '' ];
		$id  = (string) get_option( 'ett_sso_client_id', '' );
		$sec = ETT_Crypto::decrypt_triplet(
			(string) get_option( 'ett_sso_client_secret',     '' ),
			(string) get_option( 'ett_sso_client_secret_iv',  '' ),
			(string) get_option( 'ett_sso_client_secret_mac', '' )
		);
		return [ $id, $sec ];
	}

	public static function is_sso_configured(): bool {
		[ $id, $secret ] = self::credentials();
		return $id !== '' && $secret !== '';
	}

	// ── Token management ──────────────────────────────────────────────────

	public static function get_valid_access_token( int $user_id, string $character_id ) {
		$characters = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $characters ) || empty( $characters[ $character_id ] ) ) return false;

		$data          = $characters[ $character_id ];
		$access_token  = (string) ( $data['access_token']  ?? '' );
		$refresh_token = (string) ( $data['refresh_token'] ?? '' );
		$expires_at    = (int)    ( $data['expires_at']    ?? 0 );

		if ( $access_token !== '' && time() < ( $expires_at - 60 ) ) return $access_token;
		if ( $refresh_token === '' ) return false;

		[ $client_id, $client_secret ] = self::credentials();
		if ( $client_id === '' || $client_secret === '' ) return false;

		$response = wp_remote_post( 'https://login.eveonline.com/v2/oauth/token', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token ],
		] );

		if ( is_wp_error( $response ) ) return false;
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || empty( $body['access_token'] ) ) return false;

		$characters[ $character_id ]['access_token'] = (string) $body['access_token'];
		$characters[ $character_id ]['expires_at']   = time() + (int) ( $body['expires_in'] ?? 1200 );
		if ( ! empty( $body['refresh_token'] ) ) {
			$characters[ $character_id ]['refresh_token'] = (string) $body['refresh_token'];
		}
		update_user_meta( $user_id, self::META_KEY, $characters );
		return (string) $characters[ $character_id ]['access_token'];
	}

	// ── Character data ────────────────────────────────────────────────────

	/**
	 * Fetch and cache skill levels + standings for a character (1-hour cache).
	 *
	 * @return array{ skill_levels: array<int,int>, standings: array<int,float> }
	 */
	public static function get_character_data( string $character_id ): array {
		$empty     = [ 'skill_levels' => [], 'standings' => [] ];
		$cache_key = 'ettmc_char_data_v2_' . $character_id; // v2: corrected Connections formula
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) return $cached;

		$user_id = get_current_user_id();
		$token   = $user_id ? self::get_valid_access_token( $user_id, $character_id ) : false;
		if ( ! $token ) return $empty;

		$auth = [ 'Authorization' => 'Bearer ' . $token ];

		$skills    = wp_remote_get( "https://esi.evetech.net/latest/characters/{$character_id}/skills/",    [ 'headers' => $auth, 'timeout' => 15 ] );
		$standings = wp_remote_get( "https://esi.evetech.net/latest/characters/{$character_id}/standings/", [ 'headers' => $auth, 'timeout' => 15 ] );

		if ( is_wp_error( $skills ) || is_wp_error( $standings ) ) return $empty;

		$skills_data    = json_decode( wp_remote_retrieve_body( $skills ),    true );
		$standings_data = json_decode( wp_remote_retrieve_body( $standings ), true );

		if ( ! isset( $skills_data['skills'] ) || ! is_array( $standings_data ) ) return $empty;

		$skill_levels = [];
		foreach ( $skills_data['skills'] as $s ) {
			if ( isset( $s['skill_id'], $s['active_skill_level'] ) ) {
				$skill_levels[ (int) $s['skill_id'] ] = (int) $s['active_skill_level'];
			}
		}

		$standings_lookup = [];
		foreach ( $standings_data as $s ) {
			if ( isset( $s['from_id'], $s['standing'] ) ) {
				$standings_lookup[ (int) $s['from_id'] ] = (float) $s['standing'];
			}
		}

		$data = [ 'skill_levels' => $skill_levels, 'standings' => $standings_lookup ];
		set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Calculate broker fee and sales tax for a character at a named hub.
	 *
	 * Broker fee formula:
	 *   base 3% − 0.1%/BR level − 0.06%/ABR level − 0.03%/point effective standing
	 *   Floor: 0.1%
	 *
	 * Effective standing = higher of (faction standing modified by Connections/Diplomacy)
	 *                      and (corp standing, unmodified).
	 * Connections: positive faction standing × (1 + 0.04 × level)
	 * Diplomacy:   negative faction standing × (1 − 0.04 × level)
	 *
	 * Sales tax formula:
	 *   8% × (1 − 0.11 × Accounting level)
	 *
	 * @return array{ broker_fee: float, sales_tax: float }  Decimal fractions (e.g. 0.025 = 2.5%)
	 */
	public static function calc_fees( array $char_data, string $hub_name ): array {
		$skill_levels = $char_data['skill_levels'] ?? [];
		$standings    = $char_data['standings']    ?? [];

		$accounting = (int) ( $skill_levels[16622] ?? 0 );
		$br         = (int) ( $skill_levels[3446]  ?? 0 );

		// Broker fee formula (matches EVE client and original eve-mineral-compare):
		//   3% - (0.3% × Broker Relations) - (0.03% × base faction standing) - (0.02% × base corp standing)
		// Base standings are used directly — no Connections/Diplomacy/ABR adjustment needed here.
		$faction_base = 0.0;
		$corp_base    = 0.0;
		$entities     = self::hub_entities()[ $hub_name ] ?? null;
		if ( $entities ) {
			$faction_base = (float) ( $standings[ $entities['faction'] ] ?? 0.0 );
			$corp_base    = (float) ( $standings[ $entities['corp'] ]    ?? 0.0 );
			$faction_base = max( -10.0, min( 10.0, $faction_base ) );
			$corp_base    = max( -10.0, min( 10.0, $corp_base ) );
		}

		$broker_fee = 0.03
		            - 0.003  * $br
		            - 0.0003 * $faction_base
		            - 0.0002 * $corp_base;
		$broker_fee = round( max( 0.0, $broker_fee ), 6 );

		// Sales tax: 8% × (1 - 0.11 × Accounting)
		$sales_tax = round( max( 0.0, 0.08 * ( 1.0 - 0.11 * $accounting ) ), 6 );

		return [ 'broker_fee' => $broker_fee, 'sales_tax' => $sales_tax ];
	}

	/** NPC corp + faction entity IDs per hub for standings lookups. */
	public static function hub_entities(): array {
		return [
			'Jita'    => [ 'faction' => 500001, 'corp' => 1000035 ],
			'Amarr'   => [ 'faction' => 500003, 'corp' => 1000086 ],
			'Rens'    => [ 'faction' => 500002, 'corp' => 1000049 ],
			'Hek'     => [ 'faction' => 500002, 'corp' => 1000057 ],
			'Dodixie' => [ 'faction' => 500004, 'corp' => 1000120 ],
		];
	}

	// ── Connect button ────────────────────────────────────────────────────

	public static function connect_button(): string {
		[ $client_id ] = self::credentials();
		if ( $client_id === '' ) {
			return '<p class="description">EVE SSO client ID not configured — set it up in the ETT Price Helper settings tab.</p>';
		}

		$state      = wp_generate_password( 32, false, false );
		$host       = isset( $_SERVER['HTTP_HOST'] )   ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )  : '';
		$request    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$return_url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $request;

		// Prefix 'ettmc_sso_state_' distinguishes this from RT's 'ett_rt_state_' prefix.
		set_transient( 'ettmc_sso_state_' . $state, $return_url, 600 );

		$auth_url = add_query_arg( [
			'response_type' => 'code',
			'redirect_uri'  => self::callback_url(),
			'client_id'     => $client_id,
			'scope'         => self::SCOPE,
			'state'         => $state,
		], 'https://login.eveonline.com/v2/oauth/authorize' );

		return '<a href="' . esc_url( $auth_url ) . '" class="ettmc-sso-btn">Connect with EVE Online</a>';
	}

	// ── OAuth callback ────────────────────────────────────────────────────

	/**
	 * Handles admin_post_ett_eve_callback at priority 5.
	 *
	 * Returns without doing anything if the state transient does not belong
	 * to this plugin, allowing RT's handler at priority 10 to run normally.
	 */
	public static function handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $_GET['code'], $_GET['state'] ) ) return;

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		// Ownership check: only proceed if the state belongs to this plugin.
		$return_url = get_transient( 'ettmc_sso_state_' . $state );
		if ( $return_url === false ) return; // not our state — let RT handle it

		delete_transient( 'ettmc_sso_state_' . $state );

		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_user_logged_in() ) wp_die( 'You must be logged in to connect a character.' );

		[ $client_id, $client_secret ] = self::credentials();
		if ( $client_id === '' || $client_secret === '' ) wp_die( 'EVE SSO credentials are not configured.' );

		$response = wp_remote_post( 'https://login.eveonline.com/v2/oauth/token', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::callback_url(),
			],
		] );

		if ( is_wp_error( $response ) ) wp_die( 'Token request failed: ' . esc_html( $response->get_error_message() ) );

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
			$err = is_array( $body ) ? ( $body['error_description'] ?? $body['error'] ?? 'unknown' ) : 'no body';
			wp_die( 'Token exchange failed (HTTP ' . absint( $status ) . '): ' . esc_html( $err ) );
		}

		// Decode JWT to extract character ID and name.
		// Signature intentionally not verified — token received directly from EVE SSO
		// over HTTPS in exchange for our auth code; cannot have been tampered with.
		$parts = explode( '.', (string) $body['access_token'] );
		if ( count( $parts ) < 2 ) wp_die( 'Invalid access token format.' );

		$b64 = strtr( $parts[1], '-_', '+/' );
		$rem = strlen( $b64 ) % 4;
		if ( $rem ) $b64 .= str_repeat( '=', 4 - $rem );
		$payload = json_decode( base64_decode( $b64 ), true );

		if ( ! is_array( $payload ) || empty( $payload['sub'] ) ) wp_die( 'Could not parse character from token.' );
		if ( ! preg_match( '/(\d+)$/', (string) $payload['sub'], $m ) ) wp_die( 'Invalid character sub claim.' );

		$character_id   = (string) $m[1];
		$character_name = ! empty( $payload['name'] ) ? (string) $payload['name'] : 'Character ' . $character_id;

		$user_id = get_current_user_id();
		$chars   = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $chars ) ) $chars = [];

		$chars[ $character_id ] = [
			'name'          => $character_name,
			'access_token'  => (string) $body['access_token'],
			'refresh_token' => (string) $body['refresh_token'],
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 1200 ),
		];

		update_user_meta( $user_id, self::META_KEY, $chars );

		wp_safe_redirect( is_string( $return_url ) ? $return_url : home_url( '/' ) );
		exit;
	}

	/** Disconnect a character from this plugin's store. */
	public static function disconnect_character(): void {
		if ( ! is_user_logged_in() ) wp_die();
		$char_id = sanitize_text_field( wp_unslash( $_GET['char_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'ettmc_disconnect_' . $char_id );
		$user_id = get_current_user_id();
		$chars   = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $chars ) ) $chars = [];
		if ( $char_id !== '' && isset( $chars[ $char_id ] ) ) {
			unset( $chars[ $char_id ] );
			update_user_meta( $user_id, self::META_KEY, $chars );
		}
		wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
		exit;
	}
}
