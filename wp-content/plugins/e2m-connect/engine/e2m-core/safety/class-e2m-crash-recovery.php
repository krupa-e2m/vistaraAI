<?php
/**
 * E2M Connect MCP - Crash Recovery.
 *
 * Registers a PHP shutdown handler that inspects the last error record.
 * When a fatal originated inside our sandbox directory, we auto-enable
 * safe mode and append a flag file so subsequent page loads skip the
 * offending file until the admin resolves it.
 *
 * The goal is to keep MCP functional even when a user-authored sandbox
 * snippet blows up - the agent can then call e2m/disable-file to
 * quarantine the offending file and re-enable safe mode as soon as the
 * issue is fixed.
 *
 * @package  E2M Connect_MCP
 * @since    1.0.0
 * @license  GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class E2M_Crash_Recovery {

	private const FLAG_OPTION = 'e2m_engine_crash_flag';

	/**
	 * Attach the shutdown handler exactly once. Idempotent so repeated
	 * plugin reloads during dev don't stack callbacks.
	 */
	public static function register(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$settings = e2m_engine_get_settings();
		if ( empty( $settings['crash_recovery_enabled'] ) ) {
			return;
		}
		register_shutdown_function( [ self::class, 'on_shutdown' ] );
		$registered = true;
	}

	/**
	 * Shutdown-time inspector. PHP's error_get_last() returns the most
	 * recent error; we only escalate fatals (E_ERROR, E_PARSE, etc.)
	 * that touched a file under our sandbox directory.
	 */
	public static function on_shutdown(): void {
		$err = error_get_last();
		if ( ! is_array( $err ) || empty( $err['type'] ) || empty( $err['file'] ) ) {
			return;
		}

		// Fatals that PHP cannot recover from.
		$fatal = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		if ( ! in_array( (int) $err['type'], $fatal, true ) ) {
			return;
		}

		$sandbox_dir = defined( 'E2M_ENGINE_SANDBOX_DIR' ) ? (string) E2M_ENGINE_SANDBOX_DIR : '';
		if ( $sandbox_dir === '' || ! str_starts_with( (string) $err['file'], $sandbox_dir ) ) {
			return;
		}

		// Store the crash record so the admin UI can surface it on next
		// request, and auto-flip safe mode on so MCP keeps responding.
		update_option(
			self::FLAG_OPTION,
			[
				'file'     => (string) $err['file'],
				'line'     => (int) ( $err['line'] ?? 0 ),
				'message'  => (string) $err['message'],
				'ts'       => gmdate( 'c' ),
			],
			false
		);

		if ( function_exists( 'e2m_engine_toggle_safe_mode' ) ) {
			e2m_engine_toggle_safe_mode( true );
		}
	}

	/**
	 * Status for the status ability and admin UI.
	 *
	 * @return array{tripped: bool, file: string, line: int, message: string, ts: string}
	 */
	public static function status(): array {
		$record = get_option( self::FLAG_OPTION );
		if ( ! is_array( $record ) || empty( $record['file'] ) ) {
			return [ 'tripped' => false, 'file' => '', 'line' => 0, 'message' => '', 'ts' => '' ];
		}
		return [
			'tripped' => true,
			'file'    => (string) $record['file'],
			'line'    => (int) ( $record['line'] ?? 0 ),
			'message' => (string) $record['message'],
			'ts'      => (string) ( $record['ts'] ?? '' ),
		];
	}

	/**
	 * Clear the crash flag after the offending file has been quarantined
	 * or fixed.
	 */
	public static function clear(): void {
		delete_option( self::FLAG_OPTION );
	}
}
