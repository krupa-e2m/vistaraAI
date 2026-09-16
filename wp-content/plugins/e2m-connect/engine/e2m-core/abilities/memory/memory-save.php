<?php
/**
 * E2M Connect — Memory: Save (create or update).
 *
 * The central save ability. Implements the three-gate discipline:
 *
 *   1. Trigger detection (the AI provides trigger_type from the five
 *      allowed values; the input schema enforces the enum)
 *   2. Schema validation (type-specific rules via E2M_Memory_Validator)
 *   3. Near-duplicate check (similarity gate via E2M_Memory_Similarity)
 *
 * Memories saved with confidence at or above the auto-save threshold
 * (Strict: 0.80, Loose: 0.40, Custom: configurable) land as `active`.
 * Lower confidence lands as `pending_review` — the user is prompted on
 * next session start and the cron in Step 11 auto-promotes after seven
 * days.
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_register_ability(
	'e2m/memory-save',
	[
		'label'       => __( '[Memory] Save Memory', 'e2mconnect' ),
		'description' => __( 'Creates or updates a memory. Pass `id` to update an existing entry. Caller must set `trigger_type` indicating which of the five recognized triggers fired the save; the system uses it to score initial confidence and decide whether to auto-promote to active or hold as pending_review.', 'e2mconnect' ),
		'category'    => 'e2m-memory',

		'permission_callback' => 'e2m_memory_can_write',

		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'           => [
					'type'        => 'integer',
					'description' => 'Id of an existing memory to update. Omit to create new.',
				],
				'name'         => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Short, specific title for the memory.',
				],
				'description'  => [
					'type'        => 'string',
					'minLength'   => 10,
					'maxLength'   => 300,
					'description' => 'One-line hook describing the memory. Shown in the index — be specific.',
				],
				'type'         => [
					'type'        => 'string',
					'enum'        => [ 'context', 'rules', 'profile', 'reference' ],
					'description' => 'Memory category. Context = project state, Rules = how to work, Profile = who, Reference = external pointers.',
				],
				'content'      => [
					'type'        => 'string',
					'minLength'   => 30,
					'maxLength'   => 2048,
					'description' => 'The memory body. For rules type, must contain **Why:** and **How to apply:**.',
				],
				'visibility'   => [
					'type'        => 'string',
					'enum'        => [ 'private', 'team', 'system' ],
					'description' => 'Who sees this memory. Defaults to private for profile, team for others. System requires admin and applies regardless of connected user.',
				],
				'tags'         => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'maxItems'    => 10,
					'description' => 'Optional tags for organization.',
				],
				'slug'         => [
					'type'        => 'string',
					'pattern'     => '^[a-z0-9-]+$',
					'maxLength'   => 100,
					'description' => 'Optional. If set, the memory becomes slug-callable via memory-get.',
				],
				'expires_at'   => [
					'type'        => 'string',
					'description' => 'Optional ISO-8601 datetime when this memory should auto-stale.',
				],
				'trigger_type' => [
					'type'        => 'string',
					'enum'        => [ 'explicit_save', 'repeated_correction', 'unusual_confirmed', 'fact_stated', 'inferred' ],
					'description' => 'Why this save fired. Drives initial confidence: explicit_save=0.90, repeated_correction=0.85, unusual_confirmed=0.75, fact_stated=0.70, inferred=0.40.',
				],
			],
			'required'             => [ 'name', 'description', 'type', 'content', 'trigger_type' ],
			'additionalProperties' => false,
		],

		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'id'         => [ 'type' => 'integer' ],
				'name'       => [ 'type' => 'string' ],
				'type'       => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
				'visibility' => [ 'type' => 'string' ],
				'confidence' => [ 'type' => 'number' ],
				'created'    => [ 'type' => 'boolean' ],
				'warnings'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		],

		'meta' => [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'title'        => 'Save a memory',
				'readonly'     => false,
				'destructive'  => false,
				'idempotent'   => false,
				'instructions' => 'BEFORE saving a memory, verify one of the five allowed triggers fired: (1) explicit user save request, (2) corrected behavior 2+ times, (3) user confirmed an unusual choice, (4) user stated a non-derivable fact, (5) external resource pointer. Default is NOT to save. Match the trigger_type to whichever applies. For rules-type, content MUST include **Why:** and **How to apply:** sections. For context-type, resolve any relative dates ("Thursday") to absolute dates ("2026-06-04") before saving. If a near-duplicate exists the response will tell you which id to update instead.',
			],
		],

		'execute_callback' => 'e2m_memory_save_execute',
	]
);

/**
 * @param array<string,mixed>|null $input
 * @return array<string,mixed>|WP_Error
 */
function e2m_memory_save_execute( ?array $input = null ): array|WP_Error {
	$input = is_array( $input ) ? $input : [];

	$type = isset( $input['type'] ) ? (string) $input['type'] : 'context';
	if ( ! in_array( $type, E2M_Memory_CPT::get_valid_types(), true ) ) {
		return new WP_Error( 'invalid_type', __( 'Unknown memory type.', 'e2mconnect' ), [ 'status' => 400 ] );
	}

	// Apply per-type default visibility if the caller didn't set one.
	$visibility = isset( $input['visibility'] )
		? (string) $input['visibility']
		: E2M_Memory_CPT::default_visibility_for_type( $type );

	if ( ! in_array( $visibility, E2M_Memory_CPT::get_valid_visibilities(), true ) ) {
		return new WP_Error( 'invalid_visibility', __( 'Unknown visibility.', 'e2mconnect' ), [ 'status' => 400 ] );
	}

	// System escalation gate. Only admins can write or modify memories
	// that apply regardless of which user is connected.
	if ( $visibility === 'system' && ! e2m_memory_can_escalate_to_system() ) {
		return new WP_Error(
			'system_visibility_requires_admin',
			__( 'Only administrators can create system-visibility memories.', 'e2mconnect' ),
			[ 'status' => 403 ]
		);
	}

	$trigger = isset( $input['trigger_type'] ) ? (string) $input['trigger_type'] : 'inferred';
	if ( ! in_array( $trigger, E2M_Memory_Confidence::get_valid_triggers(), true ) ) {
		return new WP_Error( 'invalid_trigger_type', __( 'Unknown trigger type.', 'e2mconnect' ), [ 'status' => 400 ] );
	}

	$confidence = E2M_Memory_Confidence::score_from_trigger( $trigger );
	$strictness = E2M_Memory_Confidence::get_schema_strictness();

	// Schema validation (type-specific). Capture warnings in warn-mode so
	// the response can surface them without blocking.
	$warnings = [];
	$listener = static function ( string $message ) use ( &$warnings ): void {
		$warnings[] = $message;
	};
	add_action( 'e2m_memory_validation_warning', $listener );

	$validation = E2M_Memory_Validator::validate(
		[
			'type'    => $type,
			'content' => (string) ( $input['content'] ?? '' ),
		],
		$strictness
	);
	if ( is_wp_error( $validation ) ) {
		remove_action( 'e2m_memory_validation_warning', $listener );
		return $validation;
	}

	// Similarity / duplicate-detection gate. Only runs for creates. On
	// update we trust the caller already chose to overwrite.
	$is_update = ! empty( $input['id'] );
	if ( ! $is_update ) {
		$duplicates = E2M_Memory_Similarity::find_near_duplicates(
			(string) ( $input['content'] ?? '' ),
			90,
			[ 'type' => $type ]
		);
		if ( $duplicates !== [] ) {
			remove_action( 'e2m_memory_validation_warning', $listener );
			$top = $duplicates[0];
			return new WP_Error(
				'near_duplicate',
				sprintf(
					/* translators: 1: existing memory name, 2: existing memory id */
					__( 'Memory "%1$s" (id %2$d) looks very similar (>= 90%% match). Update that one instead by calling e2m/memory-save with id=%2$d.', 'e2mconnect' ),
					$top['name'],
					$top['id']
				),
				[
					'status'       => 409,
					'duplicate_id' => $top['id'],
					'score'        => round( $top['score'], 3 ),
				]
			);
		}
	}

	// Caps check (only for creates -- updates don't change the count).
	$cap_warning   = null;
	$auto_archived = null;
	if ( ! $is_update ) {
		$cap = E2M_Memory_Caps::check_before_save();
		if ( ! $cap['can_save'] ) {
			remove_action( 'e2m_memory_validation_warning', $listener );
			return new WP_Error(
				'memory_cap_exceeded',
				$cap['warning'] ?? __( 'Memory cap exceeded.', 'e2mconnect' ),
				[ 'status' => 507 ]
			);
		}
		if ( $cap['warning'] !== null ) {
			$cap_warning = $cap['warning'];
		}
		if ( $cap['archived'] !== null ) {
			$auto_archived = (int) $cap['archived'];
		}
	}

	// Choose status: above threshold = active, below = pending_review.
	// Updates inherit the existing memory's status unless caller specifies.
	$status = E2M_Memory_Confidence::should_auto_save( $confidence ) ? 'active' : 'pending_review';

	$payload = [
		'name'        => (string) ( $input['name'] ?? '' ),
		'description' => (string) ( $input['description'] ?? '' ),
		'content'     => (string) ( $input['content'] ?? '' ),
		'type'        => $type,
		'visibility'  => $visibility,
		'confidence'  => $confidence,
		'status'      => $status,
		'metadata'    => [
			'trigger_type' => $trigger,
		],
	];

	if ( isset( $input['tags'] ) ) {
		$payload['tags'] = (array) $input['tags'];
	}
	if ( isset( $input['slug'] ) ) {
		$payload['slug'] = (string) $input['slug'];
	}
	if ( isset( $input['expires_at'] ) ) {
		$payload['expires_at'] = (string) $input['expires_at'];
	}

	if ( $is_update ) {
		// On updates we don't reset status to pending_review — caller is
		// editing an existing memory, presumably to refine it. Status only
		// changes on explicit set_status.
		unset( $payload['status'] );

		$existing = E2M_Memory_Repository::get( (int) $input['id'] );
		if ( $existing === null ) {
			remove_action( 'e2m_memory_validation_warning', $listener );
			return new WP_Error( 'not_found', __( 'Memory not found.', 'e2mconnect' ), [ 'status' => 404 ] );
		}
		if ( ! e2m_memory_user_can_edit( $existing ) ) {
			remove_action( 'e2m_memory_validation_warning', $listener );
			return new WP_Error( 'rest_forbidden', __( 'You cannot edit this memory.', 'e2mconnect' ), [ 'status' => 403 ] );
		}

		// If editing a system memory, the escalation gate applies again.
		if ( ( $existing['visibility'] === 'system' || $visibility === 'system' ) && ! e2m_memory_can_escalate_to_system() ) {
			remove_action( 'e2m_memory_validation_warning', $listener );
			return new WP_Error( 'system_visibility_requires_admin', __( 'Editing system memories requires administrator privileges.', 'e2mconnect' ), [ 'status' => 403 ] );
		}

		$result = E2M_Memory_Repository::update( (int) $input['id'], $payload );
	} else {
		$result = E2M_Memory_Repository::create( $payload, get_current_user_id() );
	}

	remove_action( 'e2m_memory_validation_warning', $listener );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$shaped = E2M_Memory_Repository::get( (int) $result );
	if ( $shaped === null ) {
		return new WP_Error( 'save_failed', __( 'Memory was saved but could not be re-read.', 'e2mconnect' ), [ 'status' => 500 ] );
	}

	// Surface cap-related notices alongside validator warnings.
	if ( $cap_warning !== null ) {
		$warnings[] = $cap_warning;
	}
	if ( $auto_archived !== null ) {
		$warnings[] = sprintf(
			/* translators: %d: memory id that was auto-archived */
			__( 'Hard cap reached — memory %d was auto-archived (lowest-confidence non-pinned entry) to make room.', 'e2mconnect' ),
			$auto_archived
		);
	}

	return [
		'id'         => $shaped['id'],
		'name'       => $shaped['name'],
		'type'       => $shaped['type'],
		'status'     => $shaped['status'],
		'visibility' => $shaped['visibility'],
		'confidence' => $shaped['confidence'],
		'created'    => ! $is_update,
		'warnings'   => $warnings,
	];
}
