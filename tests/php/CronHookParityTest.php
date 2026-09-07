<?php
/**
 * Tests for cron scheduling/un-scheduling parity (audit #888 findings 1 + 9).
 *
 * Guarantees that every WP-Cron hook the plugin schedules is unscheduled by
 * Cron::clear_cron_jobs() (which Deactivate::init() calls), so no plugin cron
 * event leaks after deactivation.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use Brain\Monkey\Functions;

/**
 * Cron parity tests.
 *
 * @package PerformanceOptimise\Tests
 */
class CronHookParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Hooks recorded by the wp_schedule_event() stub.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $scheduled = array();

	/**
	 * Hooks recorded by the wp_clear_scheduled_hook()/wp_unschedule_hook() stubs.
	 *
	 * @var string[]
	 */
	private array $cleared = array();

	/**
	 * Install option + cron stubs backed by the in-memory store.
	 */
	private function install_stubs(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->scheduled[] = array( $timestamp, $recurrence, $hook );
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				$this->scheduled[] = array( $timestamp, $hook, $args );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = $hook;
				return 0;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = $hook;
				return 0;
			}
		);
	}

	/**
	 * Settings array with every feature that schedules a recurring cron enabled.
	 *
	 * @return array<string, mixed>
	 */
	private function all_features_enabled_settings(): array {
		return array(
			'preload_settings'  => array(
				'enablePreloadCache' => true,
			),
			'llms_txt'          => array(
				'enabled' => true,
			),
			'file_optimisation' => array(
				'removeUnusedCSS' => true,
			),
		);
	}

	/**
	 * Every hook scheduled by schedule_cron_jobs() must be part of the
	 * canonical Cron::SCHEDULED_HOOKS list.
	 */
	public function test_schedule_cron_jobs_only_schedules_canonical_hooks(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = $this->all_features_enabled_settings();

		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
		$cron->schedule_cron_jobs();

		$this->assertNotEmpty( $this->scheduled, 'Precondition: at least one recurring hook scheduled.' );

		$scheduled_hooks = array_unique( array_column( $this->scheduled, 2 ) );
		foreach ( $scheduled_hooks as $hook ) {
			$this->assertContains(
				$hook,
				Cron::SCHEDULED_HOOKS,
				sprintf( 'Hook "%s" is scheduled but missing from Cron::SCHEDULED_HOOKS.', $hook )
			);
		}
	}

	/**
	 * Every canonical hook must be unscheduled by clear_cron_jobs().
	 */
	public function test_clear_cron_jobs_covers_every_canonical_hook(): void {
		$this->install_stubs();

		Cron::clear_cron_jobs();

		foreach ( Cron::SCHEDULED_HOOKS as $hook ) {
			$this->assertContains(
				$hook,
				$this->cleared,
				sprintf( 'Hook "%s" is in Cron::SCHEDULED_HOOKS but not unscheduled by clear_cron_jobs().', $hook )
			);
		}

		// The legacy misspelled image-conversion hook must still be cleaned.
		$this->assertContains( 'wppo_img_conversation', $this->cleared );
	}

	/**
	 * End-to-end parity: anything schedule_cron_jobs() can schedule is also
	 * cleared by clear_cron_jobs() — no cron leaks on deactivate.
	 */
	public function test_scheduled_hooks_are_a_subset_of_cleared_hooks(): void {
		$this->install_stubs();
		$this->options['wppo_settings'] = $this->all_features_enabled_settings();

		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
		$cron->schedule_cron_jobs();

		$this->cleared = array();
		Cron::clear_cron_jobs();

		$scheduled_hooks = array_unique( array_column( $this->scheduled, 2 ) );
		foreach ( $scheduled_hooks as $hook ) {
			$this->assertContains(
				$hook,
				$this->cleared,
				sprintf( 'Scheduled hook "%s" would leak after clear_cron_jobs().', $hook )
			);
		}
	}

	/**
	 * Hooks scheduled outside the Cron class (RUM, Activate, LiteSpeed crawler)
	 * must also be part of the canonical cleanup list.
	 */
	public function test_external_schedulers_are_covered(): void {
		$external_hooks = array(
			'wppo_rum_flush',               // Scheduled by the RUM class.
			'wppo_run_upgrades',            // Scheduled by the Activate class.
			'wppo_litespeed_crawler_batch', // Scheduled by the LiteSpeed crawler.
			'wppo_crawler_warm',            // Scheduled by the LiteSpeed crawler.
		);

		foreach ( $external_hooks as $hook ) {
			$this->assertContains( $hook, Cron::SCHEDULED_HOOKS );
		}
	}

	/**
	 * Deactivate::unregister_runtime_hooks() must remove the plugin-owned
	 * runtime hooks documented in its docblock (audit #888 finding 1).
	 */
	public function test_deactivate_unregisters_runtime_hooks(): void {
		$this->install_stubs();

		// Record remove_action calls by wrapping the Brain Monkey-declared stub.
		$removed = array();
		Functions\expect( 'remove_action' )
			->andReturnUsing(
				static function ( $hook, $callback = null, $priority = 10 ) use ( &$removed ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					$removed[] = $hook;
					return true;
				}
			);

		\PerformanceOptimise\Inc\Deactivate::unregister_runtime_hooks();

		$expected = array(
			'update_option_wppo_settings',
			'update_option_permalink_structure',
			'switch_theme',
			'activated_plugin',
			'deactivated_plugin',
			'save_post',
			'deleted_post',
		);

		foreach ( $expected as $hook ) {
			$this->assertContains(
				$hook,
				$removed,
				sprintf( 'Deactivate::unregister_runtime_hooks() did not remove hook "%s".', $hook )
			);
		}

		// The wppo_after_cache_clear listeners are deliberately kept registered
		// (final edge purge + cheap stat-cache flush are desired during teardown).
		$this->assertNotContains( 'wppo_after_cache_clear', $removed );
	}
}
