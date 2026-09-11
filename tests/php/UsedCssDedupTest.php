<?php
/**
 * Tests for used-CSS scheduler dedup + safelist index equivalence.
 *
 * Covers review findings: OBJECT snapshot with pending/in-progress status
 * filter, unconditional per-post as_has_scheduled_action() fallback on
 * snapshot miss, and safelist index parity (exact/attr/prefix, bare '*').
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing,Generic.CodeAnalysis.UnusedFunctionParameter,Generic.Commenting.Todo,Squiz.Commenting.InlineComment

use PerformanceOptimise\Inc\Used_CSS;
use Brain\Monkey\Functions;

/**
 * Used-CSS dedup tests.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssDedupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Used_CSS instance with a controlled safelist.
	 *
	 * @param string[] $safelist Safelist entries.
	 * @return Used_CSS
	 */
	private function instance_with_safelist( array $safelist ): Used_CSS {
		$instance  = ( new \ReflectionClass( Used_CSS::class ) )->newInstanceWithoutConstructor();
		$safe_prop = new \ReflectionProperty( Used_CSS::class, 'safelist' );
		$safe_prop->setAccessible( true );
		$safe_prop->setValue( $instance, $safelist );
		return $instance;
	}

	/**
	 * Empty DOM stub for is_selector_used().
	 *
	 * @return array
	 */
	private function empty_used(): array {
		return array(
			'tags'    => array(),
			'classes' => array(),
			'ids'     => array(),
			'attrs'   => array(),
		);
	}

	/**
	 * Fake Action Scheduler action exposing get_args().
	 *
	 * @param array $args Action args.
	 * @return object Object with a get_args() method.
	 */
	private function fake_action( array $args ): object {
		return new class( $args ) {
			private $args;
			public function __construct( $args ) {
				$this->args = $args;
			}
			public function get_args() {
				return $this->args;
			}
		};
	}

	/**
	 * Safelist index: exact entries match verbatim.
	 */
	public function test_safelist_exact_match(): void {
		$instance = $this->instance_with_safelist( array( '.keep-me' ) );
		$this->assertTrue( $instance->is_selector_used( '.keep-me', $this->empty_used() ) );
		$this->assertFalse( $instance->is_selector_used( '.keep-other', $this->empty_used() ) );
	}

	/**
	 * Safelist index: attribute-name entries match compound selectors.
	 */
	public function test_safelist_attr_match(): void {
		$instance = $this->instance_with_safelist( array( '[data-elementor-type]' ) );
		$this->assertTrue( $instance->is_selector_used( 'div[data-elementor-type="popup"]', $this->empty_used() ) );
		$this->assertFalse( $instance->is_selector_used( '.plain-class', $this->empty_used() ) );
	}

	/**
	 * Safelist index: prefix entries match leading and embedded parts (case-insensitive).
	 */
	public function test_safelist_prefix_match(): void {
		$instance = $this->instance_with_safelist( array( '.popup-' ) );
		$this->assertTrue( $instance->is_selector_used( '.popup-bar', $this->empty_used() ) );
		$this->assertTrue( $instance->is_selector_used( '.foo .popup-bar', $this->empty_used() ) );
		$this->assertTrue( $instance->is_selector_used( '.POPUP-UPPER', $this->empty_used() ) );
		$this->assertFalse( $instance->is_selector_used( '.totally-unrelated', $this->empty_used() ) );
	}

	/**
	 * Bare '*' must not become a match-everything wildcard.
	 */
	public function test_safelist_bare_star_does_not_match_everything(): void {
		$instance = $this->instance_with_safelist( array( '*' ) );
		$this->assertTrue( $instance->is_selector_used( '*', $this->empty_used() ) );
		$this->assertFalse( $instance->is_selector_used( '.anything', $this->empty_used() ) );
	}

	/**
	 * Install $wpdb + post-type stubs for regenerate_all().
	 *
	 * @param array $post_ids IDs returned by the first get_col() call.
	 * @return void
	 */
	private function stub_posts( array $post_ids ): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		global $wpdb;
		$wpdb = new class( $post_ids ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			public $posts  = 'wp_posts';
			public $prefix = 'wp_';
			private $ids;
			private $calls = 0;
			public function __construct( $ids ) {
				$this->ids = $ids;
			}
			public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				if ( 0 === $this->calls ) {
					++$this->calls;
					return $this->ids;
				}
				return array();
			}
			public function prepare( $query, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return $query;
			}
			public function insert( $table = null, $data = array(), $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return false;
			}
		};
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Snapshot in OBJECT form populates dedup; status filter is requested.
	 */
	public function test_regenerate_all_skips_snapshot_hits_and_filters_status(): void {
		$this->stub_posts( array( 11, 12 ) );

		$captured_query  = null;
		$captured_format = 'OBJECT';
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_get_scheduled_actions().
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( $query = array(), $format = 'OBJECT' ) use ( &$captured_query, &$captured_format ) {
				$captured_query  = $query;
				$captured_format = $format;
				return array( $this->fake_action( array( 'post_id' => 11 ) ) );
			}
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_enqueue_async_action().
			function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) {
				$enqueued[] = $args['post_id'] ?? null;
				return 1;
			}
		);

		$used   = new Used_CSS( array( 'file_optimisation' => array() ) );
		$queued = $used->regenerate_all();

		$this->assertSame( 1, $queued );
		$this->assertSame( array( 12 ), $enqueued );
		$this->assertNotSame( 'ARRAY_A', $captured_format );
		$this->assertContains( 'pending', $captured_query['status'] ?? array() );
		$this->assertContains( 'in-progress', $captured_query['status'] ?? array() );
	}

	/**
	 * Snapshot miss always falls back to per-post as_has_scheduled_action().
	 */
	public function test_regenerate_all_falls_back_on_snapshot_miss(): void {
		$this->stub_posts( array( 21 ) );

		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_has_scheduled_action' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_has_scheduled_action().
			function ( $hook, $args = array(), $group = '' ) {
				return 21 === ( $args['post_id'] ?? 0 );
			}
		);
		$enqueued = 0;
		Functions\when( 'as_enqueue_async_action' )->alias(
			function () use ( &$enqueued ) {
				++$enqueued;
				return 1;
			}
		);

		$used = new Used_CSS( array( 'file_optimisation' => array() ) );
		$this->assertSame( 0, $used->regenerate_all() );
		$this->assertSame( 0, $enqueued );
	}

	/**
	 * Lookup failure degrades to per-post fallback instead of blind queuing.
	 */
	public function test_regenerate_all_fallback_on_lookup_failure(): void {
		$this->stub_posts( array( 31, 32 ) );

		Functions\when( 'as_get_scheduled_actions' )->alias(
			function () {
				throw new \RuntimeException( 'store down' );
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_has_scheduled_action().
			function ( $hook, $args = array(), $group = '' ) {
				return 31 === ( $args['post_id'] ?? 0 );
			}
		);
		$enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match as_enqueue_async_action().
			function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) {
				$enqueued[] = $args['post_id'] ?? null;
				return 1;
			}
		);

		$used = new Used_CSS( array( 'file_optimisation' => array() ) );
		$this->assertSame( 1, $used->regenerate_all() );
		$this->assertSame( array( 32 ), $enqueued );
	}
}
