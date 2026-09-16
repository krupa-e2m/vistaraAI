<?php
/**
 * E2M Connect MCP - Domain Lock.
 *
 * Remembers the site_url / home_url recorded at plugin activation. On
 * every ability invocation we compare those against the current values
 * and, when they diverge, refuse to execute and flag safe mode. This
 * protects against the staging-restored-over-production scenario where
 * an AI agent could run writes against what it thinks is a staging
 * database on the wrong hostname.
 *
 * it with an explicit admin "trust new domain" flow so site migrations
 * don't require code changes to re-enable.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Domain_Lock {

	private const LOCK_OPTION = 'e2m_engine_domain_lock';

	/**
	 * Capture the initial lock state. Called from the plugin activation
	 * hook. If a previous record exists we leave it alone so subsequent
	 * (re)activations don't silently re-trust a changed domain.
	 */
	public static function capture_on_activate(): void {
		$existing = get_option( self::LOCK_OPTION );
		if ( is_array( $existing ) && ! empty( $existing['site_url'] ) ) {
			return;
		}
		update_option(
			self::LOCK_OPTION,
			[
				'site_url'  => (string) get_site_url(),
				'home_url'  => (string) get_home_url(),
				'locked_at' => gmdate( 'c' ),
			],
			false
		);
	}

	/**
	 * Return true when the current request is running against the same
	 * domain the plugin was activated on. Returns false to signal the
	 * caller should abort and flip safe mode.
	 */
	public static function is_domain_trusted(): bool {
		$settings = e2m_engine_get_settings();
		if ( empty( $settings['domain_lock_enabled'] ) ) {
			return true;
		}

		$record = get_option( self::LOCK_OPTION );
		if ( ! is_array( $record ) || empty( $record['site_url'] ) ) {
			return true; // no baseline yet
		}

		return (string) $record['site_url'] === (string) get_site_url()
			&& (string) $record['home_url'] === (string) get_home_url();
	}

	/**
	 * Trust the current domain as the new baseline. Called by the admin
	 * when they intentionally migrated the site. Creating a dedicated
	 * ability for this would be convenient but we keep it admin-only to
	 * avoid an agent auto-trusting a foreign host.
	 */
	public static function trust_current_domain(): void {
		update_option(
			self::LOCK_OPTION,
			[
				'site_url'  => (string) get_site_url(),
				'home_url'  => (string) get_home_url(),
				'locked_at' => gmdate( 'c' ),
			],
			false
		);
	}

	/**
	 * Read-only snapshot of the current lock record for UI + status tools.
	 *
	 * @return array{enabled: bool, locked_site_url: string, locked_home_url: string, current_site_url: string, trusted: bool}
	 */
	public static function status(): array {
		$settings = e2m_engine_get_settings();
		$record   = get_option( self::LOCK_OPTION );
		return [
			'enabled'          => ! empty( $settings['domain_lock_enabled'] ),
			'locked_site_url'  => is_array( $record ) ? (string) ( $record['site_url'] ?? '' ) : '',
			'locked_home_url'  => is_array( $record ) ? (string) ( $record['home_url'] ?? '' ) : '',
			'current_site_url' => (string) get_site_url(),
			'trusted'          => self::is_domain_trusted(),
		];
	}

	/**
	 * WP_Error emitted by callers when the domain doesn't match.
	 */
	public static function untrusted_domain_error(): WP_Error {
		$status = self::status();
		return new WP_Error(
			'e2m_domain_locked',
			sprintf(
				/* translators: %1$s trusted URL, %2$s current URL. */
				__( 'E2M Connect MCP was activated on %1$s but is running on %2$s. An admin must approve the new domain before MCP calls are accepted.', 'e2mconnect' ),
				$status['locked_site_url'] ?: '(unknown)',
				$status['current_site_url']
			),
			[ 'status' => 403 ]
		);
	}
}
