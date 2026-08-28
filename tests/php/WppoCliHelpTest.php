<?php
/**
 * Tests for WP-CLI synopsis help (Phase1 PR-A).
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Verifies synopsis fixes: [<action>] + defaults per FINAL-ADVERSARIAL-REVIEW PR-A.
 *
 * @since NEXT
 */
class WppoCliHelpTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Path to CLI command file.
	 *
	 * @var string
	 */
	private string $cli_file;

	/**
	 * File contents.
	 *
	 * @var string
	 */
	private string $contents;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cli_file = dirname( __DIR__, 2 ) . '/includes/class-wppo-cli-command.php';
		$raw            = file_get_contents( $this->cli_file );
		$this->assertNotFalse( $raw, 'CLI file must be readable' );
		$this->contents = $raw;
	}

	/**
	 * Cache synopsis uses [<action>] with default clear.
	 */
	public function test_cache_synopsis_has_bracket_action_and_default(): void {
		$this->assertStringContainsString( '[<action>]', $this->contents );
		// Cache block must contain default: clear and the three options.
		$this->assertMatchesRegularExpression( '/cache.*?default:\s*clear/s', $this->contents );
		$this->assertStringContainsString( '- clear', $this->contents );
		$this->assertStringContainsString( '- preload', $this->contents );
		$this->assertStringContainsString( '- status', $this->contents );
	}

	/**
	 * Database synopsis uses [<action>] with default cleanup.
	 */
	public function test_database_synopsis_has_bracket_action_and_default(): void {
		$this->assertMatchesRegularExpression( '/database.*?default:\s*cleanup/s', $this->contents );
		$this->assertStringContainsString( '- cleanup', $this->contents );
		$this->assertStringContainsString( '- optimize', $this->contents );
		$this->assertStringContainsString( '- counts', $this->contents );
	}

	/**
	 * Image synopsis uses [<action>] with default status.
	 */
	public function test_image_synopsis_has_bracket_action_and_default(): void {
		$this->assertMatchesRegularExpression( '/image.*?default:\s*status/s', $this->contents );
		$this->assertStringContainsString( '- convert', $this->contents );
		// Status appears for image as well.
		$this->assertTrue( substr_count( $this->contents, '[<action>]' ) >= 3 );
	}

	/**
	 * Object-cache synopsis uses [<action>] with default status.
	 */
	public function test_object_cache_synopsis_has_bracket_action(): void {
		$this->assertMatchesRegularExpression( '/object-cache.*?\[<action>\]/s', $this->contents );
	}

	/**
	 * Pagespeed synopsis uses [<action>] with default scan.
	 */
	public function test_pagespeed_synopsis_has_bracket_action(): void {
		$this->assertMatchesRegularExpression( '/pagespeed.*?\[<action>\]/s', $this->contents );
		$this->assertMatchesRegularExpression( '/pagespeed.*?default:\s*scan/s', $this->contents );
	}

	/**
	 * No remaining "<action>" (required) synopsis should exist for the 5 verbs.
	 */
	public function test_no_required_action_synopsis_remains(): void {
		// Count occurrences of required "<action>" docblock line vs bracket variant.
		// Allow "<action>" inside comments like "Invalid cache action" error strings.
		preg_match_all( '/^\s*\*\s+<action>\s*$/m', $this->contents, $req );
		$this->assertSame( 0, count( $req[0] ), 'No required <action> synopsis lines should remain' );
		preg_match_all( '/^\s*\*\s+\[<action>\]/m', $this->contents, $opt );
		$this->assertGreaterThanOrEqual( 5, count( $opt[0] ) );
	}
}
