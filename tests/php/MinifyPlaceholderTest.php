<?php
/**
 * Tests for randomized minify/noscript placeholder namespaces + strict restore (issue #992).
 *
 * Hardens the historically exploitable minify placeholder-injection class
 * (CVE-2026-3220 / LSCWP CVE-2026-3129 shape): placeholder tokens use a
 * per-request random namespace and restore only via a strict allowlist with
 * bounds-checked indices. On token anomaly the node is emitted unmodified
 * (fail-open).
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Minify\HTML;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Placeholder namespace + strict restore tests.
 *
 * @since 2.0.0
 */
class MinifyPlaceholderTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		tearDown as protected wppoTearDown;
	}

	/**
	 * Reset the HTML-processor memo so processor availability cannot leak
	 * between test files sharing one PHPUnit process.
	 */
	protected function tearDown(): void {
		Util::reset_html_processor_memo();
		$this->wppoTearDown();
	}

	/**
	 * Stub the WP functions needed to construct Minify\HTML / Image_Optimisation.
	 */
	private function stub_construction(): void {
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'site_url' )->justReturn( 'http://example.com' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	/**
	 * Build a Minify\HTML instance with all rewrites disabled (pure extract/restore).
	 *
	 * @param string $html HTML fixture.
	 * @return HTML
	 */
	private function make_minify_html( string $html ): HTML {
		$this->stub_construction();
		return new HTML( $html, array( 'file_optimisation' => array() ) );
	}

	/**
	 * Read a private property value.
	 *
	 * @param object $target Object instance.
	 * @param string $prop   Property name.
	 * @return mixed
	 */
	private function read_prop( object $target, string $prop ) {
		$reflection = new \ReflectionProperty( $target, $prop );
		$reflection->setAccessible( true );
		return $reflection->getValue( $target );
	}

	/**
	 * Invoke a private method.
	 *
	 * @param object $target Object instance.
	 * @param string $name   Method name.
	 * @param mixed  ...$args Args.
	 * @return mixed
	 */
	private function invoke_private( object $target, string $name, ...$args ) {
		$reflection = new \ReflectionMethod( $target, $name );
		$reflection->setAccessible( true );
		return $reflection->invoke( $target, ...$args );
	}

	/**
	 * Force the regex fallback path for post_process_placeholders().
	 */
	private function force_regex_path(): void {
		$prop = new \ReflectionProperty( Util::class, 'html_processor_available' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
	}

	/**
	 * Per-request namespaces must be unique across instances.
	 */
	public function test_preserve_namespaces_unique_per_instance(): void {
		$a = $this->make_minify_html( '<html><head></head><body><p>hi</p></body></html>' );
		$b = $this->make_minify_html( '<html><head></head><body><p>hi</p></body></html>' );

		$ns_a = $this->read_prop( $a, 'preserve_namespace' );
		$ns_b = $this->read_prop( $b, 'preserve_namespace' );

		$this->assertNotSame( '', $ns_a );
		$this->assertNotSame( '', $ns_b );
		$this->assertNotSame( $ns_a, $ns_b );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]+$/', $ns_a );
	}

	/**
	 * A valid namespaced token restores its script and leaves no placeholder behind.
	 */
	public function test_valid_token_restores(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$original = '<script type="text/x-custom">var wppo_valid = 1;</script>';
		$html     = '<html><head></head><body>' . $original . '</body></html>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$out = $this->make_minify_html( $html )->get_minified_html();

		$this->assertStringContainsString( $original, $out );
		$this->assertStringNotContainsString( 'data-wppo-preserve', $out );
	}

	/**
	 * A crafted sequential token (old predictable namespace) is never restored.
	 */
	public function test_crafted_sequential_token_not_restored(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$real     = '<script type="text/x-custom">var wppo_real = 1;</script>';
		$attacker = '<script data-wppo-preserve="0"></script>';
		$html     = '<html><head></head><body><p>user</p>' . $attacker . $real . '</body></html>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$out = $this->make_minify_html( $html )->get_minified_html();

		$this->assertStringContainsString( $real, $out );
		$this->assertStringContainsString( $attacker, $out );
		$this->assertSame( 1, substr_count( $out, 'var wppo_real = 1;' ) );
	}

	/**
	 * CVE-2026-3220 shape: attacker token stays inert, privileged body never leaks.
	 */
	public function test_cve_2026_3220_shape_neutralized(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$privileged = '<script type="text/x-custom">PRIVILEGED_MARKER_abc123</script>';
		$attacker   = '<script data-wppo-preserve="0"></script>';
		$html       = '<html><head></head><body>'
			. '<div class="user-content">' . $attacker . '</div>'
			. $privileged
			. '</body></html>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$out = $this->make_minify_html( $html )->get_minified_html();

		// Attacker token emitted unmodified (inert empty script).
		$this->assertStringContainsString( $attacker, $out );
		// Privileged body appears exactly once: no leak into attacker position.
		$this->assertSame( 1, substr_count( $out, 'PRIVILEGED_MARKER_abc123' ) );
	}

	/**
	 * Resolver allowlist accept/reject matrix incl. bounds-check edges.
	 */
	public function test_resolver_allowlist_matrix(): void {
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder restore tests.
		$real = '<script type="text/x-custom">var wppo_matrix = 1;</script>';
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		$instance  = $this->make_minify_html( '<html><head></head><body>' . $real . '</body></html>' );
		$namespace = $this->read_prop( $instance, 'preserve_namespace' );
		$this->assertNotSame( '', $namespace );

		$scripts = array( $real );

		$valid = '<script data-wppo-preserve="' . $namespace . '-0"></script>';
		$this->assertSame( $real, $this->invoke_private( $instance, 'resolve_preserved_script', $valid, $scripts ) );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Static fixture HTML for placeholder token tests.
		$reject = array(
			'sequential'               => '<script data-wppo-preserve="0"></script>',
			'foreign_ns'               => '<script data-wppo-preserve="wppoattacker-0"></script>',
			'non_numeric'              => '<script data-wppo-preserve="' . $namespace . '-abc"></script>',
			'out_of_range'             => '<script data-wppo-preserve="' . $namespace . '-9999"></script>',
			'at_count'                 => '<script data-wppo-preserve="' . $namespace . '-1"></script>',
			'negative'                 => '<script data-wppo-preserve="' . $namespace . '--1"></script>',
			'int_max'                  => '<script data-wppo-preserve="' . $namespace . '-' . PHP_INT_MAX . '"></script>',
			'empty_index'              => '<script data-wppo-preserve="' . $namespace . '-"></script>',
			'not_a_token'              => '<script src="https://example.com/app.js"></script>',
			'single_quotes_sequential' => "<script data-wppo-preserve='0'></script>",
		);
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		foreach ( $reject as $label => $token ) {
			$this->assertNull(
				$this->invoke_private( $instance, 'resolve_preserved_script', $token, $scripts ),
				"Token rejected: {$label}"
			);
		}
	}

	/**
	 * Noscript namespaces must be unique per instance.
	 */
	public function test_noscript_namespaces_unique_per_instance(): void {
		$this->stub_construction();
		$options = array( 'image_optimisation' => array() );

		$a = new Image_Optimisation( $options );
		$b = new Image_Optimisation( $options );

		$ns_a = $this->invoke_private( $a, 'get_noscript_namespace' );
		$ns_b = $this->invoke_private( $b, 'get_noscript_namespace' );

		$this->assertNotSame( '', $ns_a );
		$this->assertNotSame( $ns_a, $ns_b );
	}

	/**
	 * Noscript strict restore: known tokens restore, unknown/legacy pass through.
	 */
	public function test_noscript_strict_restore(): void {
		$this->stub_construction();
		$instance  = new Image_Optimisation( array( 'image_optimisation' => array() ) );
		$namespace = $this->invoke_private( $instance, 'get_noscript_namespace' );

		$token0 = '<!--WPPO_NOSCRIPT_' . $namespace . '_0-->';
		$token1 = '<!--WPPO_NOSCRIPT_' . $namespace . '_1-->';
		$map    = array(
			$token0 => '<noscript><img src="https://example.com/a.jpg"></noscript>',
			$token1 => '<noscript><img src="https://example.com/b.jpg"></noscript>',
		);

		// Known tokens resolve.
		$this->assertSame( $map[ $token0 ], $this->invoke_private( $instance, 'resolve_noscript_token', $token0, $map ) );
		$this->assertSame( $map[ $token1 ], $this->invoke_private( $instance, 'resolve_noscript_token', $token1, $map ) );

		// Unknown, foreign-namespace, out-of-range, and legacy tokens rejected.
		$this->assertNull( $this->invoke_private( $instance, 'resolve_noscript_token', '<!--WPPO_NOSCRIPT_' . $namespace . '_7-->', $map ) );
		$this->assertNull( $this->invoke_private( $instance, 'resolve_noscript_token', '<!--WPPO_NOSCRIPT_wppoforeign_0-->', $map ) );
		$this->assertNull( $this->invoke_private( $instance, 'resolve_noscript_token', '<!--WPPO_NOSCRIPT_0-->', $map ) );

		// Full-buffer restore: known tokens swap, hostile/legacy tokens stay inert.
		$buffer = '<p>hi</p><!--WPPO_NOSCRIPT_0--><!--WPPO_NOSCRIPT_wppoforeign_0-->' . $token0;
		$out    = $this->invoke_private( $instance, 'restore_noscript_tokens', $buffer, $map );

		$this->assertStringContainsString( $map[ $token0 ], $out );
		$this->assertStringContainsString( '<!--WPPO_NOSCRIPT_0-->', $out );
		$this->assertStringContainsString( '<!--WPPO_NOSCRIPT_wppoforeign_0-->', $out );
	}

	/**
	 * Hostile placeholder-shaped input is emitted unmodified (regex fallback path).
	 */
	public function test_placeholder_fail_open_hostile_data_src(): void {
		$this->stub_construction();
		$this->force_regex_path();
		$instance = new Image_Optimisation( array( 'image_optimisation' => array() ) );

		$hostile = array(
			'<img data-src="javascript:alert(1)" alt="x">',
			'<img data-src="vbscript:msgbox(1)" alt="x">',
			'<img data-src="data:text/html,<script>alert(1)</script>" alt="x">',
			'<img data-src="https://example.com/' . str_repeat( 'a', 2048 ) . '.jpg" alt="x">',
		);

		foreach ( $hostile as $img ) {
			$out = $this->invoke_private( $instance, 'post_process_placeholders', $img, true );
			$this->assertSame( $img, $out );
		}

		// Disabled placeholders are a straight passthrough.
		$img = '<img data-src="https://example.com/a.jpg" alt="x">';
		$this->assertSame( $img, $this->invoke_private( $instance, 'post_process_placeholders', $img, false ) );
	}

	/**
	 * Hostile placeholder-shaped input is emitted unmodified (processor path).
	 */
	public function test_placeholder_fail_open_processor_path(): void {
		if ( ! class_exists( 'WP_HTML_Processor' ) ) {
			require_once __DIR__ . '/stubs/wp-html-api.php';
		}
		$this->stub_construction();
		Util::reset_html_processor_memo();
		$instance = new Image_Optimisation( array( 'image_optimisation' => array() ) );

		$img = '<img data-src="javascript:alert(1)" alt="x">';
		$out = $this->invoke_private( $instance, 'post_process_placeholders', $img, true );

		$this->assertSame( $img, $out );
	}
}
