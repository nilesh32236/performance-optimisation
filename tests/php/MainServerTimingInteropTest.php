<?php
/**
 * Tests for the Main Server-Timing Performance Lab interop (issue #885).
 *
 * When the Performance Lab Server-Timing module is active it owns the
 * Server-Timing header and its default metrics surface as `wp-before-template`,
 * `wp-template` and `wp-total` (Performance Lab prefixes metric slugs with
 * `wp-`). The plugin must therefore never emit metric names that duplicate
 * Performance Lab's: with output buffering enabled Performance Lab measures the
 * same durations, so the plugin's emission is suppressed; without output
 * buffering Performance Lab only sends `wp-before-template` at
 * template_include, so the plugin emits only the unclaimed `wp-template`
 * render duration; when the buffering-state helper is absent the mode is
 * unknown and the emission is suppressed. With Performance Lab absent both
 * metrics are emitted.
 *
 * Note on ordering (IMPORTANT): Brain Monkey eval-declared functions persist
 * for the whole test process even after tearDown(), and `function_exists` is
 * not redefinable, so the declared order of the test methods IS the required
 * execution order:
 *
 * 1. The Performance Lab-absent tests (is_pl_server_timing_active false, both
 *    metrics emitted) must run BEFORE any test stubs a `perflab_*` function —
 *    once defined, `function_exists()` would report the module as active.
 * 2. The buffering-API-absent suppression test only stubs
 *    `perflab_server_timing_register_metric` and must run BEFORE the tests
 *    that define `perflab_server_timing_use_output_buffer`.
 * 3. The output-buffering tests define/re-configure
 *    `perflab_server_timing_use_output_buffer` and must run last.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Server-Timing Performance Lab interop tests.
 *
 * @package PerformanceOptimise\Tests
 */
class MainServerTimingInteropTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Recorded Server-Timing header emissions (header() stub).
	 *
	 * @var string[]
	 */
	private array $sent_headers = array();

	/**
	 * Ensure per-test globals are clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
	}

	/**
	 * Ensure per-test globals are clean.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_TIME_FLOAT'] );
		$this->sent_headers = array();
		$this->wppoTearDown();
	}

	/**
	 * Build a Main instance (without constructor) configured for Server-Timing.
	 *
	 * Sets performance_audit.server_timing_enabled = true and captures a fixed
	 * template-start timestamp 500ms after the request start so the
	 * wp-before-template duration is deterministic (the render duration stays
	 * microtime-dependent and is matched loosely).
	 *
	 * @return Main
	 */
	private function build_main(): Main {
		$_SERVER['REQUEST_TIME_FLOAT'] = (string) ( 1000000000.0 );
		$reflection                    = new \ReflectionClass( Main::class );
		$main                          = $reflection->newInstanceWithoutConstructor();

		$options_prop = $reflection->getProperty( 'options' );
		$options_prop->setAccessible( true );
		$options_prop->setValue(
			$main,
			array(
				'performance_audit' => array(
					'server_timing_enabled' => true,
				),
			)
		);

		$template_start_prop = $reflection->getProperty( 'server_timing_template_start' );
		$template_start_prop->setAccessible( true );
		$template_start_prop->setValue( $main, 1000000000.5 );

		return $main;
	}

	/**
	 * Stub the request environment needed by emit_server_timing_header().
	 *
	 * @return void
	 */
	private function stub_environment(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'headers_sent' )->justReturn( false );
		$headers_ref = &$this->sent_headers;
		Functions\when( 'header' )->alias(
			static function ( $header, $replace = true ) use ( &$headers_ref ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$headers_ref[] = (string) $header;
				return null;
			}
		);
	}

	/**
	 * Test that the Performance Lab gate is false when the module is absent.
	 *
	 * Declared (and executed) before any test stubs the Performance Lab API,
	 * because Brain Monkey function declarations persist for the process.
	 */
	public function test_is_pl_server_timing_active_returns_false_when_module_absent(): void {
		$this->stub_environment();

		$main = $this->build_main();

		$this->assertFalse( $main->is_pl_server_timing_active() );
	}

	/**
	 * Test that with Performance Lab absent both Server-Timing metrics are
	 * emitted as before (no interop change when Performance Lab is inactive).
	 */
	public function test_emit_emits_both_metrics_when_pl_inactive(): void {
		$this->stub_environment();

		$main = $this->build_main();
		$main->emit_server_timing_header();

		$this->assertCount( 1, $this->sent_headers, 'Exactly one Server-Timing emission expected.' );
		$this->assertStringStartsWith( 'Server-Timing: wp-before-template;dur=500, wp-template;dur=', $this->sent_headers[0] );
	}

	/**
	 * Test that the Performance Lab gate is true when the module API is present.
	 */
	public function test_is_pl_server_timing_active_returns_true_when_api_present(): void {
		$this->stub_environment();
		Functions\when( 'perflab_server_timing_register_metric' )->justReturn( null );

		$main = $this->build_main();

		$this->assertTrue( $main->is_pl_server_timing_active() );
	}

	/**
	 * Test that when Performance Lab is active but the buffering-state helper
	 * is absent the plugin suppresses its emission — an unknown buffering mode
	 * must defer to Performance Lab rather than risk a duplicate emission
	 * (issue #885 review).
	 *
	 * Must run before any test defines perflab_server_timing_use_output_buffer.
	 */
	public function test_emit_suppressed_when_pl_buffering_api_absent(): void {
		$this->stub_environment();
		Functions\when( 'perflab_server_timing_register_metric' )->justReturn( null );

		$main = $this->build_main();
		$main->emit_server_timing_header();

		$this->assertSame( array(), $this->sent_headers, 'Unknown Performance Lab buffering mode must defer to Performance Lab (no emission).' );
	}

	/**
	 * Test that with Performance Lab active and output buffering enabled the
	 * plugin suppresses its emission entirely — Performance Lab's defaults
	 * (before-template / template / total) already cover the same durations.
	 */
	public function test_emit_suppressed_when_pl_owns_header_with_output_buffering(): void {
		$this->stub_environment();
		Functions\when( 'perflab_server_timing_register_metric' )->justReturn( null );
		Functions\when( 'perflab_server_timing_use_output_buffer' )->justReturn( true );

		$main = $this->build_main();
		$main->emit_server_timing_header();

		$this->assertSame( array(), $this->sent_headers, 'No raw Server-Timing header may be emitted when Performance Lab owns the header with output buffering.' );
	}

	/**
	 * Test that with Performance Lab active but without output buffering only
	 * the unclaimed `wp-template` render duration is emitted — Performance Lab
	 * already sent `wp-before-template` at template_include, so emitting the
	 * plugin's own wp-before-template would duplicate the metric name.
	 */
	public function test_emit_only_template_duration_when_pl_without_output_buffering(): void {
		$this->stub_environment();
		Functions\when( 'perflab_server_timing_register_metric' )->justReturn( null );
		Functions\when( 'perflab_server_timing_use_output_buffer' )->justReturn( false );

		$main = $this->build_main();
		$main->emit_server_timing_header();

		$this->assertCount( 1, $this->sent_headers, 'Exactly one appended Server-Timing emission expected.' );
		$this->assertStringStartsWith( 'Server-Timing: wp-template;dur=', $this->sent_headers[0] );
		$this->assertStringNotContainsString( 'wp-before-template', $this->sent_headers[0], 'wp-before-template is owned by Performance Lab in no-output-buffer mode and must not be duplicated (issue #885).' );
	}

	/**
	 * Test that the Server-Timing emission stays gated off entirely when
	 * performance_audit.server_timing_enabled is false, even with Performance
	 * Lab active.
	 */
	public function test_emit_suppressed_when_setting_disabled_even_with_pl(): void {
		$this->stub_environment();
		Functions\when( 'perflab_server_timing_register_metric' )->justReturn( null );
		Functions\when( 'perflab_server_timing_use_output_buffer' )->justReturn( false );

		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();
		$options    = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$main,
			array(
				'performance_audit' => array(
					'server_timing_enabled' => false,
				),
			)
		);

		$main->emit_server_timing_header();

		$this->assertSame( array(), $this->sent_headers, 'Server-Timing must stay opt-in (default off) regardless of Performance Lab presence.' );
	}
}
