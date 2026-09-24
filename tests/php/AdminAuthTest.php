<?php
/**
 * Security and delegation tests for the P3-011 Admin_Auth policy.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PerformanceOptimise\Inc\Admin_Auth;
use PerformanceOptimise\Inc\Abilities;
use PerformanceOptimise\Inc\Rest;

/**
 * Focused security, parity, and source-boundary coverage for Admin_Auth.
 *
 * @since NEXT
 */
final class AdminAuthTest extends PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;


	/**
	 * Capability denial is the first decision and never reads or verifies a nonce.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_capability_denial_short_circuits_before_nonce_work(): void {
		$request = \Mockery::mock( WP_REST_Request::class );
		$request->shouldNotReceive( 'get_header' );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_unslash' )->never();
		Functions\expect( 'sanitize_text_field' )->never();
		Functions\expect( 'wp_verify_nonce' )->never();

		$this->assertFalse( Admin_Auth::permission_check( $request ) );
	}

	/**
	 * A canonical request header is sanitized and verified for wp_rest.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_request_header_is_sanitized_and_verified(): void {
		$request = \Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_header' )
			->once()
			->with( 'X-WP-Nonce' )
			->andReturn( '  request-nonce  ' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$sanitized = array();
		$verified  = array();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $nonce ) use ( &$sanitized ): string {
				$sanitized[] = $nonce;
				return trim( $nonce );
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$verified ): bool {
				$verified[] = array( $nonce, $action );
				return true;
			}
		);

		$this->assertTrue( Admin_Auth::permission_check( $request ) );
		$this->assertSame( array( '  request-nonce  ' ), $sanitized );
		$this->assertSame( array( array( 'request-nonce', 'wp_rest' ) ), $verified );
	}

	/**
	 * A repeated request header uses only its first value.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repeated_request_header_uses_first_value(): void {
		$request = \Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_header' )
			->once()
			->with( 'X-WP-Nonce' )
			->andReturn( array( 'first-nonce', 'second-nonce' ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'first-nonce', 'wp_rest' )->andReturn( true );

		$this->assertTrue( Admin_Auth::permission_check( $request ) );
	}

	/**
	 * A supplied request is authoritative and never falls back to $_SERVER.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_request_without_header_does_not_use_server_fallback(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = 'legacy-valid';
		$request                    = \Mockery::mock( WP_REST_Request::class );
		$request->shouldReceive( 'get_header' )->once()->with( 'X-WP-Nonce' )->andReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'wp_unslash' )->never();
		Functions\expect( 'wp_verify_nonce' )->once()->with( '', 'wp_rest' )->andReturn( false );

		$this->assertFalse( Admin_Auth::permission_check( $request ) );
	}

	/**
	 * Null-request callers retain the slashed legacy server-header fallback.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_null_request_uses_unslashed_legacy_server_header(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = "legacy\\'nonce";
		Functions\when( 'current_user_can' )->justReturn( true );
		$unslashed = array();
		$verified  = array();
		Functions\when( 'wp_unslash' )->alias(
			static function ( $nonce ) use ( &$unslashed ): string {
				$unslashed[] = $nonce;
				return stripslashes( $nonce );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $nonce ): string => str_replace( "'", '', $nonce )
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$verified ): bool {
				$verified[] = array( $nonce, $action );
				return true;
			}
		);

		$this->assertTrue( Admin_Auth::permission_check() );
		$this->assertSame( array( "legacy\\'nonce" ), $unslashed );
		$this->assertSame( array( array( 'legacynonce', 'wp_rest' ) ), $verified );
	}

	/**
	 * A missing nonce fails closed for legacy callers.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_missing_legacy_nonce_fails_closed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->assertFalse( Admin_Auth::permission_check() );
	}

	/**
	 * An invalid nonce fails closed after the legacy unslash path.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_legacy_nonce_fails_closed(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = 'invalid-nonce';
		Functions\when( 'current_user_can' )->justReturn( true );
		$unslashed = array();
		Functions\when( 'wp_unslash' )->alias(
			static function ( $nonce ) use ( &$unslashed ): string {
				$unslashed[] = $nonce;
				return $nonce;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->assertFalse( Admin_Auth::permission_check() );
		$this->assertSame( array( 'invalid-nonce' ), $unslashed );
	}

	/**
	 * REST and Abilities retain their public callback signatures and delegate.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_adapter_callback_signatures_and_delegation_are_stable(): void {
		$rest_parameter = ( new ReflectionMethod( Rest::class, 'permission_callback' ) )->getParameters();
		$this->assertCount( 1, $rest_parameter );
		$this->assertTrue( $rest_parameter[0]->isOptional() );
		$this->assertTrue( $rest_parameter[0]->allowsNull() );
		$this->assertSame( '?WP_REST_Request', (string) $rest_parameter[0]->getType() );

		$this->assertCount( 0, ( new ReflectionMethod( Abilities::class, 'permission_check' ) )->getParameters() );
		$this->assertTrue( ( new ReflectionMethod( Admin_Auth::class, 'permission_check' ) )->isStatic() );

		$rest_body = $this->method_source( 'includes/Admin/class-rest.php', 'permission_callback' );
		$this->assertStringContainsString( 'return Admin_Auth::permission_check( $request );', $rest_body );
		$this->assertStringNotContainsString( "current_user_can( 'manage_options' )", $rest_body );
		$this->assertStringNotContainsString( 'wp_verify_nonce(', $rest_body );

		$abilities_body = $this->method_source( 'includes/Admin/class-abilities.php', 'permission_check' );
		$this->assertStringContainsString( 'return Admin_Auth::permission_check();', $abilities_body );
		$this->assertStringNotContainsString( "current_user_can( 'manage_options' )", $abilities_body );
		$this->assertStringNotContainsString( 'wp_verify_nonce(', $abilities_body );
	}

	/**
	 * The public RUM exception remains __return_true and outside Admin_Auth.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_public_rum_route_remains_exact_exception(): void {
		$rest       = new Rest();
		$reflection = new ReflectionMethod( $rest, 'get_routes' );
		$routes     = $reflection->invoke( $rest );

		$this->assertSame( '__return_true', $routes['rum_collect']['permission_callback'] );
		$this->assertSame(
			array( $rest, 'permission_callback' ),
			$routes['rum_data']['permission_callback']
		);
	}

	/**
	 * Read one method body from a local source file.
	 *
	 * @param string $relative_file Repository-relative file.
	 * @param string $method        Method name.
	 * @return string Method source including its braces.
	 */
	private function method_source( string $relative_file, string $method ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative_file;
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source assertion.
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );
		$needle = 'function ' . $method;
		$start  = strpos( (string) $source, $needle );
		$this->assertNotFalse( $start );
		$next = strpos( (string) $source, "\n\t\t/**", $start + strlen( $needle ) );
		$this->assertNotFalse( $next );
		return substr( (string) $source, $start, $next - $start );
	}
}
