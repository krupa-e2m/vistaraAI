<?php
/**
 * E2M Connect — Memory admin tab (PHP-rendered).
 *
 * Replaces the React app shipped in 0.1.0 beta. Renders inside the
 * existing so-shell layout used by MCP Connect, AI Abilities, etc. so
 * the Memory tab inherits the branded dashboard chrome (dark sidebar,
 * E2M Connect logo, design tokens) instead of standing out as a separate
 * React island.
 *
 * Views (driven by ?view= query arg):
 *   list (default) - dashboard + filter + memory cards
 *   new            - blank create form
 *   edit           - prefilled edit form for ?id=N
 *
 * Actions (POST `e2m_memory_action`):
 *   save            - create or update
 *   archive         - set status=archived
 *   restore         - set status=active
 *   pin / unpin     - toggle pinned
 *   approve         - pending_review -> active
 *   reject          - pending_review -> archived
 *   bulk_archive    - archive selected ids
 *   hard_delete     - destructive, admin-only, double-nonce
 *
 * @package E2M Connect_MCP
 * @since   0.1.0
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────
// Constants used across this file.
// ─────────────────────────────────────────────────────────────────────

const E2M_MEMORY_TAB_SLUG       = 'memory';
const E2M_MEMORY_LEGACY_TAB     = 'agent_context';
const E2M_MEMORY_NONCE_ACTION   = 'e2m_memory_action';
const E2M_MEMORY_DELETE_NONCE   = 'e2m_memory_delete';

// ─────────────────────────────────────────────────────────────────────
// POST handler — wired on admin_init via memory bootstrap.
// ─────────────────────────────────────────────────────────────────────

/**
 * Process any POSTed memory action and redirect to the appropriate view.
 * Exits early when no action is in play so other admin handlers stay
 * unaffected.
 */
function e2m_memory_admin_handle_post(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['e2m_memory_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'e2mconnect' ), 403 );
	}
	check_admin_referer( E2M_MEMORY_NONCE_ACTION );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$action = sanitize_text_field( wp_unslash( $_POST['e2m_memory_action'] ) );
	$id     = isset( $_POST['memory_id'] ) ? absint( $_POST['memory_id'] ) : 0;
	$notice = null;

	switch ( $action ) {
		case 'save':
			$notice = e2m_memory_admin_handle_save();
			break;
		case 'archive':
			$notice = e2m_memory_admin_handle_archive( $id );
			break;
		case 'restore':
			$notice = e2m_memory_admin_handle_restore( $id );
			break;
		case 'pin':
		case 'unpin':
			$notice = e2m_memory_admin_handle_pin( $id, $action === 'pin' );
			break;
		case 'approve':
		case 'reject':
			$notice = e2m_memory_admin_handle_pending( $id, $action === 'approve' );
			break;
		case 'bulk_archive':
			$notice = e2m_memory_admin_handle_bulk_archive();
			break;
		case 'hard_delete':
			$notice = e2m_memory_admin_handle_hard_delete( $id );
			break;
		case 'toggle_enabled':
			$notice = e2m_memory_admin_handle_toggle_enabled();
			break;
	}

	if ( $notice !== null ) {
		set_transient( 'e2m_memory_notice_' . get_current_user_id(), $notice, 30 );
	}

	wp_safe_redirect( e2m_memory_admin_url( [ 'view' => 'list' ] ) );
	exit;
}

/**
 * Build a URL to the Memory tab with arbitrary query args. Used by
 * redirects, form actions, and link anchors throughout the page.
 *
 * @param array<string,mixed> $args
 */
function e2m_memory_admin_url( array $args = [] ): string {
	$base = add_query_arg(
		[
			'page' => 'e2mconnect',
			'tab'  => E2M_MEMORY_TAB_SLUG,
		],
		admin_url( 'admin.php' )
	);
	if ( $args === [] ) {
		return $base;
	}
	return add_query_arg( $args, $base );
}

/**
 * Pull (and clear) a flash notice for the current user.
 *
 * @return array{type:string,message:string}|null
 */
function e2m_memory_admin_consume_notice(): ?array {
	$key   = 'e2m_memory_notice_' . get_current_user_id();
	$value = get_transient( $key );
	if ( $value !== false ) {
		delete_transient( $key );
		return is_array( $value ) ? $value : null;
	}
	return null;
}

// ─────────────────────────────────────────────────────────────────────
// POST action handlers — return ['type'=>'success|error','message'=>...]
// ─────────────────────────────────────────────────────────────────────

function e2m_memory_admin_handle_save(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$post = wp_unslash( $_POST );

	$payload = [
		'name'         => sanitize_text_field( (string) ( $post['memory_name'] ?? '' ) ),
		'description'  => sanitize_text_field( (string) ( $post['memory_description'] ?? '' ) ),
		'type'         => sanitize_text_field( (string) ( $post['memory_type'] ?? 'context' ) ),
		'visibility'   => sanitize_text_field( (string) ( $post['memory_visibility'] ?? 'team' ) ),
		'status'       => sanitize_text_field( (string) ( $post['memory_status'] ?? 'active' ) ),
		'content'      => wp_kses_post( (string) ( $post['memory_content'] ?? '' ) ),
		'slug'         => sanitize_text_field( (string) ( $post['memory_slug'] ?? '' ) ),
		'expires_at'   => sanitize_text_field( (string) ( $post['memory_expires_at'] ?? '' ) ),
		'tags'         => array_filter( array_map( 'sanitize_title', explode( ',', (string) ( $post['memory_tags'] ?? '' ) ) ) ),
		'trigger_type' => 'explicit_save', // human authored
	];

	// System-visibility escalation gate.
	if ( $payload['visibility'] === 'system' && ! e2m_memory_can_escalate_to_system() ) {
		return [ 'type' => 'error', 'message' => __( 'Only administrators can create system-visibility memories.', 'e2mconnect' ) ];
	}

	$memory_id = isset( $post['memory_id'] ) ? absint( $post['memory_id'] ) : 0;
	if ( $memory_id > 0 ) {
		$existing = E2M_Memory_Repository::get( $memory_id );
		if ( $existing === null ) {
			return [ 'type' => 'error', 'message' => __( 'Memory not found.', 'e2mconnect' ) ];
		}
		if ( ! e2m_memory_user_can_edit( $existing ) ) {
			return [ 'type' => 'error', 'message' => __( 'You cannot edit this memory.', 'e2mconnect' ) ];
		}
		$result = E2M_Memory_Repository::update( $memory_id, $payload );
	} else {
		$result = E2M_Memory_Repository::create( $payload, get_current_user_id() );
	}

	if ( is_wp_error( $result ) ) {
		return [ 'type' => 'error', 'message' => $result->get_error_message() ];
	}

	return [
		'type'    => 'success',
		'message' => $memory_id > 0
			? __( 'Memory updated.', 'e2mconnect' )
			: __( 'Memory created.', 'e2mconnect' ),
	];
}

function e2m_memory_admin_handle_archive( int $id ): array {
	$memory = E2M_Memory_Repository::get( $id );
	if ( $memory === null ) {
		return [ 'type' => 'error', 'message' => __( 'Memory not found.', 'e2mconnect' ) ];
	}
	if ( ! e2m_memory_user_can_edit( $memory ) ) {
		return [ 'type' => 'error', 'message' => __( 'You cannot archive this memory.', 'e2mconnect' ) ];
	}
	if ( $memory['visibility'] === 'system' && ! e2m_memory_can_escalate_to_system() ) {
		return [ 'type' => 'error', 'message' => __( 'Only administrators can archive system memories.', 'e2mconnect' ) ];
	}
	$result = E2M_Memory_Repository::archive( $id );
	if ( is_wp_error( $result ) ) {
		return [ 'type' => 'error', 'message' => $result->get_error_message() ];
	}
	return [ 'type' => 'success', 'message' => __( 'Memory archived.', 'e2mconnect' ) ];
}

function e2m_memory_admin_handle_restore( int $id ): array {
	$memory = E2M_Memory_Repository::get( $id );
	if ( $memory === null ) {
		return [ 'type' => 'error', 'message' => __( 'Memory not found.', 'e2mconnect' ) ];
	}
	if ( ! e2m_memory_user_can_edit( $memory ) ) {
		return [ 'type' => 'error', 'message' => __( 'You cannot restore this memory.', 'e2mconnect' ) ];
	}
	E2M_Memory_Repository::set_status( $id, 'active' );
	return [ 'type' => 'success', 'message' => __( 'Memory restored to active.', 'e2mconnect' ) ];
}

function e2m_memory_admin_handle_pin( int $id, bool $pin ): array {
	$memory = E2M_Memory_Repository::get( $id );
	if ( $memory === null ) {
		return [ 'type' => 'error', 'message' => __( 'Memory not found.', 'e2mconnect' ) ];
	}
	if ( ! e2m_memory_user_can_edit( $memory ) ) {
		return [ 'type' => 'error', 'message' => __( 'You cannot modify this memory.', 'e2mconnect' ) ];
	}
	E2M_Memory_Repository::set_status( $id, $pin ? 'pinned' : 'active' );
	return [
		'type'    => 'success',
		'message' => $pin ? __( 'Memory pinned.', 'e2mconnect' ) : __( 'Memory unpinned.', 'e2mconnect' ),
	];
}

function e2m_memory_admin_handle_pending( int $id, bool $approve ): array {
	// Audit fix [HIGH]: per-id ownership check before promote/reject. Without
	// this, any editor could promote/reject any pending memory by guessing
	// the post id (they're sequential WP post ids). Now matches the pattern
	// used by archive/restore/pin.
	$memory = E2M_Memory_Repository::get( $id );
	if ( $memory === null ) {
		return [ 'type' => 'error', 'message' => __( 'Memory not found.', 'e2mconnect' ) ];
	}
	if ( ! e2m_memory_user_can_edit( $memory ) ) {
		return [ 'type' => 'error', 'message' => __( 'You cannot review this memory.', 'e2mconnect' ) ];
	}
	if ( $memory['visibility'] === 'system' && ! e2m_memory_can_escalate_to_system() ) {
		return [ 'type' => 'error', 'message' => __( 'Only administrators can review system memories.', 'e2mconnect' ) ];
	}

	$result = E2M_Memory_Pending_Review::promote_one( $id, $approve );
	if ( is_wp_error( $result ) ) {
		return [ 'type' => 'error', 'message' => $result->get_error_message() ];
	}
	return [
		'type'    => 'success',
		'message' => $approve
			? __( 'Memory approved and marked active.', 'e2mconnect' )
			: __( 'Memory rejected and archived.', 'e2mconnect' ),
	];
}

function e2m_memory_admin_handle_bulk_archive(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$ids = isset( $_POST['memory_ids'] ) && is_array( $_POST['memory_ids'] )
		? array_filter( array_map( 'absint', wp_unslash( $_POST['memory_ids'] ) ) )
		: [];
	if ( $ids === [] ) {
		return [ 'type' => 'error', 'message' => __( 'No memories selected.', 'e2mconnect' ) ];
	}
	$archived = 0;
	foreach ( $ids as $id ) {
		$memory = E2M_Memory_Repository::get( (int) $id );
		if ( $memory === null || ! e2m_memory_user_can_edit( $memory ) ) {
			continue;
		}
		if ( $memory['visibility'] === 'system' && ! e2m_memory_can_escalate_to_system() ) {
			continue;
		}
		if ( E2M_Memory_Repository::archive( (int) $id ) === true ) {
			$archived++;
		}
	}
	return [
		'type'    => 'success',
		'message' => sprintf(
			/* translators: %d: count */
			_n( '%d memory archived.', '%d memories archived.', $archived, 'e2mconnect' ),
			$archived
		),
	];
}

function e2m_memory_admin_handle_toggle_enabled(): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return [ 'type' => 'error', 'message' => __( 'Toggling the memory subsystem requires administrator privileges.', 'e2mconnect' ) ];
	}
	// Checkbox is only present in POST when checked. Absence = off.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$enable = ! empty( $_POST['memory_enabled'] );
	e2m_memory_set_enabled( $enable );
	return [
		'type'    => 'success',
		'message' => $enable
			? __( 'Memory enabled. Abilities and context injection are active.', 'e2mconnect' )
			: __( 'Memory disabled. Existing data is preserved but no abilities or context are exposed to agents.', 'e2mconnect' ),
	];
}

function e2m_memory_admin_handle_hard_delete( int $id ): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return [ 'type' => 'error', 'message' => __( 'Hard delete requires administrator privileges.', 'e2mconnect' ) ];
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['delete_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['delete_nonce'] ) ), E2M_MEMORY_DELETE_NONCE ) ) {
		return [ 'type' => 'error', 'message' => __( 'Invalid delete confirmation.', 'e2mconnect' ) ];
	}
	$result = E2M_Memory_Repository::delete_hard( $id );
	if ( is_wp_error( $result ) ) {
		return [ 'type' => 'error', 'message' => $result->get_error_message() ];
	}
	return [ 'type' => 'success', 'message' => __( 'Memory permanently deleted.', 'e2mconnect' ) ];
}

// ─────────────────────────────────────────────────────────────────────
// Main render — dispatched from admin-pages.php tab switch.
// ─────────────────────────────────────────────────────────────────────

function e2m_memory_admin_render_tab(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$edit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

	$notice = e2m_memory_admin_consume_notice();
	if ( $notice !== null ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	// When the subsystem is disabled, show a clear empty-state instead
	// of the normal dashboard. The page-header toggle still works (lives
	// outside this render). Admin can browse/edit existing memories via
	// the URL but the warning makes the state obvious.
	if ( ! e2m_memory_is_enabled() ) {
		?>
		<div class="so-card e2m-memory-empty">
			<div class="e2m-memory-empty__icon" aria-hidden="true">⏸</div>
			<h3 class="so-card-title"><?php esc_html_e( 'AI Memory is turned off', 'e2mconnect' ); ?></h3>
			<p class="so-card-desc e2m-memory-empty__message">
				<?php esc_html_e( 'The AI Memory abilities are not exposed to connected agents and no context is prepended to the agent system prompt. Your existing memories are preserved — flip the toggle in the page header to turn the subsystem back on.', 'e2mconnect' ); ?>
			</p>
		</div>
		<?php
		return;
	}

	switch ( $view ) {
		case 'new':
			e2m_memory_admin_render_form( null );
			break;
		case 'edit':
			$memory = $edit_id > 0 ? E2M_Memory_Repository::get( $edit_id ) : null;
			if ( $memory === null ) {
				echo '<div class="so-card"><p class="so-card-desc">' . esc_html__( 'Memory not found.', 'e2mconnect' ) . '</p></div>';
				return;
			}
			if ( ! e2m_memory_user_can_see( $memory ) ) {
				echo '<div class="so-card"><p class="so-card-desc">' . esc_html__( 'You cannot view this memory.', 'e2mconnect' ) . '</p></div>';
				return;
			}
			e2m_memory_admin_render_form( $memory );
			break;
		case 'list':
		default:
			e2m_memory_admin_render_list();
			break;
	}
}

// ─────────────────────────────────────────────────────────────────────
// List view — dashboard + pending queue + filters + cards.
// ─────────────────────────────────────────────────────────────────────

function e2m_memory_admin_render_list(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$filters = [
		'type'             => isset( $_GET['ftype'] ) ? sanitize_text_field( wp_unslash( $_GET['ftype'] ) ) : '',
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'status'           => isset( $_GET['fstatus'] ) ? sanitize_text_field( wp_unslash( $_GET['fstatus'] ) ) : '',
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'visibility'       => isset( $_GET['fvis'] ) ? sanitize_text_field( wp_unslash( $_GET['fvis'] ) ) : '',
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'include_archived' => ! empty( $_GET['finclude'] ),
	];

	$query_filters = array_filter(
		[
			'type'             => $filters['type'] !== '' ? $filters['type'] : null,
			'status'           => $filters['status'] !== '' ? $filters['status'] : null,
			'visibility'       => $filters['visibility'] !== '' ? $filters['visibility'] : null,
			'include_archived' => $filters['include_archived'] ? true : null,
		],
		static fn ( $v ) => $v !== null
	);

	$result   = E2M_Memory_Repository::list( $query_filters, 1, 200 );
	$memories = array_values( array_filter( $result['memories'], 'e2m_memory_user_can_see' ) );

	$pending = E2M_Memory_Pending_Review::list_pending( 0, 20 );
	$pending = array_values( array_filter( $pending, 'e2m_memory_user_can_see' ) );

	e2m_memory_admin_render_dashboard();
	e2m_memory_admin_render_pending_queue( $pending );
	e2m_memory_admin_render_filter_bar( $filters );
	e2m_memory_admin_render_cards( $memories );
}

function e2m_memory_admin_render_dashboard(): void {
	$settings = E2M_Memory_Settings::get();
	$soft_cap = (int) $settings['limits']['soft_cap'];
	$hard_cap = (int) $settings['limits']['hard_cap'];

	// Audit fix [HIGH]: count only memories the current user can see.
	// Raw count() leaks the existence + bucket sizes of private memories
	// owned by other users. Bounded at 2000 to keep the dashboard cheap;
	// well above the configured soft cap.
	$visible_pool = E2M_Memory_Repository::list( [ 'include_archived' => true ], 1, 2000 );
	$visible      = array_values( array_filter( $visible_pool['memories'], 'e2m_memory_user_can_see' ) );

	$total      = count( $visible );
	$active_set = 0;
	$by_type    = array_fill_keys( E2M_Memory_CPT::get_valid_types(), 0 );

	foreach ( $visible as $memory ) {
		$status = (string) ( $memory['status'] ?? 'active' );
		$type   = (string) ( $memory['type'] ?? 'context' );
		if ( isset( $by_type[ $type ] ) ) {
			$by_type[ $type ]++;
		}
		if ( in_array( $status, [ 'pinned', 'always', 'active', 'pending_review' ], true ) ) {
			$active_set++;
		}
	}

	$progress  = $soft_cap > 0 ? min( 100, (int) round( ( $active_set / $soft_cap ) * 100 ) ) : 0;
	$cap_color = $active_set >= $hard_cap
		? 'var(--so-error-active)'
		: ( $progress >= 90 ? 'var(--so-status-warn)' : 'var(--so-status-success-fg)' );

	$type_labels = [
		'context'   => __( 'Context', 'e2mconnect' ),
		'rules'     => __( 'Rules', 'e2mconnect' ),
		'profile'   => __( 'Profile', 'e2mconnect' ),
		'reference' => __( 'Reference', 'e2mconnect' ),
	];
	?>
	<div class="so-card e2m-memory-dashboard">
		<div class="e2m-memory-dashboard__row">
			<div>
				<h3 class="so-card-title"><?php esc_html_e( 'Memory health', 'e2mconnect' ); ?></h3>
				<p class="so-card-desc"><?php
					printf(
						/* translators: 1: count, 2: soft cap, 3: hard cap */
						esc_html__( '%1$d of %2$d soft cap. Hard cap %3$d.', 'e2mconnect' ),
						(int) $active_set,
						(int) $soft_cap,
						(int) $hard_cap
					);
				?></p>
				<div class="e2m-memory-dashboard__meter">
					<div class="e2m-memory-dashboard__meter-fill" style="width:<?php echo esc_attr( (string) $progress ); ?>%;--meter-fill:<?php echo esc_attr( $cap_color ); ?>;"></div>
				</div>
			</div>
			<div class="e2m-memory-dashboard__total">
				<div class="e2m-memory-dashboard__total-value"><?php echo esc_html( (string) $total ); ?></div>
				<div class="e2m-memory-dashboard__total-label"><?php esc_html_e( 'Total memories', 'e2mconnect' ); ?></div>
			</div>
		</div>

		<div class="e2m-memory-dashboard__types">
			<?php foreach ( $type_labels as $type => $label ): ?>
				<a href="<?php echo esc_url( e2m_memory_admin_url( [ 'ftype' => $type ] ) ); ?>" class="e2m-memory-dashboard__type-card">
					<div class="e2m-memory-dashboard__chip-label"><?php echo esc_html( $label ); ?></div>
					<div class="e2m-memory-dashboard__type-value"><?php echo esc_html( (string) $by_type[ $type ] ); ?></div>
				</a>
			<?php endforeach; ?>
		</div>

	</div>
	<?php
}

function e2m_memory_admin_render_pending_queue( array $pending ): void {
	if ( $pending === [] ) {
		return;
	}
	?>
	<div class="so-card e2m-memory-pending">
		<h3 class="so-card-title">
			🔔
			<?php
			printf(
				/* translators: %d: count of pending memories */
				esc_html( _n( '%d memory pending review', '%d memories pending review', count( $pending ), 'e2mconnect' ) ),
				(int) count( $pending )
			);
			?>
		</h3>
		<p class="so-card-desc"><?php esc_html_e( 'The AI auto-saved these at or above the confidence threshold. Approve to keep them active, or reject to archive. Auto-promotes to active after 7 days unreviewed.', 'e2mconnect' ); ?></p>

		<div class="e2m-memory-pending__list">
			<?php foreach ( $pending as $memory ): ?>
				<div class="e2m-memory-pending__item">
					<div class="e2m-memory-pending__row">
						<div>
							<strong><?php echo esc_html( $memory['name'] ); ?></strong>
							<span class="e2m-memory-card__meta"><?php echo esc_html( ' · ' . $memory['type'] ); ?></span>
							<p class="e2m-memory-card__desc"><?php echo esc_html( $memory['description'] ); ?></p>
							<div class="e2m-memory-card__byline">
								<?php
								printf(
									/* translators: %s: time-since label */
									esc_html__( 'Proposed %s', 'e2mconnect' ),
									esc_html( e2m_memory_admin_format_relative( $memory['updated_at'] ?? '' ) )
								);
								?>
								<span class="e2m-memory-byline__sep" aria-hidden="true">·</span>
								<?php e2m_memory_admin_render_byline( $memory ); ?>
							</div>
						</div>
						<form method="post" class="e2m-memory-card__actions">
							<?php wp_nonce_field( E2M_MEMORY_NONCE_ACTION ); ?>
							<input type="hidden" name="memory_id" value="<?php echo (int) $memory['id']; ?>" />
							<button type="submit" name="e2m_memory_action" value="approve" class="button button-primary button-small"><?php esc_html_e( 'Approve', 'e2mconnect' ); ?></button>
							<button type="submit" name="e2m_memory_action" value="reject" class="button button-small e2m-memory-card__btn-archive"><?php esc_html_e( 'Reject', 'e2mconnect' ); ?></button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Render the canonical custom dropdown component used across the plugin.
 *
 * @param string $name        Form input name (also written to a hidden input).
 * @param array  $options     Map of value => label.
 * @param string $current     Currently selected value.
 * @param bool   $auto_submit If true (default), picking an option submits the parent form.
 *                            Use false inside content-edit forms so picking a value
 *                            doesn't save the record.
 */
function e2m_memory_admin_render_dropdown( string $name, array $options, string $current, bool $auto_submit = true ): void {
	$current_label = $options[ $current ] ?? reset( $options );
	$submit_attr   = $auto_submit ? 'true' : 'false';
	?>
	<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $current ); ?>" />
	<div class="so-dropdown so-dropdown--block" data-input="<?php echo esc_attr( $name ); ?>" data-submit-on-change="<?php echo esc_attr( $submit_attr ); ?>">
		<button type="button" class="so-dropdown-trigger">
			<span class="so-dropdown-label"><?php echo esc_html( (string) $current_label ); ?></span>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
		</button>
		<div class="so-dropdown-menu">
			<?php foreach ( $options as $val => $label ): ?>
				<button type="button" class="so-dropdown-item<?php echo (string) $current === (string) $val ? ' active' : ''; ?>" data-value="<?php echo esc_attr( (string) $val ); ?>"><?php echo esc_html( (string) $label ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

function e2m_memory_admin_render_filter_bar( array $filters ): void {
	$type_options = [
		''          => __( 'All types', 'e2mconnect' ),
		'context'   => __( 'Context', 'e2mconnect' ),
		'rules'     => __( 'Rules', 'e2mconnect' ),
		'profile'   => __( 'Profile', 'e2mconnect' ),
		'reference' => __( 'Reference', 'e2mconnect' ),
	];
	$status_options = [
		''               => __( 'All statuses', 'e2mconnect' ),
		'active'         => __( 'Active', 'e2mconnect' ),
		'always'         => __( 'Always applied', 'e2mconnect' ),
		'pinned'         => __( 'Pinned', 'e2mconnect' ),
		'pending_review' => __( 'Pending review', 'e2mconnect' ),
		'stale'          => __( 'Stale', 'e2mconnect' ),
		'disputed'       => __( 'Disputed', 'e2mconnect' ),
	];
	$visibility_options = [
		''        => __( 'All visibilities', 'e2mconnect' ),
		'private' => __( 'Private', 'e2mconnect' ),
		'team'    => __( 'Team', 'e2mconnect' ),
		'system'  => __( 'System', 'e2mconnect' ),
	];
	?>
	<div class="so-card e2m-memory-filter">
		<div class="e2m-memory-filter__header">
			<h3 class="so-card-title"><?php esc_html_e( 'Memories', 'e2mconnect' ); ?></h3>
			<a href="<?php echo esc_url( e2m_memory_admin_url( [ 'view' => 'new' ] ) ); ?>" class="button button-primary"><?php esc_html_e( '+ New memory', 'e2mconnect' ); ?></a>
		</div>
		<form method="get" class="e2m-memory-filter__form">
			<input type="hidden" name="page" value="e2mconnect" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( E2M_MEMORY_TAB_SLUG ); ?>" />
			<div class="e2m-memory-filter__field">
				<span class="e2m-memory-filter__label"><?php esc_html_e( 'Type', 'e2mconnect' ); ?></span>
				<?php e2m_memory_admin_render_dropdown( 'ftype', $type_options, (string) $filters['type'] ); ?>
			</div>
			<div class="e2m-memory-filter__field">
				<span class="e2m-memory-filter__label"><?php esc_html_e( 'Status', 'e2mconnect' ); ?></span>
				<?php e2m_memory_admin_render_dropdown( 'fstatus', $status_options, (string) $filters['status'] ); ?>
			</div>
			<div class="e2m-memory-filter__field">
				<span class="e2m-memory-filter__label"><?php esc_html_e( 'Visibility', 'e2mconnect' ); ?></span>
				<?php e2m_memory_admin_render_dropdown( 'fvis', $visibility_options, (string) $filters['visibility'] ); ?>
			</div>
			<a href="<?php echo esc_url( e2m_memory_admin_url() ); ?>" class="button button-secondary e2m-memory-filter__reset"><?php esc_html_e( 'Reset', 'e2mconnect' ); ?></a>
		</form>
	</div>
	<?php
}

function e2m_memory_admin_render_cards( array $memories ): void {
	if ( $memories === [] ) {
		?>
		<div class="so-card e2m-memory-empty">
			<div class="e2m-memory-empty__icon">🌱</div>
			<h3 class="so-card-title"><?php esc_html_e( 'No memories match these filters', 'e2mconnect' ); ?></h3>
			<p class="so-card-desc e2m-memory-empty__message"><?php esc_html_e( 'Memories appear here as the AI saves them under the discipline contract — or as you add them yourself. Reset filters to see everything, or create a new one.', 'e2mconnect' ); ?></p>
			<a href="<?php echo esc_url( e2m_memory_admin_url( [ 'view' => 'new' ] ) ); ?>" class="button button-primary"><?php esc_html_e( 'New memory', 'e2mconnect' ); ?></a>
		</div>
		<?php
		return;
	}

	?>
	<div class="e2m-memory-cards">
		<?php foreach ( $memories as $memory ): ?>
			<?php e2m_memory_admin_render_memory_card( $memory ); ?>
		<?php endforeach; ?>
	</div>
	<?php
}

function e2m_memory_admin_render_memory_card( array $memory ): void {
	$is_archived = $memory['status'] === 'archived';
	$is_pinned   = $memory['status'] === 'pinned';
	$is_always   = $memory['status'] === 'always';
	$updated     = e2m_memory_admin_format_relative( $memory['updated_at'] );
	$edit_url    = e2m_memory_admin_url( [ 'view' => 'edit', 'id' => (int) $memory['id'] ] );

	$toggle_label = $is_archived
		? __( 'Restore from archive', 'e2mconnect' )
		: __( 'Archive this memory', 'e2mconnect' );

	?>
	<article class="e2m-memory-card">
		<div class="e2m-memory-card__body">
			<header class="e2m-memory-card__head">
				<div class="e2m-memory-card__icon-badge e2m-memory-card__icon-badge--<?php echo esc_attr( (string) $memory['type'] ); ?>" aria-hidden="true">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG markup from internal helper.
					echo e2m_memory_admin_type_icon( (string) $memory['type'] );
					?>
				</div>
				<form method="post" class="e2m-memory-card__toggle-form" title="<?php echo esc_attr( $toggle_label ); ?>">
					<?php wp_nonce_field( E2M_MEMORY_NONCE_ACTION ); ?>
					<input type="hidden" name="memory_id" value="<?php echo (int) $memory['id']; ?>" />
					<input type="hidden" name="e2m_memory_action" value="<?php echo esc_attr( $is_archived ? 'restore' : 'archive' ); ?>" />
					<button type="submit" class="e2m-memory-card__toggle <?php echo $is_archived ? '' : 'e2m-memory-card__toggle--on'; ?>" aria-pressed="<?php echo $is_archived ? 'false' : 'true'; ?>" aria-label="<?php echo esc_attr( $toggle_label ); ?>">
						<span class="e2m-memory-card__toggle-track" aria-hidden="true">
							<span class="e2m-memory-card__toggle-thumb"></span>
						</span>
					</button>
				</form>
				<details class="e2m-memory-card__menu">
					<summary class="e2m-memory-card__menu-btn" aria-label="<?php esc_attr_e( 'More actions', 'e2mconnect' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
					</summary>
					<div class="e2m-memory-card__menu-list" role="menu">
						<a class="e2m-memory-card__menu-item" role="menuitem" href="<?php echo esc_url( $edit_url ); ?>">
							<?php esc_html_e( 'Edit', 'e2mconnect' ); ?>
						</a>

						<?php if ( $is_pinned ): ?>
							<?php e2m_memory_admin_render_menu_form( (int) $memory['id'], 'unpin', __( 'Unpin', 'e2mconnect' ) ); ?>
						<?php elseif ( ! $is_archived && ! $is_always ): ?>
							<?php e2m_memory_admin_render_menu_form( (int) $memory['id'], 'pin', __( 'Pin', 'e2mconnect' ) ); ?>
						<?php endif; ?>

						<?php if ( current_user_can( 'manage_options' ) && ! $is_archived ): ?>
							<form method="post" class="e2m-memory-card__menu-form" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this memory? This cannot be undone.', 'e2mconnect' ) ); ?>');">
								<?php wp_nonce_field( E2M_MEMORY_NONCE_ACTION ); ?>
								<input type="hidden" name="memory_id" value="<?php echo (int) $memory['id']; ?>" />
								<input type="hidden" name="delete_nonce" value="<?php echo esc_attr( wp_create_nonce( E2M_MEMORY_DELETE_NONCE ) ); ?>" />
								<button type="submit" name="e2m_memory_action" value="hard_delete" role="menuitem" class="e2m-memory-card__menu-item e2m-memory-card__menu-item--danger">
									<?php esc_html_e( 'Delete', 'e2mconnect' ); ?>
								</button>
							</form>
						<?php endif; ?>
					</div>
				</details>
			</header>

			<h3 class="e2m-memory-card__title">
				<a href="<?php echo esc_url( $edit_url ); ?>">
					<?php echo esc_html( $memory['name'] ); ?>
				</a>
			</h3>

			<p class="e2m-memory-card__desc">
				<?php echo esc_html( $memory['description'] ); ?>
			</p>
		</div>

		<footer class="e2m-memory-card__footer">
			<span class="e2m-memory-card__footer-author"><?php e2m_memory_admin_render_byline( $memory ); ?></span>
			<span class="e2m-memory-card__footer-sep" aria-hidden="true">·</span>
			<span class="e2m-memory-card__footer-time"><?php echo esc_html( $updated ); ?></span>
		</footer>
	</article>
	<?php
}

/**
 * Render a single nonce-protected form inside the card's three-dot menu.
 * Kept small + dedicated rather than the previous closure to make the
 * menu markup itself easier to scan.
 */
function e2m_memory_admin_render_menu_form( int $memory_id, string $action, string $label ): void {
	?>
	<form method="post" class="e2m-memory-card__menu-form">
		<?php wp_nonce_field( E2M_MEMORY_NONCE_ACTION ); ?>
		<input type="hidden" name="memory_id" value="<?php echo (int) $memory_id; ?>" />
		<button type="submit" name="e2m_memory_action" value="<?php echo esc_attr( $action ); ?>" role="menuitem" class="e2m-memory-card__menu-item">
			<?php echo esc_html( $label ); ?>
		</button>
	</form>
	<?php
}

// ─────────────────────────────────────────────────────────────────────
// Create / Edit form.
// ─────────────────────────────────────────────────────────────────────

function e2m_memory_admin_render_form( ?array $memory ): void {
	$is_new      = $memory === null;
	$can_system  = e2m_memory_can_escalate_to_system();
	$tags_csv    = $is_new ? '' : implode( ', ', (array) ( $memory['tags'] ?? [] ) );
	?>
	<div class="e2m-memory-form-toolbar">
		<a href="<?php echo esc_url( e2m_memory_admin_url() ); ?>" class="button button-secondary">&larr; <?php esc_html_e( 'Back to list', 'e2mconnect' ); ?></a>
		<?php if ( ! $is_new && current_user_can( 'manage_options' ) ): ?>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this memory? This cannot be undone.', 'e2mconnect' ) ); ?>');">
				<?php wp_nonce_field( E2M_MEMORY_NONCE_ACTION ); ?>
				<input type="hidden" name="memory_id" value="<?php echo (int) $memory['id']; ?>" />
				<input type="hidden" name="delete_nonce" value="<?php echo esc_attr( wp_create_nonce( E2M_MEMORY_DELETE_NONCE ) ); ?>" />
				<button type="submit" name="e2m_memory_action" value="hard_delete" class="button button-link-delete"><?php esc_html_e( 'Delete permanently', 'e2mconnect' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<form method="post" class="so-card e2m-memory-form">
		<?php wp_nonce_field( E2M_MEMORY_NONCE_ACTION ); ?>
		<input type="hidden" name="e2m_memory_action" value="save" />
		<?php if ( ! $is_new ): ?>
			<input type="hidden" name="memory_id" value="<?php echo (int) $memory['id']; ?>" />
		<?php endif; ?>

		<h2 class="so-card-title"><?php echo esc_html( $is_new ? __( 'New memory', 'e2mconnect' ) : __( 'Edit memory', 'e2mconnect' ) ); ?></h2>

		<?php if ( ! $is_new ): ?>
			<div class="e2m-memory-form__provenance">
				<?php e2m_memory_admin_render_byline( $memory ); ?>
				<span class="e2m-memory-byline__sep" aria-hidden="true">·</span>
				<span class="e2m-memory-card__meta">
					<?php
					printf(
						/* translators: 1: created relative time, 2: last-update relative time */
						esc_html__( 'Created %1$s · Last updated %2$s', 'e2mconnect' ),
						esc_html( e2m_memory_admin_format_relative( (string) ( $memory['created_at'] ?? '' ) ) ),
						esc_html( e2m_memory_admin_format_relative( (string) ( $memory['updated_at'] ?? '' ) ) )
					);
					?>
				</span>
			</div>
		<?php endif; ?>

		<label class="e2m-memory-form__field">
			<strong><?php esc_html_e( 'Name', 'e2mconnect' ); ?></strong>
			<input type="text" name="memory_name" required maxlength="200" placeholder="<?php esc_attr_e( 'A short, specific title — e.g. "Always use Bricks for new pages"', 'e2mconnect' ); ?>" value="<?php echo esc_attr( (string) ( $memory['name'] ?? '' ) ); ?>" />
		</label>

		<label class="e2m-memory-form__field">
			<strong><?php esc_html_e( 'Description', 'e2mconnect' ); ?></strong>
			<span class="e2m-memory-form__field-hint"><?php esc_html_e( 'One-line hook (10–300 chars). Shown in the list — be specific.', 'e2mconnect' ); ?></span>
			<input type="text" name="memory_description" required maxlength="300" minlength="10" placeholder="<?php esc_attr_e( 'What this memory contains — used in the index to decide relevance.', 'e2mconnect' ); ?>" value="<?php echo esc_attr( (string) ( $memory['description'] ?? '' ) ); ?>" />
		</label>

		<div class="e2m-memory-form__row">
			<div class="e2m-memory-form__field">
				<strong><?php esc_html_e( 'Type', 'e2mconnect' ); ?></strong>
				<?php
				e2m_memory_admin_render_dropdown(
					'memory_type',
					[
						'context'   => __( 'Context', 'e2mconnect' ),
						'rules'     => __( 'Rules', 'e2mconnect' ),
						'profile'   => __( 'Profile', 'e2mconnect' ),
						'reference' => __( 'Reference', 'e2mconnect' ),
					],
					(string) ( $memory['type'] ?? 'context' ),
					false
				);
				?>
			</div>
			<div class="e2m-memory-form__field">
				<strong><?php esc_html_e( 'Visibility', 'e2mconnect' ); ?></strong>
				<?php
				$visibility_options = [
					'private' => __( 'Private', 'e2mconnect' ),
					'team'    => __( 'Team', 'e2mconnect' ),
				];
				if ( $can_system ) {
					$visibility_options['system'] = __( 'System', 'e2mconnect' );
				}
				e2m_memory_admin_render_dropdown(
					'memory_visibility',
					$visibility_options,
					(string) ( $memory['visibility'] ?? 'team' ),
					false
				);
				?>
				<?php if ( $can_system ): ?>
					<span class="e2m-memory-form__hint"><?php esc_html_e( 'System applies to every agent connection on this site (admin-only).', 'e2mconnect' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="e2m-memory-form__field">
				<strong><?php esc_html_e( 'Status', 'e2mconnect' ); ?></strong>
				<?php
				// Audit fix [HIGH]: preserve pending_review status. Without
				// this option, the dropdown would default to 'active' and
				// saving a pending memory would silently promote it.
				$current_status  = (string) ( $memory['status'] ?? 'active' );
				$status_options  = [];
				if ( $current_status === 'pending_review' ) {
					$status_options['pending_review'] = __( 'Pending review — keep until approved', 'e2mconnect' );
				}
				$status_options['active'] = __( 'Active', 'e2mconnect' );
				$status_options['always'] = __( 'Always', 'e2mconnect' );
				$status_options['pinned'] = __( 'Pinned', 'e2mconnect' );
				if ( ! $is_new ) {
					$status_options['archived'] = __( 'Archived', 'e2mconnect' );
				}
				e2m_memory_admin_render_dropdown( 'memory_status', $status_options, $current_status, false );
				?>
			</div>
		</div>

		<label class="e2m-memory-form__field">
			<strong><?php esc_html_e( 'Content', 'e2mconnect' ); ?></strong>
			<span class="e2m-memory-form__field-hint"><?php esc_html_e( '30–2048 characters. Rules-type must include **Why:** and **How to apply:**. Context must use absolute dates (no "today", "Thursday").', 'e2mconnect' ); ?></span>
			<textarea name="memory_content" required minlength="30" maxlength="2048" rows="10" placeholder="<?php esc_attr_e( "The full memory body. Empty seeds ship zero tokens — fill this in only when you have a rule, context, profile detail, or reference worth keeping.", 'e2mconnect' ); ?>"><?php echo esc_textarea( (string) ( $memory['content'] ?? '' ) ); ?></textarea>
		</label>

		<div class="e2m-memory-form__row">
			<label class="e2m-memory-form__field">
				<strong><?php esc_html_e( 'Slug (optional)', 'e2mconnect' ); ?></strong>
				<span class="e2m-memory-form__field-hint"><?php esc_html_e( 'Lowercase letters, digits, hyphens. Makes the memory callable by name from agents.', 'e2mconnect' ); ?></span>
				<input type="text" name="memory_slug" pattern="[a-z0-9-]*" maxlength="100" value="<?php echo esc_attr( (string) ( $memory['slug'] ?? '' ) ); ?>" />
			</label>
			<label class="e2m-memory-form__field">
				<strong><?php esc_html_e( 'Expires at (optional)', 'e2mconnect' ); ?></strong>
				<span class="e2m-memory-form__field-hint"><?php esc_html_e( 'YYYY-MM-DD HH:MM. Past values mark the memory stale.', 'e2mconnect' ); ?></span>
				<input type="text" name="memory_expires_at" placeholder="2026-09-01 00:00" value="<?php echo esc_attr( (string) ( $memory['expires_at'] ?? '' ) ); ?>" />
			</label>
		</div>

		<label class="e2m-memory-form__field">
			<strong><?php esc_html_e( 'Tags', 'e2mconnect' ); ?></strong>
			<span class="e2m-memory-form__field-hint"><?php esc_html_e( 'Comma-separated. Lowercase, max 10.', 'e2mconnect' ); ?></span>
			<input type="text" name="memory_tags" value="<?php echo esc_attr( $tags_csv ); ?>" />
		</label>

		<div class="e2m-memory-form__footer">
			<a href="<?php echo esc_url( e2m_memory_admin_url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'e2mconnect' ); ?></a>
			<button type="submit" class="button button-primary"><?php echo esc_html( $is_new ? __( 'Create memory', 'e2mconnect' ) : __( 'Save changes', 'e2mconnect' ) ); ?></button>
		</div>
	</form>
	<?php
}

// ─────────────────────────────────────────────────────────────────────
// Small helpers.
// ─────────────────────────────────────────────────────────────────────

/**
 * Visual metadata per status. Tone determines the CSS modifier applied
 * to the status chip — `brand` for always/pinned (the only states that
 * earn the brand-purple accent), `neutral` for everything else. Icons
 * carry the semantic meaning so screen readers and color-blind users
 * still get the signal.
 *
 * @return array{label:string, tone:string, icon:string}
 */
function e2m_memory_admin_status_meta( string $status ): array {
	$map = [
		'active'         => [ 'label' => __( 'Active', 'e2mconnect' ),  'tone' => 'neutral', 'icon' => '●' ],
		'always'         => [ 'label' => __( 'Always', 'e2mconnect' ),  'tone' => 'brand',   'icon' => '★' ],
		'pinned'         => [ 'label' => __( 'Pinned', 'e2mconnect' ),  'tone' => 'brand',   'icon' => '📌' ],
		'pending_review' => [ 'label' => __( 'Pending', 'e2mconnect' ), 'tone' => 'muted',   'icon' => '·' ],
		'stale'          => [ 'label' => __( 'Stale', 'e2mconnect' ),   'tone' => 'muted',   'icon' => '⌛' ],
		'disputed'       => [ 'label' => __( 'Disputed', 'e2mconnect' ),'tone' => 'muted',   'icon' => '⚠' ],
		'archived'       => [ 'label' => __( 'Archived', 'e2mconnect' ),'tone' => 'muted',   'icon' => '◌' ],
	];
	return $map[ $status ] ?? $map['active'];
}

/**
 * Render the "By X · last edited by Y" provenance line for a memory.
 *
 * Three display modes:
 *   - Never edited (author === last_edited_by): "Sagar"
 *   - Edited by same person who created: "Sagar"
 *   - Edited by a different person: "Sagar · last edited by Aditya"
 *
 * Memory `metadata.origin === 'seed'` (the four blank seeds installed on
 * activation) renders as "Seeded by E2M Connect" so admins can quickly tell
 * the difference between a placeholder and a real entry. Seed memories
 * that have been customized show the editor instead.
 *
 * Echos directly; safe markup escaped via esc_html / esc_url / esc_attr.
 */
function e2m_memory_admin_render_byline( array $memory ): void {
	$author          = (array) ( $memory['author'] ?? [] );
	$editor          = (array) ( $memory['last_edited_by'] ?? [] );
	$is_seed         = ( $memory['metadata']['origin'] ?? '' ) === 'seed';
	$was_edited      = ! empty( $memory['updated_at'] ) && ! empty( $memory['created_at'] ) && $memory['updated_at'] !== $memory['created_at'];
	$different_editor = ! empty( $editor['id'] ) && (int) ( $author['id'] ?? 0 ) !== (int) ( $editor['id'] ?? 0 );

	// Seed memories that haven't been customized: hide the author label
	// (it's always user 1, that's noise) and show the E2M Connect brand
	// attribution instead. Same shape as a normal byline (icon + name)
	// so the row reads consistently regardless of origin.
	if ( $is_seed && ! $was_edited ) {
		?>
		<span class="e2m-memory-byline e2m-memory-byline--seed">
			<svg class="e2m-memory-byline__icon" width="16" height="16" viewBox="0 0 22 22" fill="none" aria-hidden="true">
				<path d="M16.2909 12.863C16.2968 12.4597 16.0112 12.3875 15.6658 12.4043C15.05 12.3984 14.4341 12.3984 13.8182 12.3875C13.2612 12.3782 12.4982 12.4269 12.0386 12.0514C11.637 11.722 11.5665 11.249 11.732 10.823C11.9765 10.1886 12.7184 9.91893 13.3611 9.96514C13.9678 10.029 14.3585 9.85171 13.8963 9.27365C13.6056 8.85102 11.9773 6.54969 11.5068 5.9061C11.1547 5.55237 10.9035 6.08842 10.712 6.35309C10.2129 7.06222 8.86855 8.94597 8.70975 9.17703C8.61816 9.32995 8.47365 9.4627 8.44088 9.64082C8.39803 10.0525 9.0097 9.97606 9.26008 9.96178C9.96334 9.93657 10.7229 10.2987 10.8674 11.0246C10.9943 11.6103 10.5658 12.206 9.97006 12.2841C9.37267 12.3454 8.924 11.8455 8.34594 11.7766C7.01085 11.5195 5.72197 12.5715 5.70769 13.9284C5.69928 14.7669 6.12863 15.5349 6.87221 15.934C7.5679 16.3062 8.4392 16.3087 9.11641 15.8903C9.61045 15.5844 10.0028 15.0694 10.1146 14.4955C10.2146 13.9813 10.1658 13.3142 10.5851 12.9286C11.0749 12.4774 11.9353 12.6815 12.2731 13.2193C12.4058 13.4226 12.4688 13.6663 12.468 13.9082C12.468 14.3619 12.468 15.1711 12.4688 15.6139C12.4512 15.829 12.5184 16.0508 12.7503 16.1113C13.7199 16.1709 14.9189 16.1491 15.9675 16.1188C16.2313 16.0819 16.2993 15.8273 16.2901 15.5971C16.2901 14.7174 16.2951 13.6461 16.2909 12.863Z" fill="currentColor"/>
			</svg>
			<span class="e2m-memory-byline__name">E2M Connect</span>
		</span>
		<?php
		return;
	}

	?>
	<span class="e2m-memory-byline">
		<?php if ( ! empty( $author['avatar_url'] ) ): ?>
			<img class="e2m-memory-byline__avatar" src="<?php echo esc_url( $author['avatar_url'] ); ?>" alt="" width="20" height="20" />
		<?php endif; ?>
		<span class="e2m-memory-byline__name"><?php echo esc_html( (string) ( $author['name'] ?? __( 'Unknown', 'e2mconnect' ) ) ); ?></span>
		<?php if ( $different_editor ): ?>
			<span class="e2m-memory-byline__sep" aria-hidden="true">·</span>
			<span class="e2m-memory-byline__edit">
				<?php
				printf(
					/* translators: %s: name of the last editor */
					esc_html__( 'edited by %s', 'e2mconnect' ),
					'<span class="e2m-memory-byline__name">' . esc_html( (string) ( $editor['name'] ?? '' ) ) . '</span>'
				);
				?>
			</span>
		<?php endif; ?>
	</span>
	<?php
}

/**
 * Inline SVG for the type-icon badge in the card header.
 * 24x24, currentColor stroke — matches the sidebar icon style.
 *
 * @return string Raw SVG markup. Caller is responsible for any wrapping.
 */
function e2m_memory_admin_type_icon( string $type ): string {
	$icons = [
		'context'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/></svg>',
		'rules'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
		'profile'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'reference' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
	];
	return $icons[ $type ] ?? $icons['context'];
}

/**
 * "3 days ago" / "12 min ago" / falls back to absolute date past 30d.
 */
function e2m_memory_admin_format_relative( string $gmt ): string {
	if ( $gmt === '' ) {
		return __( 'unknown', 'e2mconnect' );
	}
	$ts = strtotime( $gmt . ' GMT' );
	if ( $ts === false ) {
		return $gmt;
	}
	$diff = max( 0, time() - $ts );
	if ( $diff < 60 ) {
		return __( 'just now', 'e2mconnect' );
	}
	if ( $diff < 3600 ) {
		/* translators: %d: minutes */
		return sprintf( __( '%d min ago', 'e2mconnect' ), (int) ( $diff / 60 ) );
	}
	if ( $diff < 86400 ) {
		/* translators: %d: hours */
		return sprintf( __( '%d h ago', 'e2mconnect' ), (int) ( $diff / 3600 ) );
	}
	$days = (int) ( $diff / 86400 );
	if ( $days < 30 ) {
		/* translators: %d: days */
		return sprintf( __( '%d days ago', 'e2mconnect' ), $days );
	}
	return gmdate( 'Y-m-d', $ts );
}
