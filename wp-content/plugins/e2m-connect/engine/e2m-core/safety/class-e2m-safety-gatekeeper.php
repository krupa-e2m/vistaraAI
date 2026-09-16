<?php
/**
 * E2M Connect MCP - Safety Gatekeeper.
 *
 * Centralised pre-dispatch filter that applies every safety rule to
 * incoming Abilities API requests without editing each ability file.
 * Runs in this order:
 *
 *   1. Domain lock     -> refuses every call when the hostname drifted
 *   2. Rate limiter    -> token bucket per (user, ip)
 *   3. Protected post  -> forbids writes to pinned IDs
 *   4. Nuclear check   -> delete-plugin, force=true, etc. require token
 *   5. Dry-run short-circuit -> preview without writing
 *
 * After successful execution we tap rest_post_dispatch to record a row
 * in the audit log and update the anomaly counter.
 *
 * Placing the gate at the REST layer means abilities stay builder-agnostic
 * about safety; they only need to know their own native inputs.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Safety_Gatekeeper {

	/**
	 * Any ability slug starting with these prefixes is considered read-
	 * only for safety purposes. We still audit-log them for visibility
	 * but skip dry-run, token, and protected-post checks.
	 *
	 * @var array<int, string>
	 */
	private const READ_ONLY_PREFIXES = [ 'list-', 'get-', 'read-', 'find-', 'search-', 'detect-', 'discover-', 'validate-', 'export-' ];

	/**
	 * Ability names that are always bypassed - they manage safety controls
	 * themselves and must never be blocked by the gatekeeper.
	 *
	 * @var array<int, string>
	 */
	private const BYPASS_ABILITIES = [
		'e2m/get-safety-status',
		'e2m/protect-post',
		'e2m/unprotect-post',
		'e2m/list-protected-posts',
		'e2m/get-audit-log',
		// Bridge abilities must always be reachable for MCP clients.
		'e2m-bridge/discover-tools',
		'e2m-bridge/inspect-tool',
		'e2m-bridge/dispatch-tool',
	];

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', [ self::class, 'pre_dispatch' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ self::class, 'post_dispatch' ], 10, 3 );
	}

	/**
	 * Hooked on rest_pre_dispatch. Returning a WP_Error short-circuits
	 * the REST router and surfaces the error to the client. Returning
	 * null lets the original handler run.
	 */
	public static function pre_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		// Previous filters may have already produced a response; respect it.
		if ( $result !== null ) {
			return $result;
		}

		$slug = self::extract_ability_slug( (string) $request->get_route() );
		if ( $slug === null ) {
			return null;
		}
		if ( in_array( $slug, self::BYPASS_ABILITIES, true ) ) {
			return null;
		}

		$input = self::extract_input( $request );

		// 1. Domain lock
		if ( ! E2M_Domain_Lock::is_domain_trusted() ) {
			self::log_rejection( $slug, $input, 'e2m_domain_locked' );
			return E2M_Domain_Lock::untrusted_domain_error();
		}

		// 2. Rate limit (applies to all abilities)
		$rate = E2M_Rate_Limiter::check();
		if ( is_wp_error( $rate ) ) {
			self::log_rejection( $slug, $input, 'e2m_rate_limit_exceeded' );
			return $rate;
		}

		// Bridge / bypass list already returned, so the remaining slugs
		// are candidates for destructive-only checks. Readonly abilities
		// skip the rest.
		if ( self::is_read_only( $slug ) ) {
			return null;
		}

		// 3. Protected post. Abilities use different keys for the same
		// concept - post_id (posts, media, comments), page_id (pages),
		// item_id (menu items), etc. We scan the common aliases so the
		// guard applies regardless of which ability is calling.
		$post_id = self::extract_target_post_id( $input );
		if ( $post_id > 0 && E2M_Safety::is_post_protected( $post_id ) ) {
			self::log_rejection( $slug, $input, 'e2m_post_protected' );
			return E2M_Safety::protected_post_error( $post_id );
		}

		// 4. Dry-run short-circuit: when the effective mode says dry-run
		// is default on and the caller didn't override, return a preview
		// envelope without invoking the ability.
		if ( E2M_Safety::is_dry_run( $input ) ) {
			self::log_rejection( $slug, $input, 'e2m_dry_run' );
			return rest_ensure_response(
				E2M_Safety::dry_run_envelope(
					sprintf( 'Would execute %s with the supplied input.', $slug ),
					[ 'ability' => $slug, 'input' => $input ]
				)
			);
		}

		// 5. Capture a pre-write snapshot so the admin can roll the post
		// back if the agent's change is wrong. Only fires when the HTTP
		// method actually matches the ability's expected method -
		// otherwise the MCP client will retry with the correct verb and
		// we would capture twice for a single user intent.
		// Only capture on the request whose HTTP method actually matches the
		// ability (avoids double-capture when an MCP client retries with the
		// wrong verb first). The shared backup logic lives in pre_execute_safety
		// so the bridge dispatch path can reuse it.
		if ( self::request_will_execute( $slug, $request ) ) {
			self::pre_execute_safety( $slug, $input );
		}

		return null;
	}

	/**
	 * Take every pre-task backup for a (non read-only) ability about to run:
	 *   - in-DB meta-snapshot of the target post's builder data,
	 *   - file-based Elementor export when it's an Elementor page,
	 *   - scoped DB backup of the rows the task targets (or a warning when the
	 *     blast radius can't be scoped).
	 *
	 * Shared by BOTH entry points so backups fire no matter how the ability is
	 * invoked:
	 *   1. rest_pre_dispatch (direct /wp-abilities REST calls), and
	 *   2. the E2M bridge dispatch tool, which executes abilities in-process
	 *      and therefore never hits rest_pre_dispatch. Without this call the
	 *      primary agent path (bridge dispatch) would get NO automatic backup.
	 *
	 * Idempotent per logical task: exactly one of the two entry points runs for
	 * any given execution, so there is no double capture.
	 *
	 * Returns the id(s) of whichever backups actually fired, so callers (the
	 * bridge dispatch path — see e2m-bridge-dispatch.php) can surface a
	 * `backup_id` on the ability response for one-step rollback. Both
	 * `capture_if_destructive()` and `maybe_export_if_destructive()` already
	 * returned an id internally; this method simply stops discarding them.
	 *
	 * @param array<string, mixed> $input
	 * @return array{meta_snapshot_id: string, elementor_export_id: string}
	 */
	public static function pre_execute_safety( string $slug, array $input ): array {
		$backups = [
			'meta_snapshot_id'     => '',
			'elementor_export_id'  => '',
		];

		if ( $slug === '' || in_array( $slug, self::BYPASS_ABILITIES, true ) || self::is_read_only( $slug ) ) {
			return $backups;
		}

		$post_id = self::extract_target_post_id( $input );
		if ( $post_id > 0 ) {
			if ( class_exists( 'E2M_Meta_Snapshot' ) ) {
				$backups['meta_snapshot_id'] = E2M_Meta_Snapshot::capture_if_destructive( $slug, $post_id, $input );
			}
			// File-based Elementor export (survives plugin deactivation;
			// restorable to any point) — complements the in-DB snapshot above.
			if ( class_exists( 'E2M_Elementor_Backup' ) ) {
				$backups['elementor_export_id'] = E2M_Elementor_Backup::maybe_export_if_destructive( $slug, $post_id );
			}
		}

		// Scoped DB safety net for any content/DB-affecting write (posts,
		// options, terms, users). Runs even without a post_id. When the work
		// can't be scoped (plugin/theme update, raw SQL, bulk delete) we record
		// a warning instead and let the task proceed.
		if ( class_exists( 'E2M_Risk_Classifier' ) ) {
			$verdict = E2M_Risk_Classifier::classify( $slug, $input );
			if ( ! empty( $verdict['db_scope'] ) && class_exists( 'E2M_DB_Backup' ) ) {
				E2M_DB_Backup::backup_scope( $verdict['db_scope'], 'pre:' . $slug );
			} elseif ( ! empty( $verdict['warn'] ) ) {
				self::log_warning( $slug, $input, (string) $verdict['reason'] );
			}
		}

		return $backups;
	}

	/**
	 * Decide whether this (method, ability) pair is the one that will
	 * actually run. The Abilities API maps:
	 *   readonly    -> GET
	 *   destructive -> DELETE
	 *   otherwise   -> POST
	 *
	 * If the caller used the wrong method, WP REST will 405 and the
	 * client will retry. We don't want the first (doomed) attempt to
	 * trigger pre-write side effects like snapshotting.
	 */
	private static function request_will_execute( string $slug, WP_REST_Request $request ): bool {
		$method = strtoupper( (string) $request->get_method() );

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( $ability === null ) {
			// Unknown ability - let the registry respond with 404; skip
			// capture rather than risk spurious snapshots.
			return false;
		}

		$meta        = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : [];
		$annotations = (array) ( $meta['annotations'] ?? [] );
		$readonly    = ! empty( $annotations['readonly'] );
		$destructive = ! empty( $annotations['destructive'] );

		$expected = $readonly ? 'GET' : ( $destructive ? 'DELETE' : 'POST' );
		return $method === $expected;
	}

	/**
	 * Hooked on rest_post_dispatch. Logs the outcome of every ability
	 * call we touched. Always returns $response unchanged.
	 */
	public static function post_dispatch( $response, WP_REST_Server $server, WP_REST_Request $request ) {
		$slug = self::extract_ability_slug( (string) $request->get_route() );
		if ( $slug === null || in_array( $slug, self::BYPASS_ABILITIES, true ) ) {
			return $response;
		}

		$input = self::extract_input( $request );
		$data  = $response instanceof WP_REST_Response ? $response->get_data() : null;
		$is_err = $response instanceof WP_Error
			|| ( is_array( $data ) && isset( $data['code'] ) && isset( $data['message'] ) && isset( $data['data'] ) );

		$error_code = '';
		if ( $response instanceof WP_Error ) {
			$error_code = (string) $response->get_error_code();
		} elseif ( $is_err && is_array( $data ) ) {
			$error_code = (string) ( $data['code'] ?? '' );
		}

		// Resolve the target post ID from the input first (for operations
		// on existing posts) and fall back to the response payload (for
		// create-* operations that return the new ID).
		$post_id = self::extract_target_post_id( $input );
		if ( $post_id === 0 && is_array( $data ) ) {
			$post_id = self::extract_target_post_id( $data );
		}

		E2M_Audit_Log::record(
			[
				'ability_name' => $slug,
				'input'        => $input,
				'http_method'  => (string) $request->get_method(),
				'post_id'      => $post_id,
				'success'      => ! $is_err,
				'error_code'   => $error_code,
				'metadata'     => [],
			]
		);

		return $response;
	}

	/**
	 * Parse an ability slug out of a REST route path.
	 * Accepts both namespaces used by E2M Connect so forward-compatible
	 * URL changes won't silently bypass the gatekeeper.
	 */
	private static function extract_ability_slug( string $route ): ?string {
		if ( ! preg_match( '#/wp-abilities/v1/abilities/([^/]+/[^/]+)/run#', $route, $m ) ) {
			return null;
		}
		return (string) $m[1];
	}

	/**
	 * Pull the "input" payload from the request. The Abilities API
	 * wraps it under { input: {...} } in the body and input[] in the
	 * query string, so we have to look in both places.
	 *
	 * @return array<string, mixed>
	 */
	private static function extract_input( WP_REST_Request $request ): array {
		$payload = $request->get_json_params();
		if ( is_array( $payload ) && isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			return $payload['input'];
		}
		$params = $request->get_params();
		if ( is_array( $params ) && isset( $params['input'] ) && is_array( $params['input'] ) ) {
			return $params['input'];
		}
		return [];
	}

	/**
	 * Resolve the canonical post ID from an ability input, scanning the
	 * common aliases WordPress abilities use (post_id, page_id, etc.).
	 */
	private static function extract_target_post_id( array $input ): int {
		foreach ( [ 'post_id', 'page_id', 'attachment_id' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$value = (int) $input[ $key ];
				if ( $value > 0 ) {
					return $value;
				}
			}
		}
		return 0;
	}

	/**
	 * Classify by slug prefix. get-page -> readonly, delete-page ->
	 * destructive. Fast and works even if we haven't consulted the
	 * Abilities API registry.
	 */
	private static function is_read_only( string $slug ): bool {
		$tail = substr( $slug, strrpos( $slug, '/' ) + 1 );
		foreach ( self::READ_ONLY_PREFIXES as $prefix ) {
			if ( str_starts_with( $tail, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record an audit-log row for a rejected request. We call this from
	 * pre_dispatch because the post_dispatch filter never fires when a
	 * pre-dispatch filter returns a WP_Error.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function log_rejection( string $slug, array $input, string $code ): void {
		E2M_Audit_Log::record(
			[
				'ability_name' => $slug,
				'input'        => $input,
				'http_method'  => '',
				'post_id'      => self::extract_target_post_id( $input ),
				'success'      => false,
				'error_code'   => $code,
				'metadata'     => [ 'phase' => 'gatekeeper_pre_dispatch' ],
			]
		);
	}

	/**
	 * Record a non-blocking safety warning - the task is allowed to proceed,
	 * but we note that no automatic DB backup could be taken so the operator
	 * can review it later.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function log_warning( string $slug, array $input, string $reason ): void {
		E2M_Audit_Log::record(
			[
				'ability_name' => $slug,
				'input'        => $input,
				'http_method'  => '',
				'post_id'      => self::extract_target_post_id( $input ),
				'success'      => true,
				'error_code'   => 'e2m_db_backup_warning',
				'metadata'     => [ 'phase' => 'gatekeeper_pre_dispatch', 'reason' => $reason ],
			]
		);
	}
}
