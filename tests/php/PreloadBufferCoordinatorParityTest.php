<?php
/**
 * P3-015 parity tests for Preload_Buffer_Coordinator.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Preload_Buffer_Coordinator;
use PerformanceOptimise\Inc\Script_Strategy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Source and behavior parity for the bounded preload/buffer cluster.
 */
class PreloadBufferCoordinatorParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Enqueue calls captured from the injected scheduling port.
	 *
	 * @var array<int, array{0:string,1:array,2:string}>
	 */
	private array $enqueued = array();

	/**
	 * Build a coordinator with narrow injected ports.
	 *
	 * @param array $options Options snapshot.
	 * @return Preload_Buffer_Coordinator
	 */
	private function make_coordinator( array $options = array() ): Preload_Buffer_Coordinator {
		return new Preload_Buffer_Coordinator(
			static fn(): array => $options,
			new Image_Optimisation( $options ),
			new Google_Fonts( $options ),
			static function ( array $file_optimisation ): bool {
				unset( $file_optimisation );
				return false;
			},
			static fn(): bool => false,
			function ( string $hook, array $args, string $group ): void {
				$this->enqueued[] = array( $hook, $args, $group );
			}
		);
	}

	/**
	 * Main keeps the public signatures and callback target used by Hook_Registry.
	 *
	 * @return void
	 */
	public function test_main_facade_signatures_and_hook_targets_are_preserved(): void {
		$expected = array(
			'process_used_css_only'     => 2,
			'start_used_css_buffer'     => 0,
			'start_lcp_priority_buffer' => 0,
			'process_used_css_capture'  => 1,
		);
		foreach ( $expected as $method_name => $parameter_count ) {
			$this->assertTrue( method_exists( Main::class, $method_name ) );
			$this->assertTrue( method_exists( Preload_Buffer_Coordinator::class, $method_name ) );
			$main_method = new \ReflectionMethod( Main::class, $method_name );
			$this->assertTrue( $main_method->isPublic() );
			$this->assertCount( $parameter_count, $main_method->getParameters() );
		}
		$core = new \ReflectionMethod( Main::class, 'should_use_core_template_buffer' );
		$this->assertTrue( $core->isPublic() );
		$this->assertTrue( $core->isStatic() );
		$this->assertCount( 0, $core->getParameters() );
	}

	/**
	 * Facades contain only compatibility delegation while scheduling bodies live
	 * with the bounded coordinator owner.
	 *
	 * @return void
	 */
	public function test_source_parity_keeps_moved_bodies_out_of_main(): void {
		$main_path = ( new \ReflectionClass( Main::class ) )->getFileName();
		$source    = implode( '', (array) file( (string) $main_path ) );
		foreach ( array( 'process_used_css_only', 'start_used_css_buffer', 'start_lcp_priority_buffer', 'process_used_css_capture' ) as $method_name ) {
			$method       = new \ReflectionMethod( Main::class, $method_name );
			$method_lines = (array) file( (string) $method->getFileName() );
			$body         = implode(
				'',
				array_slice(
					$method_lines,
					$method->getStartLine() - 1,
					$method->getEndLine() - $method->getStartLine() + 1
				)
			);
			$this->assertStringContainsString( 'preload_buffer_coordinator->', $body, $method_name );
		}
		$this->assertStringContainsString( 'queue_crawler_warm_after_cache_invalidation', $source );
		$this->assertStringContainsString( 'queue_used_css_regeneration', $source );
		$this->assertStringNotContainsString( "Util::enqueue_unique_async_action( 'wppo_crawler_warm'", $source );
		$this->assertStringNotContainsString( "Util::enqueue_unique_async_action( 'wppo_used_css_generate'", $source );
	}

	/**
	 * The 6.9 availability probe is identical through every public compatibility
	 * entry point and never calls the runtime core opt-out predicate.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_core_buffer_predicate_is_unchanged(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\stubs( array( 'wp_should_output_buffer_template_for_enhancement' => false ) );
		$this->assertTrue( Preload_Buffer_Coordinator::should_use_core_template_buffer() );
		$this->assertTrue( Main::should_use_core_template_buffer() );
		$this->assertTrue( Script_Strategy::should_use_core_template_buffer() );

		$GLOBALS['wp_version'] = '6.8.2';
		$this->assertFalse( Preload_Buffer_Coordinator::should_use_core_template_buffer() );
		$this->assertFalse( Main::should_use_core_template_buffer() );
		$this->assertFalse( Script_Strategy::should_use_core_template_buffer() );
	}

	/**
	 * Legacy starts preserve callback target and buffer order, and one-shot
	 * lifecycle state now belongs to the coordinator.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_legacy_buffer_callbacks_and_one_shot_lifecycle(): void {
		$GLOBALS['wp_version'] = '6.8.2';
		Functions\stubs( array( 'wp_should_output_buffer_template_for_enhancement' => false ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_robots' )->justReturn( false );
		Functions\when( 'is_trackback' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );

		$options     = array(
			'cache_settings'    => array(),
			'file_optimisation' => array(),
		);
		$coordinator = $this->make_coordinator( $options );
		$baseline    = ob_get_level();
		$coordinator->start_lcp_priority_buffer();
		$this->assertSame( $baseline + 1, ob_get_level() );
		$image_callback = new \ReflectionProperty( Preload_Buffer_Coordinator::class, 'image_optimisation' );
		$this->assertInstanceOf( Image_Optimisation::class, $image_callback->getValue( $coordinator ) );
		ob_end_clean();

		$coordinator->start_used_css_buffer();
		$this->assertSame( $baseline + 1, ob_get_level() );
		ob_end_clean();

		$state = new \ReflectionProperty( Preload_Buffer_Coordinator::class, 'used_css_buffer_enhanced' );
		$state->setValue( $coordinator, true );
		$this->assertSame( '<p>same</p>', $coordinator->process_used_css_only( '<p>same</p>', '<p>raw</p>' ) );
		$this->assertSame( '<p>same</p>', $coordinator->process_used_css_capture( '<p>same</p>' ) );
		$this->assertSame( '', $coordinator->process_used_css_only( false, false ) );
		$this->assertSame( '', $coordinator->process_used_css_capture( false ) );
	}

	/**
	 * Cache-aware scheduling seams preserve their exact hook, args, group, and
	 * setting gates through the coordinator.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cache_aware_scheduling_seams(): void {
		Functions\stubs(
			array(
				'as_enqueue_async_action' => null,
				'as_has_scheduled_action' => false,
			)
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/hello-world/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$coordinator = $this->make_coordinator();
		$coordinator->queue_used_css_regeneration( 42, array( 'file_optimisation' => array( 'removeUnusedCSS' => false ) ) );
		$coordinator->queue_crawler_warm_after_cache_invalidation( 42, array( 'preload_settings' => array( 'preloadSitemap' => false ) ) );
		$this->assertSame( array(), $this->enqueued );

		$coordinator->queue_used_css_regeneration( 42, array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		$coordinator->queue_crawler_warm_after_cache_invalidation( 42, array( 'preload_settings' => array( 'preloadSitemap' => true ) ) );
		$this->assertSame(
			array(
				array( 'wppo_used_css_generate', array( 'post_id' => 42 ), 'performance_optimisation' ),
				array( 'wppo_crawler_warm', array( 'https://example.com/hello-world/' ), 'performance_optimisation' ),
			),
			$this->enqueued
		);
	}
}
