<?php
/**
 * Tests for the CSS-pipeline hardening (issue #1347).
 *
 * Covers the shared Util CSS sanitizer (script-construct stripping plus
 * size/charset bounds), the per-site HMAC secret + payload sign/verify
 * helpers, and the regeneration-worker gates: a missing/mismatched tag
 * rejects the job and purges poisoned CSS (unoptimized served), while a
 * valid tag proceeds.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Hardening tests for the used/critical-CSS ingest + callback auth.
 *
 * @package PerformanceOptimise\Tests
 */
class CssPipelineHmac1347Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option store for the secret/roundtrip tests.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Cache-tree roots created by these tests (for cleanup).
	 *
	 * @var string[]
	 */
	private array $created_dirs = array();

	/**
	 * Stub get_option/update_option with a persisting in-memory store and
	 * reset the in-memory secret cache so each test starts clean.
	 *
	 * @return void
	 */
	private function stub_option_store(): void {
		Util::reset_css_pipeline_secrets();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match update_option().
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Remove created cache files.
	 */
	protected function tearDown(): void {
		foreach ( $this->created_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->created_dirs = array();
		$this->options      = array();
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					$this->remove_dir( $path );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup only.
					unlink( $path );
				}
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup only.
	}

	/**
	 * Script-capable constructs are stripped from stored CSS.
	 */
	public function test_sanitize_strips_script_constructs(): void {
		$evil = '.x{width:expression(alert(1))}'
			. '.y{background:url(javascript:alert(1))}'
			. '.z{background:url(data:image/svg+xml,<svg>)}'
			. '.w{behavior:url(x.htc)}'
			. '.v{-moz-binding:url(x.xml)}'
			. '.u{background:url(x"onload="alert(1))}'
			. '/*</style><script>alert(1)</script>*/';

		$clean = Util::sanitize_css_for_storage( $evil );

		$this->assertDoesNotMatchRegularExpression( '/expression\s*\(/i', $clean );
		$this->assertDoesNotMatchRegularExpression( '/javascript\s*:/i', $clean );
		$this->assertDoesNotMatchRegularExpression( '/<\/style/i', $clean );
		$this->assertDoesNotMatchRegularExpression( '/<script/i', $clean );
		$this->assertDoesNotMatchRegularExpression( '/behaviou?r\s*:/i', $clean );
		$this->assertDoesNotMatchRegularExpression( '/-moz-binding/i', $clean );
		$this->assertDoesNotMatchRegularExpression( '/</', $clean );
		$this->assertFalse( Util::is_css_safe_for_storage( $evil ) );
	}

	/**
	 * Benign CSS (including behavior-substring selectors and modern
	 * hyphenated properties) passes through byte-for-byte.
	 */
	public function test_sanitize_passes_benign_css_through(): void {
		$benign = array(
			'body{color:#fff;background:url(a.png)}',
			'.a{color:red}',
			'.behavior-badge{color:red}#behaviour-list{margin:0}',
			'html{scroll-behavior:smooth;scroll-padding-top:6rem}',
			':root{--scroll-behavior:smooth}',
		);
		foreach ( $benign as $css ) {
			$this->assertSame( $css, Util::sanitize_css_for_storage( $css ), "Benign CSS altered: {$css}" );
			$this->assertTrue( Util::is_css_safe_for_storage( $css ), "Benign CSS flagged unsafe: {$css}" );
		}
	}

	/**
	 * Control-byte blobs fail closed; oversized input is truncated to the cap.
	 */
	public function test_sanitize_enforces_charset_and_size_bounds(): void {
		$this->assertSame( '', Util::sanitize_css_for_storage( ".a{color:red}\x00.evil{}" ) );
		$this->assertFalse( Util::is_css_safe_for_storage( ".a{color:red}\x01" ) );

		$big = str_repeat( '.a{color:red}', 200000 );
		$this->assertGreaterThan( Util::MAX_CSS_STORAGE_BYTES, strlen( $big ) );
		$this->assertSame( Util::MAX_CSS_STORAGE_BYTES, strlen( Util::sanitize_css_for_storage( $big ) ) );
	}

	/**
	 * A valid tag verifies; missing/mismatched/wrong-payload tags reject.
	 */
	public function test_sign_verify_roundtrip(): void {
		$this->stub_option_store();

		$payload = Util::used_css_job_payload( 42 );
		$tag     = Util::sign_css_regen_payload( $payload );

		$this->assertNotSame( '', $tag );
		$this->assertTrue( Util::verify_css_regen_payload( $payload, $tag ) );
		$this->assertFalse( Util::verify_css_regen_payload( $payload, 'bad-tag' ) );
		$this->assertFalse( Util::verify_css_regen_payload( $payload, '' ) );
		$this->assertFalse( Util::verify_css_regen_payload( $payload, null ) );
		$this->assertFalse( Util::verify_css_regen_payload( Util::used_css_job_payload( 43 ), $tag ) );
		$this->assertFalse( Util::verify_css_regen_payload( Util::ccss_job_payload( 'abc' ), $tag ) );
	}

	/**
	 * The secret persists in a per-site option and is registered for uninstall.
	 */
	public function test_secret_uses_per_site_option_and_uninstall_list(): void {
		$this->stub_option_store();

		$tag = Util::sign_css_regen_payload( Util::used_css_job_payload( 7 ) );
		$this->assertNotSame( '', $tag );
		$this->assertArrayHasKey( Util::CSS_PIPELINE_SECRET_OPTION, $this->options );
		$this->assertIsString( $this->options[ Util::CSS_PIPELINE_SECRET_OPTION ] );
		$this->assertGreaterThanOrEqual( 32, strlen( (string) $this->options[ Util::CSS_PIPELINE_SECRET_OPTION ] ) );
		$this->assertContains( Util::CSS_PIPELINE_SECRET_OPTION, Util::UNINSTALL_OPTIONS );
	}

	/**
	 * Secrets are isolated per site: a tag minted for one blog fails on another.
	 */
	public function test_secrets_are_isolated_per_site(): void {
		$this->stub_option_store();

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$tag_blog1 = Util::sign_css_regen_payload( Util::used_css_job_payload( 9 ) );
		$this->assertNotSame( '', $tag_blog1 );
		$this->assertTrue( Util::verify_css_regen_payload( Util::used_css_job_payload( 9 ), $tag_blog1 ) );

		// Simulate blog 2 with a fresh option row: it mints its own secret,
		// so blog 1's tag must not verify there.
		$this->options = array();
		Util::reset_css_pipeline_secrets();
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->assertFalse( Util::verify_css_regen_payload( Util::used_css_job_payload( 9 ), $tag_blog1 ) );
		$tag_blog2 = Util::sign_css_regen_payload( Util::used_css_job_payload( 9 ) );
		$this->assertNotSame( '', $tag_blog2 );
		$this->assertTrue( Util::verify_css_regen_payload( Util::used_css_job_payload( 9 ), $tag_blog2 ) );
	}

	/**
	 * Signed job args carry a verifiable tag.
	 */
	public function test_job_args_for_post_carries_valid_hmac(): void {
		$this->stub_option_store();

		$args = Used_CSS::job_args_for_post( 42 );
		$this->assertSame( 42, $args['post_id'] );
		$this->assertArrayHasKey( 'hmac', $args );
		$this->assertTrue( Used_CSS::is_job_hmac_valid( 42, $args['hmac'] ) );
		$this->assertFalse( Used_CSS::is_job_hmac_valid( 42, 'forged' ) );
		$this->assertFalse( Used_CSS::is_job_hmac_valid( 0, '' ) );
	}

	/**
	 * Install the filesystem + permalink stubs shared by the worker tests.
	 *
	 * @param string $permalink Permalink returned for any post.
	 * @param array  $fetches Sink for wp_remote_get calls.
	 * @return void
	 */
	private function stub_worker_env( string $permalink, array &$fetches ): void {
		$this->stub_option_store();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) use ( $permalink ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature must match get_permalink().
				return $permalink;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args = array() ) use ( &$fetches ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match wp_remote_get().
				$fetches[] = $url;
				return new class() {
					/**
					 * Mimic WP_Error::get_error_message().
					 *
					 * @return string
					 */
					public function get_error_message() {
						return 'stubbed failure';
					}
				};
			}
		);
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		$GLOBALS['wp_filesystem'] = new class() {
			/**
			 * Check existence via native PHP.
			 *
			 * @param string $p Path.
			 * @return bool
			 */
			public function exists( $p ) {
				return file_exists( (string) $p );
			}

			/**
			 * Delete via native PHP.
			 *
			 * @param string $p Path.
			 * @return bool
			 */
			public function delete( $p ) {
				$p = (string) $p;
				if ( is_dir( $p ) && ! is_link( $p ) ) {
					return rmdir( $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fake filesystem only.
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fake filesystem only.
				return unlink( $p );
			}

			/**
			 * Stat via native PHP.
			 *
			 * @param string $p Path.
			 * @return int|false
			 */
			public function size( $p ) {
				$size = filesize( (string) $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Test fake filesystem only.
				return false === $size ? false : $size;
			}

			/**
			 * Read via native PHP.
			 *
			 * @param string $p Path.
			 * @return string|false
			 */
			public function get_contents( $p ) {
				return file_get_contents( (string) $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fake filesystem only.
			}

			/**
			 * Copy via native PHP.
			 *
			 * @param string $src Source.
			 * @param string $dst Dest.
			 * @param bool   $overwrite Overwrite.
			 * @return bool
			 */
			public function copy( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test fake filesystem only.
				return copy( (string) $src, (string) $dst ); // phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy -- Test fake filesystem only.
			}

			/**
			 * Write via native PHP.
			 *
			 * @param string $p Path.
			 * @param string $contents Contents.
			 * @return bool
			 */
			public function put_contents( $p, $contents ) {
				return false !== file_put_contents( (string) $p, (string) $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fake filesystem only.
			}
		};
	}

	/**
	 * A forged (non-empty mismatched) HMAC rejects the used-CSS job: no
	 * fetch runs and any poisoned CSS is purged (unoptimized served
	 * instead). A missing/empty tag is a legacy pre-HMAC job: honoured
	 * without purging so upgrades never burn legitimate cache.
	 */
	public function test_process_background_rejects_bad_hmac_and_purges(): void {
		$fetches   = array();
		$permalink = 'http://example.com/poisoned-page/';
		$this->stub_worker_env( $permalink, $fetches );

		$used_css = new Used_CSS( array( 'file_optimisation' => array( 'removeUnusedCSS' => true ) ) );
		$path     = $used_css->get_used_css_path( $permalink );
		$this->assertNotSame( '', $path );
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup only.
			mkdir( $dir, 0755, true );
		}
		$this->created_dirs[] = WP_CONTENT_DIR . '/cache';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $path, '.x{width:expression(alert(1))}' );
		$this->assertFileExists( $path );

		Used_CSS::process_background( 77, 'forged-tag' );
		$this->assertSame( array(), $fetches, 'Rejected job must not fetch' );
		$this->assertFileDoesNotExist( $path, 'Poisoned CSS must be purged on HMAC mismatch' );

		// Legacy pre-HMAC shape (single assoc arg, no tag) is honoured
		// without purging.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $path, '.a{color:red}' );
		Used_CSS::process_background( array( 'post_id' => 77 ) );
		$this->assertCount( 1, $fetches, 'Legacy job must proceed to the fetch' );
		$this->assertFileExists( $path, 'Legacy cache must not be purged on upgrade' );
	}

	/**
	 * A valid HMAC lets the used-CSS job proceed to the fetch.
	 */
	public function test_process_background_accepts_valid_hmac(): void {
		$fetches   = array();
		$permalink = 'http://example.com/valid-page/';
		$this->stub_worker_env( $permalink, $fetches );

		$args = Used_CSS::job_args_for_post( 78 );
		Used_CSS::process_background( $args['post_id'], $args['hmac'] );

		$this->assertCount( 1, $fetches, 'Valid job must proceed to the fetch' );
		$this->assertSame( $permalink, $fetches[0] );
	}

	/**
	 * Encoded bypasses (comments inside keywords, CSS hex escapes,
	 * double-encoded entities, non-allowlisted data: types) are neutralized.
	 */
	public function test_sanitize_blocks_encoded_bypasses(): void {
		$comment_split = '.x{width:exp/**/ression(alert(1))}';
		$clean         = Util::sanitize_css_for_storage( $comment_split );
		$this->assertDoesNotMatchRegularExpression( '/expression\s*\(/i', $clean );
		$this->assertFalse( Util::is_css_safe_for_storage( $comment_split ) );

		$escaped = '.x{width:\65xpression(alert(1))}';
		$clean   = Util::sanitize_css_for_storage( $escaped );
		$this->assertDoesNotMatchRegularExpression( '/expression\s*\(/i', $clean );
		$this->assertFalse( Util::is_css_safe_for_storage( $escaped ) );

		$js_comment = '.y{background:url(java/**/script:alert(1))}';
		$this->assertFalse( Util::is_css_safe_for_storage( $js_comment ) );
		$this->assertDoesNotMatchRegularExpression( '/javascript\s*:/i', Util::sanitize_css_for_storage( $js_comment ) );

		// Double-encoded entity: &amp;lt; decodes once to &lt; and the
		// remnant `&` is escaped so it can never decode back to `<`.
		$double = '.z{content:"&amp;lt;script"}';
		$clean  = Util::sanitize_css_for_storage( $double );
		$this->assertStringNotContainsString( '&lt;', $clean );
		$this->assertFalse( Util::is_css_safe_for_storage( '.w{content:"&#60;script"}' ) );

		// Non-allowlisted data: types break; allowlisted image types pass.
		$this->assertFalse( Util::is_css_safe_for_storage( '.a{background:url(data:text/css;base64,花的)}' ) );
		$this->assertFalse( Util::is_css_safe_for_storage( '.a{background:url(data:application/javascript;base64,xxx)}' ) );
		$this->assertFalse( Util::is_css_safe_for_storage( '.a{background:url(data:image/svg+xml,<svg>)}' ) );
		$this->assertTrue( Util::is_css_safe_for_storage( '.a{background:url(data:image/png;base64,iVBOR)}' ) );
		$safe_png = '.a{background:url(data:image/png;base64,iVBOR)}';
		$this->assertSame( $safe_png, Util::sanitize_css_for_storage( $safe_png ) );

		// Sanitizer output is safe by construction: is_safe(sanitize(x))
		// holds for hostile input (pins the gate/sanitizer agreement).
		foreach ( array( $comment_split, $escaped, $js_comment ) as $evil ) {
			$this->assertTrue( Util::is_css_safe_for_storage( Util::sanitize_css_for_storage( $evil ) ) );
		}
	}

	/**
	 * A forged (non-empty mismatched) HMAC rejects the critical-CSS job
	 * and purges poisoned CSS. A missing tag is a legacy pre-HMAC job:
	 * honoured without purging.
	 */
	public function test_ccss_background_generate_rejects_bad_hmac_and_purges(): void {
		$this->stub_option_store();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );

		$hash = str_repeat( 'a', 64 );
		$dir  = WP_CONTENT_DIR . '/cache/wppo/ccss';
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup only.
			mkdir( $dir, 0755, true );
		}
		$this->created_dirs[] = WP_CONTENT_DIR . '/cache';
		$file                 = $dir . '/' . $hash . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $file, '.x{width:expression(alert(1))}' );
		$this->assertFileExists( $file );

		Critical_CSS::background_generate(
			array(
				'template_hash' => $hash,
				'hmac'          => 'forged-tag',
			)
		);
		$this->assertFileDoesNotExist( $file, 'Poisoned CCSS must be purged on HMAC mismatch' );

		// Legacy pre-HMAC shape (no tag) is honoured without purging.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $file, '.a{color:red}' );
		Critical_CSS::background_generate( array( 'template_hash' => $hash ) );
		$this->assertFileExists( $file, 'Legacy CCSS cache must not be purged on upgrade' );
	}

	/**
	 * A valid HMAC lets the critical-CSS job proceed past the auth gate.
	 *
	 * Mirrors test_process_background_accepts_valid_hmac: the signed happy
	 * path must not purge the stored file (the worker proceeds to template
	 * resolution instead of the poison branch).
	 */
	public function test_ccss_background_generate_accepts_valid_hmac(): void {
		$this->stub_option_store();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );

		$hash = str_repeat( 'b', 64 );
		$dir  = WP_CONTENT_DIR . '/cache/wppo/ccss';
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup only.
			mkdir( $dir, 0755, true );
		}
		$this->created_dirs[] = WP_CONTENT_DIR . '/cache';
		$file                 = $dir . '/' . $hash . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup only.
		file_put_contents( $file, '.a{color:red}' );
		$this->assertFileExists( $file );

		$tag = Util::sign_css_regen_payload( Util::ccss_job_payload( $hash ) );
		$this->assertNotSame( '', $tag );
		Critical_CSS::background_generate(
			array(
				'template_hash' => $hash,
				'hmac'          => $tag,
			)
		);
		$this->assertFileExists( $file, 'Valid job must proceed past the HMAC gate without purging' );

		// The verified gate itself: valid returns the hash, forged rejects.
		$method = new \ReflectionMethod( Critical_CSS::class, 'verified_ccss_template_hash' );
		$this->assertSame(
			$hash,
			$method->invoke(
				null,
				array(
					'template_hash' => $hash,
					'hmac'          => $tag,
				)
			)
		);
		$this->assertSame(
			'',
			$method->invoke(
				null,
				array(
					'template_hash' => $hash,
					'hmac'          => 'forged-tag',
				)
			)
		);
	}
}
