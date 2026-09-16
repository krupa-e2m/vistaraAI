<?php
/**
 * E2M Connect — Backups & Rollback hub tab.
 *
 * A first-class, discoverable place for the operator to see every backup taken
 * before the agent changed a theme file, an Elementor page, or the database,
 * and to roll any of them back. Added via the engine's own hub extension hooks
 * (e2m_connect_hub_*), so it needs ZERO edits to the vendored engine and
 * survives a re-vendor — the same pattern as the Dashboard tab.
 *
 * The rollback itself is handled by the engine AJAX endpoint
 * `e2m_engine_rollback_backup` (registered in plugin_loader.php, implemented in
 * admin-safety-tab.php), which dispatches to E2M_File_Backup / E2M_Elementor_Backup
 * / E2M_DB_Backup and snapshots the current state first so it is reversible.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Admin;

final class Backups {

	const TAB = 'backups';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'e2m_connect_hub_valid_tabs', [ self::class, 'validTabs' ] );
		add_filter( 'e2m_connect_hub_nav', [ self::class, 'nav' ] );
		add_filter( 'e2m_connect_hub_page_meta', [ self::class, 'pageMeta' ] );
		add_action( 'e2m_connect_hub_render', [ self::class, 'render' ], 10, 2 );
	}

	public static function validTabs( $tabs ) {
		$tabs = is_array( $tabs ) ? $tabs : [];
		if ( ! in_array( self::TAB, $tabs, true ) ) {
			$tabs[] = self::TAB;
		}
		return $tabs;
	}

	public static function nav( $items ) {
		$items = is_array( $items ) ? $items : [];
		$items[ self::TAB ] = [
			'icon'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6c0 1.66-4 3-9 3s-9-1.34-9-3V7"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/><ellipse cx="12" cy="6" rx="9" ry="3"/></svg>',
			'label' => __( 'Backups', 'e2m-connect' ),
		];
		return $items;
	}

	public static function pageMeta( $meta ) {
		$meta = is_array( $meta ) ? $meta : [];
		$meta[ self::TAB ] = [
			'title' => __( 'Backups & Rollback', 'e2m-connect' ),
			'desc'  => __( 'Automatic backups taken before the agent edits files, Elementor pages, or the database — roll back any one.', 'e2m-connect' ),
		];
		return $meta;
	}

	/** @param string $tab */
	public static function render( $tab, $settings = [] ): void {
		if ( $tab !== self::TAB ) {
			return;
		}

		$retention = (int) ( is_array( $settings ) ? ( $settings['backup_retention_days'] ?? 30 ) : 30 );
		$backups   = class_exists( '\\E2M_Backup_Store' ) ? \E2M_Backup_Store::list() : [];
		$backups   = array_slice( $backups, 0, 200 );
		$nonce     = wp_create_nonce( 'e2m_engine_safety_nonce' );
		$type_labels = [
			'file'      => __( 'File', 'e2m-connect' ),
			'elementor' => __( 'Elementor', 'e2m-connect' ),
			'db'        => __( 'Database', 'e2m-connect' ),
		];
		?>
		<style>
			.e2m-bk { max-width: 1100px; }
			.e2m-bk-note { opacity: .75; margin: 0 0 16px; }
			.e2m-bk-table { width: 100%; border-collapse: collapse; font-size: 13px; }
			.e2m-bk-table th, .e2m-bk-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,.08); vertical-align: middle; }
			.e2m-bk-table th { font-weight: 600; opacity: .6; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; }
			.e2m-bk-table tbody tr:hover { background: rgba(255,255,255,.03); }
			.e2m-bk-target { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; max-width: 360px; }
			.e2m-bk-reason { opacity: .65; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
			.e2m-bk-type { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; background:rgba(255,106,26,.15); color:#FF8A3D; }
			.e2m-bk-btn { cursor:pointer; }
			.e2m-bk-btn[disabled] { opacity:.5; cursor:progress; }
			.e2m-bk-status { margin-top:14px; min-height:20px; font-weight:600; }
			.e2m-bk-status.ok { color:#34d399; }
			.e2m-bk-status.err { color:#f87171; }
			.e2m-bk-empty { opacity:.7; padding:20px 0; }
		</style>

		<div class="e2m-bk">
			<p class="e2m-bk-note">
				<?php
				printf(
					/* translators: %d = retention days */
					esc_html__( 'Kept for %d days, then cleaned automatically. Each rollback first snapshots the current state, so it can itself be undone.', 'e2m-connect' ),
					$retention
				);
				?>
			</p>

			<?php if ( empty( $backups ) ) : ?>
				<p class="e2m-bk-empty" id="e2m-bk-empty">
					<?php esc_html_e( 'No backups yet. One is created automatically the first time the agent changes a file, an Elementor page, or the database.', 'e2m-connect' ); ?>
				</p>
			<?php else : ?>
				<table class="e2m-bk-table" id="e2m-bk-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'e2m-connect' ); ?></th>
							<th><?php esc_html_e( 'Type', 'e2m-connect' ); ?></th>
							<th><?php esc_html_e( 'Target', 'e2m-connect' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'e2m-connect' ); ?></th>
							<th><?php esc_html_e( 'Action', 'e2m-connect' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $b ) :
							$id   = (string) ( $b['id'] ?? '' );
							$type = (string) ( $b['type'] ?? '' );
							$rel  = (string) ( $b['meta']['rel_path'] ?? '' );
							$target = $rel !== '' ? $rel : (string) ( $b['target'] ?? '' );
							$when = ! empty( $b['created_ts'] ) ? wp_date( 'M j, Y H:i', (int) $b['created_ts'] ) : (string) ( $b['created_at'] ?? '' );
							?>
							<tr data-backup-type="<?php echo esc_attr( $type ); ?>">
								<td><?php echo esc_html( $when ); ?></td>
								<td><span class="e2m-bk-type"><?php echo esc_html( $type_labels[ $type ] ?? $type ); ?></span></td>
								<td class="e2m-bk-target"><?php echo esc_html( $target ); ?></td>
								<td class="e2m-bk-reason"><?php echo esc_html( (string) ( $b['reason'] ?? '' ) ); ?></td>
								<td>
									<button type="button" class="so-btn so-btn-outline so-btn-sm e2m-bk-btn button"
										data-id="<?php echo esc_attr( $id ); ?>"
										data-type="<?php echo esc_attr( $type ); ?>">
										<?php esc_html_e( 'Roll back', 'e2m-connect' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="e2m-bk-status" id="e2m-bk-status" aria-live="polite"></p>
		</div>

		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var confirmMsg = <?php echo wp_json_encode( __( 'Roll back this item to its backed-up state? An undo backup of the current state is created first.', 'e2m-connect' ) ); ?>;
			var status = document.getElementById('e2m-bk-status');

			function flash(text, ok) {
				if (!status) return;
				status.textContent = text;
				status.className = 'e2m-bk-status ' + (ok ? 'ok' : 'err');
			}

			document.querySelectorAll('.e2m-bk-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (!window.confirm(confirmMsg)) return;
					btn.disabled = true;
					flash(<?php echo wp_json_encode( __( 'Rolling back…', 'e2m-connect' ) ); ?>, true);

					var body = new FormData();
					body.append('action', 'e2m_engine_rollback_backup');
					body.append('_ajax_nonce', nonce);
					body.append('backup_id', btn.getAttribute('data-id'));
					body.append('type', btn.getAttribute('data-type'));

					fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							var ok = !!(res && res.success);
							var msg = ok
								? (res.data && res.data.message ? res.data.message : <?php echo wp_json_encode( __( 'Rolled back.', 'e2m-connect' ) ); ?>)
								: (res && res.data ? (res.data.message || res.data) : <?php echo wp_json_encode( __( 'Rollback failed.', 'e2m-connect' ) ); ?>);
							flash(msg, ok);
							if (ok) { setTimeout(function () { window.location.reload(); }, 1200); }
							else { btn.disabled = false; }
						})
						.catch(function () {
							flash(<?php echo wp_json_encode( __( 'Rollback failed (network error).', 'e2m-connect' ) ); ?>, false);
							btn.disabled = false;
						});
				});
			});
		})();
		</script>
		<?php
	}
}
