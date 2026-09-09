<?php
/**
 * Tests for one-click autoload-bloat remediation (issue #934).
 *
 * Covers dry-run reporting, apply (non-core only), per-option revert and
 * the expired-only transient export in Database_Cleanup.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Database_Cleanup;
use Brain\Monkey\Functions;

// phpcs:disable WordPress.Files.FileName -- Pulls in the shared WP_Error test stand-in from TelemetryTest.php.

// The plugin constructs core's WP_Error on error paths; the unit environment
// has no WP core, so reuse the guarded stand-in co-located in TelemetryTest.php
// (require_once keeps it a single declaration whichever file loads first).
if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/TelemetryTest.php';
}

/**
 * Tests for autoload remediation and transient export.
 *
 * @package PerformanceOptimise\Tests
 */
class AutoloadRemediationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Captured wp_set_option_autoload calls.
	 *
	 * @var array<int, array{option:string,autoload:bool}>
	 */
	private array $autoload_calls = array();

	/**
	 * Captured update_option calls.
	 *
	 * @var array<string, mixed>
	 */
	private array $updated_options = array();

	/**
	 * In-memory option store for get_option stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $option_store = array();

	/**
	 * Rows returned by the wpdb mock for candidate queries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $candidate_rows = array();

	/**
	 * Total autoload bytes returned by the wpdb mock.
	 *
	 * @var int
	 */
	private int $total_bytes = 0;

	/**
	 * Set up the Autoload fixture + WP function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		// This class defines its own setUp(), shadowing the trait's — re-register
		// the shared Brain Monkey stubs (wp_using_ext_object_cache, etc.) here.
		$this->register_common_function_stubs();

		$this->autoload_calls  = array();
		$this->updated_options = array();
		$this->option_store    = array(
			'wppo_settings'            => array(
				'database_cleanup' => array( 'autoloadThreshold' => 1024 ),
			),
			'wppo_autoload_remediated' => array(),
		);
		// 2MB fixture: one ~1MB non-core option, one ~1MB core option, one tiny option.
		$this->candidate_rows = array(
			array(
				'option_name' => 'big_plugin_blob',
				'autoload'    => 'yes',
				'opt_size'    => 1048576,
			),
			array(
				'option_name' => 'rewrite_rules',
				'autoload'    => 'yes',
				'opt_size'    => 1048576,
			),
			array(
				'option_name' => 'tiny_option',
				'autoload'    => 'yes',
				'opt_size'    => 10,
			),
		);
		$this->total_bytes    = 2097162;

		$test = $this;

		$GLOBALS['wpdb'] = new class($test) extends WPPO_Autoload_DB_Base {
			/**
			 * Owning test case for fixture access.
			 *
			 * @var AutoloadRemediationTest
			 */
			private $test;

			/**
			 * Args from the most recent prepare() call (emulates WHERE binding).
			 *
			 * @var array<int, mixed>
			 */
			private $last_args = array();

			/**
			 * Constructor.
			 *
			 * @param AutoloadRemediationTest $test Owning test.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * Capture bound args so get_results() can emulate the WHERE clause.
			 *
			 * @param string $query SQL query.
			 * @param mixed  ...$args Prepared arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->last_args = $args;
				return $query;
			}

			/**
			 * Return fixture rows for candidate queries, expired rows for export queries.
			 *
			 * Emulates the `LENGTH(option_value) >= threshold` predicate using
			 * the bound threshold arg (last prepare arg for candidate queries).
			 *
			 * @param string|null $query SQL query.
			 * @param string|null $output Output type.
			 * @return array<int, array<string, mixed>>
			 */
			public function get_results( $query = null, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				if ( is_string( $query ) && false !== strpos( $query, 'timeout_value' ) ) {
					return $this->test->get_export_rows();
				}
				$rows      = $this->test->get_candidate_rows();
				$threshold = ! empty( $this->last_args ) ? (int) end( $this->last_args ) : 0;
				if ( $threshold > 0 ) {
					$rows = array_values(
						array_filter(
							$rows,
							static function ( $row ) use ( $threshold ) {
								return (int) ( $row['opt_size'] ?? 0 ) >= $threshold;
							}
						)
					);
				}
				return $rows;
			}

			/**
			 * Return the fixture total.
			 *
			 * @param string|null $query SQL query.
			 * @return int
			 */
			public function get_var( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return $this->test->get_total_bytes();
			}
		};

		Functions\when( 'wp_set_option_autoload' )->alias(
			function ( $option, $autoload ) use ( $test ) {
				$test->record_autoload_call( (string) $option, (bool) $autoload );
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->alias(
			static function ( $show = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return '6.8';
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( $test ) {
				return $test->read_option( (string) $name, $fallback );
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( $test ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$test->write_option( (string) $name, $value );
				return true;
			}
		);
	}

	/**
	 * Get fixture candidate rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_candidate_rows(): array {
		return $this->candidate_rows;
	}

	/**
	 * Get fixture total bytes.
	 *
	 * @return int
	 */
	public function get_total_bytes(): int {
		return $this->total_bytes;
	}

	/**
	 * Get fixture export rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_export_rows(): array {
		return array(
			array(
				'option_name'   => '_transient_expired_one',
				'timeout_name'  => '_transient_timeout_expired_one',
				'timeout_value' => (string) ( time() - 100 ),
				'opt_size'      => 42,
			),
		);
	}

	/**
	 * Record an autoload flip call.
	 *
	 * @param string $option Option name.
	 * @param bool   $autoload Autoload value.
	 * @return void
	 */
	public function record_autoload_call( string $option, bool $autoload ): void {
		$this->autoload_calls[] = array(
			'option'   => $option,
			'autoload' => $autoload,
		);
	}

	/**
	 * Read an option from the in-memory store.
	 *
	 * @param string $name Option name.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public function read_option( string $name, $fallback = false ) {
		return array_key_exists( $name, $this->option_store ) ? $this->option_store[ $name ] : $fallback;
	}

	/**
	 * Write an option to the in-memory store.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 * @return void
	 */
	public function write_option( string $name, $value ): void {
		$this->option_store[ $name ]    = $value;
		$this->updated_options[ $name ] = $value;
	}

	/**
	 * Dry run must change nothing and report the non-core candidate only.
	 */
	public function test_plan_dry_run_changes_nothing_and_excludes_core(): void {
		$report = Database_Cleanup::plan_autoload_remediation( 1024, 100 );

		$this->assertSame( array(), $this->autoload_calls, 'Dry run must not flip any option' );
		$this->assertArrayNotHasKey( Database_Cleanup::REMEDIATED_OPTION, $this->updated_options, 'Dry run must not persist priors' );
		$this->assertTrue( $report['supported'] );
		$this->assertSame( 1, $report['count'] );
		$this->assertSame( 1048576, $report['bytes_saved'] );
		$this->assertSame( 'big_plugin_blob', $report['options'][0]['option_name'] );
		$this->assertSame( $this->total_bytes, $report['total_autoload_bytes'] );
	}

	/**
	 * Apply must flip only the targeted non-core option and store its prior.
	 */
	public function test_apply_flips_only_targeted_options_with_core_untouched(): void {
		$result = Database_Cleanup::remediate_autoload( 1024, 100 );

		$this->assertSame( 1, count( $result['applied'] ) );
		$this->assertSame( 'big_plugin_blob', $result['applied'][0]['option_name'] );
		$this->assertSame( 'yes', $result['applied'][0]['prior'] );
		$this->assertSame( 1048576, $result['bytes_saved'] );
		$this->assertSame( array(), $result['failed'] );

		$flipped = array_column( $this->autoload_calls, 'option' );
		$this->assertContains( 'big_plugin_blob', $flipped );
		$this->assertNotContains( 'rewrite_rules', $flipped, 'Core options must never be touched' );

		$priors = $this->read_option( Database_Cleanup::REMEDIATED_OPTION, array() );
		$this->assertSame( array( 'big_plugin_blob' => 'yes' ), $priors );
	}

	/**
	 * Revert must restore the prior value and drop the stored entry.
	 */
	public function test_revert_restores_prior_value(): void {
		$this->option_store[ Database_Cleanup::REMEDIATED_OPTION ] = array( 'big_plugin_blob' => 'yes' );

		$result = Database_Cleanup::revert_autoload_option( 'big_plugin_blob' );

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				array(
					'option'   => 'big_plugin_blob',
					'autoload' => true,
				),
			),
			$this->autoload_calls
		);
		$this->assertSame( array(), $this->read_option( Database_Cleanup::REMEDIATED_OPTION, array() ) );
	}

	/**
	 * Reverting an option that was never remediated must fail closed.
	 */
	public function test_revert_unknown_option_returns_error(): void {
		$result = Database_Cleanup::revert_autoload_option( 'never_touched' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->autoload_calls );
	}

	/**
	 * Transient export must return the expired rows for pre-run review.
	 *
	 * On single-site both transient families live in wp_options, so the
	 * fixture row is returned once per family (2 rows total).
	 */
	public function test_export_expired_transients_returns_expired_rows(): void {
		$rows = Database_Cleanup::export_expired_transients( 500 );

		$this->assertCount( 2, $rows );
		$this->assertSame( '_transient_expired_one', $rows[0]['option_name'] );
		$this->assertSame( '_transient_timeout_expired_one', $rows[0]['timeout_option'] );
		$this->assertSame( 42, $rows[0]['size'] );
		$this->assertSame( array(), $this->autoload_calls, 'Export must be read-only' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile
// Minimal wpdb base for the autoload remediation tests.

if ( ! class_exists( 'WPPO_Autoload_DB_Base' ) ) {
	/**
	 * Minimal WPDB base for autoload remediation unit tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WPPO_Autoload_DB_Base {
		/**
		 * Last error state.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Table name stand-ins mirror $wpdb.
		 *
		 * @var string
		 */
		// phpcs:disable Squiz.Commenting.VariableComment -- Table-name stand-ins mirror $wpdb.
		public $prefix      = 'wp_';
		public $posts       = 'wp_posts';
		public $postmeta    = 'wp_postmeta';
		public $comments    = 'wp_comments';
		public $commentmeta = 'wp_commentmeta';
		public $options     = 'wp_options';
		// phpcs:enable Squiz.Commenting.VariableComment

		/**
		 * Return an empty column list.
		 *
		 * @param string|null $query SQL query.
		 * @return array<int, mixed>
		 */
		public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}

		/**
		 * Simulate a successful no-op query.
		 *
		 * @param string|null $query SQL query.
		 * @return int
		 */
		public function query( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return 0;
		}

		/**
		 * Simulate a successful insert.
		 *
		 * @param string|null $table Table name.
		 * @param array       $data Row data.
		 * @param array       $format Formats.
		 * @return int
		 */
		public function insert( $table = null, $data = array(), $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return 1;
		}

		/**
		 * Simulate a successful update.
		 *
		 * @param string|null $table Table name.
		 * @param array       $data Row data.
		 * @param array       $where Where clause.
		 * @return int
		 */
		public function update( $table = null, $data = array(), $where = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return 1;
		}

		/**
		 * Return the query unchanged.
		 *
		 * @param string $query SQL query.
		 * @param mixed  ...$args Prepared arguments.
		 * @return string
		 */
		public function prepare( $query, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return $query;
		}

		/**
		 * Return the text unchanged.
		 *
		 * @param string $text Text to escape.
		 * @return string
		 */
		public function esc_like( $text ) {
			return $text;
		}
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
