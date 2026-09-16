<?php
/**
 * E2M Connect MCP - Safety admin tab.
 *
 * Renders the Safety tab and wires the AJAX handler that backs its toggles.
 * Snapshots provide the recovery path for any agent change; this tab gives
 * admins a single place to see safety status, manage protected posts, and
 * gate optional capability groups (e.g. WooCommerce abilities).
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Safety tab content.
 *
 * @param array<string, mixed> $settings Output of e2m_engine_get_settings().
 */
function e2m_engine_render_safety_tab_content( array $settings ): void {
	$wc_active  = function_exists( 'e2m_engine_has_woocommerce' ) && e2m_engine_has_woocommerce();
	$wc_enabled = ! empty( $settings['modules']['woocommerce'] );
	$profile    = (string) ( $settings['capabilities_profile'] ?? 'full' );
	$nonce      = wp_create_nonce( 'e2m_engine_safety_nonce' );

	$profiles = [
		'ultra-minimal' => [
			'label' => __( 'Ultra-minimal', 'e2mconnect' ),
			'desc'  => __( '3 bridge tools only (~3 KB). Every operation goes through discover→inspect→dispatch. Best for token-metered deployments.', 'e2mconnect' ),
		],
		'minimal'  => [
			'label' => __( 'Minimal (recommended)', 'e2mconnect' ),
			'desc'  => __( 'Bridge + ~8 most-used tools (~8 KB). Common ops one-shot, rare ops via bridge.', 'e2mconnect' ),
		],
		'standard' => [
			'label' => __( 'Standard', 'e2mconnect' ),
			'desc'  => __( 'Bridge + every essential + standard-tier tools (~30-50 KB). Common operations and most niche operations work in one shot.', 'e2mconnect' ),
		],
		'full'     => [
			'label' => __( 'Full', 'e2mconnect' ),
			'desc'  => __( 'Every applicable tool exposed directly (~50-140 KB). Largest upfront cost; no bridge hops.', 'e2mconnect' ),
		],
	];
	?>
	<div class="so-safety">

		<!-- ═══ Capabilities profile (token-surface control) ═══ -->
		<section class="so-card" aria-labelledby="so-safety-profile-title">
			<div class="so-card-header">
				<div>
					<h3 class="so-card-title" id="so-safety-profile-title">
						<?php esc_html_e( 'Capabilities Profile', 'e2mconnect' ); ?>
					</h3>
					<p class="so-card-desc">
						<?php esc_html_e( 'How much of the tool catalogue does the AI see upfront? Lower = fewer tokens spent every session; AI uses the bridge for rare operations.', 'e2mconnect' ); ?>
					</p>
				</div>
			</div>
			<fieldset class="so-safety-fieldset">
				<?php foreach ( $profiles as $value => $info ) :
					$id = 'e2m-profile-' . $value;
					?>
					<label for="<?php echo esc_attr( $id ); ?>" class="so-safety-radio">
						<input
							type="radio"
							id="<?php echo esc_attr( $id ); ?>"
							name="e2m_capabilities_profile"
							value="<?php echo esc_attr( $value ); ?>"
							<?php checked( $profile, $value ); ?>
							data-safety-setting="capabilities_profile"
						/>
						<span>
							<strong><?php echo esc_html( $info['label'] ); ?></strong>
							<span class="so-safety-radio-desc"><?php echo esc_html( $info['desc'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
				<p class="so-safety-status" id="e2m-safety-profile-status" aria-live="polite"></p>
			</fieldset>
		</section>

		<!-- ═══ WooCommerce Module ═══ -->
		<section class="so-card" aria-labelledby="so-safety-wc-title">
			<div class="so-card-header">
				<div>
					<h3 class="so-card-title" id="so-safety-wc-title">
						<?php esc_html_e( 'WooCommerce Module', 'e2mconnect' ); ?>
					</h3>
					<p class="so-card-desc">
						<?php esc_html_e( '22 shop abilities for products, categories, tags, orders, and sales reports. Loads only when WooCommerce is active.', 'e2mconnect' ); ?>
					</p>
				</div>
				<?php if ( $wc_active ) : ?>
					<span class="so-badge so-badge-success"><span class="so-badge-dot"></span><?php esc_html_e( 'Active', 'e2mconnect' ); ?></span>
				<?php else : ?>
					<span class="so-badge so-badge-neutral"><span class="so-badge-dot"></span><?php esc_html_e( 'Inactive', 'e2mconnect' ); ?></span>
				<?php endif; ?>
			</div>

			<label for="e2m-wc-toggle" class="so-safety-toggle">
				<input
					type="checkbox"
					id="e2m-wc-toggle"
					<?php checked( $wc_enabled ); ?>
					<?php disabled( ! $wc_active ); ?>
					data-safety-setting="module_woocommerce"
				/>
				<span><?php esc_html_e( 'Expose WooCommerce abilities to MCP clients', 'e2mconnect' ); ?></span>
			</label>
			<?php if ( ! $wc_active ) : ?>
				<p class="so-safety-hint">
					<?php esc_html_e( 'Activate WooCommerce to enable this toggle.', 'e2mconnect' ); ?>
				</p>
			<?php endif; ?>
			<p class="so-safety-status" id="e2m-safety-wc-status" aria-live="polite"></p>
		</section>

		<?php // "Backups & Rollback" lives in its own hub tab (src/Admin/Backups.php). ?>
	</div>

	<script>
	(function() {
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

		function flash(el, text, ok) {
			if (!el) return;
			el.textContent = text;
			el.classList.remove('is-success', 'is-error');
			el.classList.add(ok ? 'is-success' : 'is-error');
			setTimeout(function() {
				el.textContent = '';
				el.classList.remove('is-success', 'is-error');
			}, 3000);
		}

		function post(action, data) {
			var body = new FormData();
			body.append('action', action);
			body.append('_ajax_nonce', nonce);
			Object.keys(data || {}).forEach(function(k) { body.append(k, data[k]); });
			return fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function(r) { return r.json(); });
		}

		var wcToggle = document.getElementById('e2m-wc-toggle');
		if (wcToggle) {
			wcToggle.addEventListener('change', function() {
				var status = document.getElementById('e2m-safety-wc-status');
				post('e2m_engine_save_safety_setting', {
					key: 'module_woocommerce',
					value: wcToggle.checked ? '1' : '0'
				}).then(function(res) {
					flash(status, res && res.success ? <?php echo wp_json_encode(__('Saved', 'e2mconnect')); ?> : (res && res.data ? res.data : <?php echo wp_json_encode(__('Failed to save', 'e2mconnect')); ?>), !!(res && res.success));
				});
			});
		}

		document.querySelectorAll('input[name="e2m_capabilities_profile"]').forEach(function(r) {
			r.addEventListener('change', function() {
				if (!r.checked) return;
				var status = document.getElementById('e2m-safety-profile-status');
				post('e2m_engine_save_safety_setting', {
					key: 'capabilities_profile',
					value: r.value
				}).then(function(res) {
					flash(status, res && res.success ? <?php echo wp_json_encode(__('Saved. Refresh your MCP client to see new tool list.', 'e2mconnect')); ?> : (res && res.data ? res.data : <?php echo wp_json_encode(__('Failed to save', 'e2mconnect')); ?>), !!(res && res.success));
				});
			});
		});
	})();
	</script>

	<?php // Styles for .so-safety* live in e2m-ui/admin-design-system.css §23. ?>
	<?php
}

/**
 * AJAX handler: save a Safety-tab setting.
 *
 * Whitelisted keys today: module_woocommerce, capabilities_profile. Every other key is rejected
 * so drive-by POSTs cannot clobber unrelated settings.
 */
function e2m_engine_ajax_save_safety_setting(): void {
	check_ajax_referer( 'e2m_engine_safety_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'e2mconnect' ), 403 );
	}

	$key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
	$value = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

	$saved = get_option( 'e2m_engine_settings', [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}

	if ( $key === 'module_woocommerce' ) {
		if ( ! isset( $saved['modules'] ) || ! is_array( $saved['modules'] ) ) {
			$saved['modules'] = [];
		}
		$saved['modules']['woocommerce'] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	} elseif ( $key === 'capabilities_profile' ) {
		$allowed_profiles = [ 'ultra-minimal', 'minimal', 'standard', 'full' ];
		if ( ! in_array( $value, $allowed_profiles, true ) ) {
			wp_send_json_error( __( 'Invalid profile.', 'e2mconnect' ) );
		}
		$saved['capabilities_profile'] = $value;
	} else {
		wp_send_json_error( __( 'Unknown safety setting key.', 'e2mconnect' ) );
	}

	update_option( 'e2m_engine_settings', $saved );
	e2m_engine_get_settings( true );
	wp_send_json_success( [ 'key' => $key, 'saved' => true ] );
}

/**
 * AJAX handler: roll back a single backup (file / Elementor / DB) by id.
 * Dispatches to the matching backup class; each one snapshots the current
 * state first so the rollback is itself reversible.
 */
function e2m_engine_ajax_rollback_backup(): void {
	check_ajax_referer( 'e2m_engine_safety_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'e2mconnect' ) ], 403 );
	}

	$backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
	$type      = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );
	if ( $backup_id === '' ) {
		wp_send_json_error( [ 'message' => __( 'Missing backup id.', 'e2mconnect' ) ] );
	}

	// Trust the stored record's type over the posted one when available.
	if ( class_exists( 'E2M_Backup_Store' ) ) {
		$record = E2M_Backup_Store::get( $backup_id );
		if ( $record === null ) {
			wp_send_json_error( [ 'message' => __( 'Backup not found.', 'e2mconnect' ) ] );
		}
		$type = (string) ( $record['type'] ?? $type );
	}

	switch ( $type ) {
		case 'file':
			$result = class_exists( 'E2M_File_Backup' ) ? E2M_File_Backup::rollback( $backup_id ) : null;
			break;
		case 'elementor':
			$result = class_exists( 'E2M_Elementor_Backup' ) ? E2M_Elementor_Backup::restore_export( $backup_id ) : null;
			break;
		case 'db':
			$result = class_exists( 'E2M_DB_Backup' ) ? E2M_DB_Backup::restore( $backup_id ) : null;
			break;
		default:
			wp_send_json_error( [ 'message' => __( 'Unknown backup type.', 'e2mconnect' ) ] );
	}

	if ( $result === null ) {
		wp_send_json_error( [ 'message' => __( 'Backup subsystem unavailable.', 'e2mconnect' ) ] );
	}
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( [
		'message' => __( 'Rolled back. An undo backup of the previous state was created.', 'e2mconnect' ),
		'undo_id' => is_array( $result ) ? ( $result['undo_id'] ?? '' ) : '',
	] );
}
