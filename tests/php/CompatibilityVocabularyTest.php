<?php
/**
 * Guards the compatibility vocabulary across every file that describes
 * docs/site/compatibility.html.
 *
 * WHY THIS EXISTS. The compatibility page was corrected from a four-tier
 * vocabulary (Verified / Supported / Best effort / Known limitation) down to
 * two (Supported / Best effort). Nothing in the suite referenced the page, so
 * the retired words survived in five other documents and each one was
 * rediscovered by hand — a repeated review finding rather than a caught
 * regression. See claims.md C-04 and C-09.
 *
 * WHAT IT ASSERTS.
 * 1. The page's own legend defines exactly the statuses its table uses.
 * 2. No other file describes this page using a status it no longer defines.
 *
 * WHEN IT ACTUALLY RUNS - read this before relying on it. The PHPUnit job
 * lives in .github/workflows/webpack.yml, which declares
 * `paths-ignore: ['**.md']` on both push and pull_request. A commit that
 * touches ONLY Markdown therefore does not run the suite, and this guard does
 * not execute for it. It fires when compatibility.html, another HTML page, or
 * any PHP file changes - which is the case that matters most, because that is
 * when the table itself moves.
 *
 * Narrowing that ignore so docs/growth/**.md triggers the suite is the real
 * fix and is tracked separately; until then this guard is a backstop for the
 * HTML and PHP paths, NOT an enforcement of the Markdown mirrors. Do not
 * describe it as the latter.
 *
 * @package PerformanceOptimise\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Guards the compatibility vocabulary across every document that names the page.
 *
 * @coversNothing
 */
class CompatibilityVocabularyTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Statuses the page is allowed to define.
	 *
	 * @var string[]
	 */
	private const ALLOWED = array( 'Supported', 'Best effort' );

	/**
	 * Statuses the page used to define and must not be referenced again.
	 *
	 * @var string[]
	 */
	private const RETIRED = array( 'Verified', 'Known limitation' );

	/**
	 * Set up the repository root.
	 */
	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
	}

	/**
	 * The page's legend must define exactly the statuses its table uses.
	 */
	public function test_legend_defines_exactly_the_statuses_the_table_uses(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- repository file on disk; wp_remote_get is HTTP and wrong here.
		$html = (string) file_get_contents( $this->root . '/docs/site/compatibility.html' );

		self::assertNotSame( '', $html, 'compatibility.html must be readable' );

		// Statuses actually carried by a table row.
		preg_match_all( '#<td>(Supported|Best effort|Known limitation|Verified)([^<]*)</td>#', $html, $rows );
		$variants = array_values( array_unique( array_filter( array_map( 'trim', $rows[2] ) ) ) );
		self::assertSame(
			array(),
			$variants,
			'every status cell must carry the bare status; a variant such as \'Supported with limitation\' is an undefined third status'
		);
		$used = array_values( array_unique( $rows[1] ) );
		self::assertNotEmpty( $used, 'the table must carry at least one status' );

		// Statuses defined by the legend.
		preg_match_all( '#<strong>(Supported|Best effort|Known limitation|Verified):</strong>#', $html, $legend );
		$defined = array_values( array_unique( $legend[1] ) );

		sort( $used );
		sort( $defined );

		$allowed = self::ALLOWED;
		sort( $allowed );

		self::assertSame(
			$allowed,
			$used,
			'the table must use exactly the supported statuses'
		);

		self::assertSame(
			$used,
			$defined,
			'the legend must define exactly the statuses the table uses; a defined status no row carries is dead vocabulary'
		);
	}

	/**
	 * No document may describe this page using a retired status.
	 *
	 * @dataProvider narrativeDocuments
	 *
	 * @param string $relative Path relative to the repository root.
	 */
	public function test_no_document_describes_the_page_with_a_retired_status( string $relative ): void {
		$path = $this->root . '/' . $relative;
		self::assertFileExists( $path, $relative . ' should exist' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- repository file on disk; wp_remote_get is HTTP and wrong here.
		$text = (string) file_get_contents( $path );

		// A retired status is only a violation when the file is talking about
		// THIS page. Files reference it as `docs/site/compatibility.html`,
		// `compatibility.html`, or `compatibility/`, so match the page name
		// rather than one spelling of its path.
		self::assertTrue(
			false !== strpos( $text, 'compatibility.html' )
				|| false !== strpos( $text, 'compatibility/' ),
			$relative . ' does not reference the page'
		);

		// A previous version scanned a 600-character window starting at the
		// FIRST page mention. In DOCUMENTATION-MAP.md that mention sits 3491
		// characters before the vocabulary list, so the window never reached
		// the text it was written to protect and the guard passed a document
		// carrying the retired tier. Scan the whole document, and allow the
		// words only in a sentence that says they are retired.
		foreach ( self::RETIRED as $retired ) {
			$offset = 0;
			while ( true ) {
				$at = strpos( $text, $retired, $offset );
				if ( false === $at ) {
					break;
				}
				$sentence = substr( $text, max( 0, $at - 120 ), 260 );
				self::assertStringContainsString(
					'retired',
					strtolower( $sentence ),
					$relative . ' describes the page with the retired status "' . $retired . '"'
				);
				$offset = $at + strlen( $retired );
			}
		}
	}

	/**
	 * Documents that describe the compatibility page in prose.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function narrativeDocuments(): array {
		return array(
			array( 'docs/growth/DOCUMENTATION-MAP.md' ),
			array( 'docs/growth/TRUST-AND-CONVERSION.md' ),
			array( 'docs/site/features.html' ),
			array( 'docs/site/performance-optimisation.html' ),
		);
	}
}
