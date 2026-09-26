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

use PerformanceOptimise\Inc\Job_Registry;

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
	 * The standalone helper must exist and carry a @since 2.2.0 tag.
	 */
	public function test_uninstall_defines_clear_scheduled_jobs_helper_with_since_next(): void {
		$source = $this->uninstall_source();
		$this->assertStringContainsString( 'function wppo_clear_scheduled_jobs', $source );
		$pos = strpos( $source, 'function wppo_clear_scheduled_jobs' );
		$this->assertNotFalse( $pos );
		$docblock = substr( $source, 0, (int) $pos );
		$docblock = substr( $docblock, (int) strrpos( $docblock, '/**' ) );
		$this->assertStringContainsString( '@since 2.2.0', (string) $docblock, 'New uninstall helper must carry @since 2.2.0 (never guess a version)' );
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
		$this->assertStringContainsString( 'Job_Registry::all_cron_hooks()', (string) $body );
		foreach ( Job_Registry::all_cron_hooks() as $hook ) {
			$this->assertStringStartsWith( 'wppo_', $hook );
		}
		// Legacy misspelling and feature-owned fallbacks are registry-owned.
		$this->assertContains( 'wppo_img_conversation', Job_Registry::all_cron_hooks() );
		$this->assertContains( 'wppo_google_fonts_download', Job_Registry::all_cron_hooks() );
		$this->assertContains( 'wppo_builder_drift_purge', Job_Registry::all_cron_hooks() );
		$this->assertContains( 'wppo_upgrade_purge', Job_Registry::all_cron_hooks() );
	}

	/**
	 * Every canonical Action Scheduler hook must be cleared by the uninstall
	 * helper, plus the group-based forward-compat net.
	 */
	public function test_uninstall_clears_every_canonical_as_hook_and_group(): void {
		$source = $this->uninstall_source();
		$body   = $this->extract_function_body( $source, 'wppo_clear_scheduled_jobs' );
		$this->assertNotNull( $body, 'wppo_clear_scheduled_jobs() must exist in uninstall.php' );
		$this->assertStringContainsString( 'Job_Registry::all_action_scheduler_hooks()', (string) $body );
		foreach ( Job_Registry::all_action_scheduler_hooks() as $hook ) {
			$this->assertStringStartsWith( 'wppo_', $hook );
		}
		// Builder drift and generic upgrade purge are AS-owned jobs too.
		$this->assertContains( 'wppo_builder_drift_purge', Job_Registry::all_action_scheduler_hooks() );
		$this->assertContains( 'wppo_upgrade_purge', Job_Registry::all_action_scheduler_hooks() );
		// Forward-compat net for future hooks in the plugin's AS group.
		$this->assertStringContainsString( 'Job_Registry::AS_GROUP', (string) $body );
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
		$this->assertStringContainsString( 'Job_Registry::all_cron_hooks()', (string) $body );
		$this->assertStringContainsString( 'Job_Registry::all_action_scheduler_hooks()', (string) $body );
		$this->assertStringContainsString( 'Job_Registry::AS_GROUP', (string) $body );
		foreach ( Job_Registry::all_cron_hooks() as $hook ) {
			$this->assertStringStartsWith( 'wppo_', $hook );
		}
		foreach ( Job_Registry::all_action_scheduler_hooks() as $hook ) {
			$this->assertStringStartsWith( 'wppo_', $hook );
		}
	}

	/**
	 * Standalone uninstall must consume the same registry as the booted plugin.
	 *
	 * @return void
	 */
	public function test_uninstall_consumes_job_registry_manifest(): void {
		$source = $this->uninstall_source();
		$this->assertStringContainsString( 'includes/Scheduler/class-job-registry.php', $source );
		$body = $this->extract_function_body( $source, 'wppo_clear_scheduled_jobs' );
		$this->assertNotNull( $body, 'wppo_clear_scheduled_jobs() must exist in uninstall.php' );
		$body = (string) $body;
		$this->assertStringContainsString( 'Job_Registry::all_cron_hooks()', $body );
		$this->assertStringContainsString( 'Job_Registry::all_action_scheduler_hooks()', $body );
		$this->assertStringContainsString( 'Job_Registry::AS_GROUP', $body );
		$this->assertContains( 'wppo_upgrade_purge', Job_Registry::all_cron_hooks(), 'The upgrade purge fallback must be covered by the registry teardown.' );
	}
}
