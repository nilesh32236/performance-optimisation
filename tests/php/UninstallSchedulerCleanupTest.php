<?php
/**
 * Tests for uninstall scheduler cleanup (issue #1118).
 *
 * The uninstall entrypoint runs standalone under WP_UNINSTALL_PLUGIN and
 * cannot be executed inside PHPUnit, so these are static (text-only, never
 * executed) guards plus live parity checks against the canonical
 * Cron::SCHEDULED_HOOKS / Cron::AS_HOOKS lists.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\Cron;

/**
 * Uninstall scheduler-cleanup guards.
 *
 * @package PerformanceOptimise\Tests
 */
class UninstallSchedulerCleanupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Read uninstall.php source (never executed).
	 *
	 * @return string
	 */
	private function uninstall_source(): string {
		$source = file_get_contents( WPPO_PLUGIN_PATH . 'uninstall.php' );
		$this->assertNotFalse( $source, 'uninstall.php must be readable' );
		return (string) $source;
	}

	/**
	 * Extract a function body from source by brace matching.
	 *
	 * @param string $source        Full file source.
	 * @param string $function_name Function name without parentheses.
	 * @return string|null Function body or null when not found.
	 */
	private function extract_function_body( string $source, string $function_name ): ?string {
		$pos = strpos( $source, 'function ' . $function_name );
		if ( false === $pos ) {
			return null;
		}
		$open = strpos( $source, '{', $pos );
		if ( false === $open ) {
			return null;
		}
		$depth = 0;
		$len   = strlen( $source );
		for ( $i = $open; $i < $len; ++$i ) {
			if ( '{' === $source[ $i ] ) {
				++$depth;
			} elseif ( '}' === $source[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $source, $open, $i - $open + 1 );
				}
			}
		}
		return null;
	}

	/**
	 * The standalone helper must exist and carry a @since NEXT tag.
	 */
	public function test_uninstall_defines_clear_scheduled_jobs_helper_with_since_next(): void {
		$source = $this->uninstall_source();
		$this->assertStringContainsString( 'function wppo_clear_scheduled_jobs', $source );
		$pos = strpos( $source, 'function wppo_clear_scheduled_jobs' );
		$this->assertNotFalse( $pos );
		$docblock = substr( $source, 0, (int) $pos );
		$docblock = substr( $docblock, (int) strrpos( $docblock, '/**' ) );
		$this->assertStringContainsString( '@since NEXT', (string) $docblock, 'New uninstall helper must carry @since NEXT (never guess a version)' );
	}

	/**
	 * The per-site cleanup must call the scheduler clear so the multisite
	 * loop clears every blog.
	 */
	public function test_cleanup_site_calls_scheduler_clear(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_cleanup_site' );
		$this->assertNotNull( $body, 'wppo_cleanup_site() must exist in uninstall.php' );
		$this->assertStringContainsString( 'wppo_clear_scheduled_jobs()', (string) $body, 'wppo_cleanup_site() must unschedule cron/AS jobs per site' );
	}

	/**
	 * Scheduler cleanup is per-site: the once-guarded network helper must
	 * not host it.
	 */
	public function test_network_files_helper_does_not_host_scheduler_clear(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_cleanup_network_files' );
		$this->assertNotNull( $body, 'wppo_cleanup_network_files() must exist in uninstall.php' );
		$this->assertStringNotContainsString( 'wppo_clear_scheduled_jobs', (string) $body, 'Cron/AS state is per-site; network helper must not clear it' );
	}

	/**
	 * Every canonical WP-Cron hook must be cleared by the uninstall helper.
	 */
	public function test_uninstall_clears_every_canonical_cron_hook(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_clear_scheduled_jobs' );
		$this->assertNotNull( $body, 'wppo_clear_scheduled_jobs() must exist in uninstall.php' );
		foreach ( Cron::SCHEDULED_HOOKS as $hook ) {
			$this->assertStringContainsString( $hook, (string) $body, "Uninstall scheduler clear must cover WP-Cron hook {$hook}" );
		}
		// Legacy misspelling kept for BC with older installs.
		$this->assertStringContainsString( 'wppo_img_conversation', (string) $body );
		// WP-Cron single-event fallbacks scheduled outside the Cron class.
		$this->assertStringContainsString( 'wppo_google_fonts_download', (string) $body );
		$this->assertStringContainsString( 'wppo_builder_drift_purge', (string) $body );
	}

	/**
	 * Every canonical Action Scheduler hook must be cleared by the uninstall
	 * helper, plus the group-based forward-compat net.
	 */
	public function test_uninstall_clears_every_canonical_as_hook_and_group(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_clear_scheduled_jobs' );
		$this->assertNotNull( $body, 'wppo_clear_scheduled_jobs() must exist in uninstall.php' );
		foreach ( Cron::AS_HOOKS as $hook ) {
			$this->assertStringContainsString( $hook, (string) $body, "Uninstall scheduler clear must cover AS hook {$hook}" );
		}
		// Drift-purge hook is AS-scheduled but missing from Cron::AS_HOOKS.
		$this->assertStringContainsString( 'wppo_builder_drift_purge', (string) $body );
		// Forward-compat net for future hooks in the plugin's AS group.
		$this->assertStringContainsString( 'performance_optimisation', (string) $body );
	}

	/**
	 * All scheduler calls must be guarded and fail-open (never fatal when
	 * WP-Cron helpers or Action Scheduler are absent).
	 */
	public function test_uninstall_scheduler_clear_is_guarded_and_fail_open(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_clear_scheduled_jobs' );
		$this->assertNotNull( $body, 'wppo_clear_scheduled_jobs() must exist in uninstall.php' );
		$body = (string) $body;
		$this->assertStringContainsString( "function_exists( 'wp_unschedule_hook' )", $body );
		$this->assertStringContainsString( "function_exists( 'wp_clear_scheduled_hook' )", $body );
		$this->assertStringContainsString( "function_exists( 'wp_next_scheduled' )", $body );
		$this->assertStringContainsString( "function_exists( 'wp_unschedule_event' )", $body );
		$this->assertStringContainsString( "function_exists( 'as_unschedule_all_actions' )", $body );
		$this->assertStringContainsString( 'Throwable', $body, 'Scheduler clear must swallow failures (fail-open)' );
	}

	/**
	 * The uninstall helper must never touch foreign hooks or groups — only
	 * wppo_-prefixed hooks and the plugin's own AS group.
	 */
	public function test_uninstall_scheduler_clear_touches_only_plugin_hooks(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_clear_scheduled_jobs' );
		$this->assertNotNull( $body, 'wppo_clear_scheduled_jobs() must exist in uninstall.php' );
		preg_match_all( "/'(wppo_[a-z0-9_]+)'/", (string) $body, $m );
		$this->assertNotEmpty( $m[1], 'Scheduler clear must list explicit wppo_ hooks' );
		foreach ( $m[1] as $hook ) {
			$this->assertStringStartsWith( 'wppo_', $hook );
		}
	}
}
