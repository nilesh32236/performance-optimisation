<?php
/**
 * Schema and drift tests for the Phase 3 architecture inventory.
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only local JSON reads and deterministic CLI drift check.

use PHPUnit\Framework\TestCase;

/**
 * Validate the tokenizer-derived architecture evidence committed to the repo.
 *
 * @since NEXT
 */
final class ArchitectureInventoryTest extends TestCase {

	/**
	 * Ensure committed graph artifacts exactly match current PHP syntax.
	 *
	 * @return void
	 */
	public function test_generated_artifacts_are_current(): void {
		$root    = dirname( __DIR__, 2 );
		$script  = $root . '/scripts/generate-class-inventory.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --check 2>&1';
		$output  = array();
		$status  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test-only deterministic local generator drift gate.
		exec( $command, $output, $status );

		$this->assertSame( 0, $status, "Architecture artifacts are stale:\n" . implode( "\n", $output ) );
	}

	/**
	 * Inventory entries identify symbols and distinguish runtime from protected scopes.
	 *
	 * @return void
	 */
	public function test_class_inventory_records_symbols_and_scope(): void {
		$inventory = $this->read_json( 'class-inventory.json' );
		$this->assertNotEmpty( $inventory );

		$root           = dirname( __DIR__, 2 );
		$expected_files = array_merge(
			(array) glob( $root . '/includes/*/class-*.php' ),
			(array) glob( $root . '/includes/*/trait-*.php' ),
			(array) glob( $root . '/includes/class-*.php' ),
			array( $root . '/includes/Support/redis-connect-helper.php', $root . '/templates/object-cache.php' )
		);
		$expected_files = array_map(
			static fn( string $file ): string => ltrim( str_replace( '\\', '/', substr( $file, strlen( $root ) ) ), '/' ),
			$expected_files
		);
		sort( $expected_files, SORT_STRING );
		$actual_files = array_column( $inventory, 'file' );
		sort( $actual_files, SORT_STRING );
		$this->assertSame( $expected_files, $actual_files, 'Inventory must contain every first-party class, trait, helper, and drop-in file exactly once.' );
		$this->assertSame( count( $actual_files ), count( array_unique( $actual_files ) ) );

		$by_file = array();
		foreach ( $inventory as $entry ) {
			$by_file[ $entry['file'] ] = $entry;
		}

		$this->assertArrayHasKey( 'includes/Core/class-main.php', $by_file );
		$this->assertSame( 'Main', $by_file['includes/Core/class-main.php']['class'] );
		$this->assertSame( 'PerformanceOptimise\\Inc\\Main', $by_file['includes/Core/class-main.php']['fqcn'] );
		$this->assertGreaterThan( 200, $by_file['includes/Core/class-main.php']['methods'] );
		$this->assertTrue( $by_file['includes/Core/class-main.php']['static_state'] );
		$this->assertGreaterThan( 0, $by_file['includes/Core/class-main.php']['static_properties'] );
		$this->assertSame( 'protected_vendor_adjacent', $by_file['includes/minify/class-css.php']['scope'] );
		$this->assertSame( 'drop_in', $by_file['templates/object-cache.php']['scope'] );

		$procedural = array_filter(
			$inventory,
			static fn( array $entry ): bool => null === $entry['fqcn']
		);
		$this->assertCount( 1, $procedural, 'Only the tracked Redis helper should be procedural inside the class inventory.' );
		$this->assertSame( 'includes/Support/redis-connect-helper.php', array_values( $procedural )[0]['file'] );
	}

	/**
	 * Recursively discover first-party PHP scope and reject untracked files.
	 *
	 * @return void
	 */
	public function test_first_party_php_scope_has_no_untracked_files(): void {
		$root      = dirname( __DIR__, 2 );
		$inventory = $this->read_json( 'class-inventory.json' );
		$allowed   = array_fill_keys( array_column( $inventory, 'file' ), true );
		foreach ( array( 'performance-optimisation.php', 'uninstall.php', 'templates/perf-translations.php' ) as $file ) {
			$allowed[ $file ] = true;
		}

		$discovered = array();
		foreach ( array( 'includes', 'templates' ) as $directory ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$path         = str_replace( '\\', '/', $file->getPathname() );
					$discovered[] = ltrim( substr( $path, strlen( $root ) ), '/' );
				}
			}
		}
		$root_php = glob( $root . '/*.php' );
		$this->assertIsArray( $root_php );
		foreach ( $root_php as $file ) {
			$discovered[] = basename( $file );
		}
		sort( $discovered, SORT_STRING );
		$this->assertSame( array(), array_values( array_diff( $discovered, array_keys( $allowed ) ) ), 'Every first-party PHP file must be explicitly inventoried or graph-scoped.' );
		$this->assertSame( array( 'performance-optimisation.php', 'uninstall.php' ), $root_php ? array_map( 'basename', $root_php ) : array() );

		$graph       = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$graph_files = array_values( array_unique( array_column( $graph['nodes'], 'file' ) ) );
		$expected    = array_keys( $allowed );
		sort( $graph_files, SORT_STRING );
		sort( $expected, SORT_STRING );
		$this->assertSame( $expected, $graph_files, 'Graph node files must equal the declared inventory plus entry/uninstall/translation scope.' );
	}

	/**
	 * Graph nodes retain every required tokenizer-derived reference category.
	 *
	 * @return void
	 */
	public function test_dependency_graph_records_required_reference_categories(): void {
		$graph = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$this->assertSame( 2, $graph['schema_version'] );
		$this->assertStringContainsString( 'PhpToken', $graph['analysis_method'] );
		$this->assertSame( 89, $graph['summary']['files'] );
		$this->assertSame( 85, $graph['summary']['class_like_nodes'] );
		$this->assertSame( 4, $graph['summary']['procedural_nodes'] );
		$this->assertSame( 392, $graph['summary']['edges'] );
		$this->assertSame( 391, $graph['summary']['runtime_edges'] );
		$this->assertSame( 206, $graph['summary']['compatibility_edges'] );
		$this->assertSame( 3, $graph['summary']['loader_edges'] );
		$this->assertNotEmpty( $graph['nodes'] );
		$this->assertNotEmpty( $graph['edges'] );
		$this->assertGreaterThan( 10, $graph['nodes']['WP_Object_Cache']['top_level_function_count'] );
		$this->assertNotEmpty( $graph['nodes']['WP_Object_Cache']['top_level_functions'] );
		$this->assertContains( 'wp_cache_get', array_column( $graph['nodes']['WP_Object_Cache']['top_level_functions'], 'name' ) );
		$this->assertGreaterThan( 0, $graph['nodes']['file:performance-optimisation.php']['top_level_function_count'] );

		$required = array(
			'static_calls',
			'instance_calls',
			'new_expressions',
			'class_references',
			'class_constant_references',
			'class_string_references',
			'class_exists',
			'method_exists',
			'interface_exists',
			'trait_exists',
			'reflection',
			'loader_references',
			'file_references',
		);
		foreach ( $graph['nodes'] as $node_id => $node ) {
			foreach ( $required as $category ) {
				$this->assertArrayHasKey( $category, $node['references'], "{$node_id} is missing {$category}." );
			}
			$this->assertArrayHasKey( 'imports', $node );
		}
	}

	/**
	 * Namespaced imports and literal class probes resolve to exact plugin nodes.
	 *
	 * @return void
	 */
	public function test_graph_resolves_imports_and_literal_class_probes(): void {
		$graph = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$main  = $graph['nodes']['PerformanceOptimise\\Inc\\Main'];
		$html  = $graph['nodes']['PerformanceOptimise\\Inc\\Minify\\HTML'];

		$this->assertContains( 'class:PerformanceOptimise\\Inc\\Util', $html['imports'] );
		$this->assertContains( 'PerformanceOptimise\\Inc\\Main', $html['runtime_dependencies'] );
		$this->assertContains( 'PerformanceOptimise\\Inc\\Util', $html['runtime_dependencies'] );
		$this->assertArrayHasKey( 'PerformanceOptimise\\Inc\\AI_Adaptive', $main['references']['class_exists'] );
		$this->assertArrayNotHasKey( 'PerformanceOptimise\\Inc\\PerformanceOptimise\\Inc\\AI_Adaptive', $main['references']['class_exists'] );
		$hooks = $graph['nodes']['PerformanceOptimise\\Inc\\Hook_Registry'];
		$this->assertArrayHasKey( 'PerformanceOptimise\\Inc\\Main', $hooks['references']['class_references'] );
		$this->assertArrayNotHasKey( 'PerformanceOptimise\\Inc\\Main', $hooks['references']['class_constant_references'] );
		$ai = $graph['nodes']['PerformanceOptimise\\Inc\\AI_Adaptive'];
		$this->assertArrayHasKey( 'PerformanceOptimise\\Inc\\Pagespeed', $ai['references']['class_constant_references'] );
		$this->assertArrayNotHasKey( 'PerformanceOptimise\\Inc\\Pagespeed::TREND_OPTION', $ai['references']['static_calls'] );
		$image = $graph['nodes']['PerformanceOptimise\\Inc\\Image_Optimisation'];
		$this->assertArrayHasKey( '\\wp_html_custom_data_attribute_name', $image['references']['wordpress_functions'] );
		$wordpress_calls = array();
		foreach ( $graph['nodes'] as $node ) {
			$wordpress_calls += $node['references']['wordpress_functions'];
		}
		foreach ( array( 'get_permalink', 'get_post_type_archive_link', 'get_current_user_id', 'add_meta_box', 'update_option', 'remove_action', 'is_404' ) as $function ) {
			$this->assertArrayHasKey( $function, $wordpress_calls );
		}
		$procedural_nodes = array(
			'file:includes/Support/redis-connect-helper.php',
			'file:performance-optimisation.php',
			'file:uninstall.php',
		);
		foreach ( $procedural_nodes as $node_id ) {
			foreach ( array_keys( $graph['nodes'][ $node_id ]['references']['external_functions'] ) as $function ) {
				$this->assertStringStartsNotWith( 'wppo_', strtolower( $function ) );
			}
		}
	}

	/**
	 * Runtime, loader, and compatibility edges remain distinct from documentation.
	 *
	 * @return void
	 */
	public function test_graph_classifications_and_documentation_separation(): void {
		$graph       = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$classifiers = array( 'runtime', 'loader', 'compatibility' );
		$node_ids    = array_keys( $graph['nodes'] );
		$doc_records = 0;
		foreach ( $graph['nodes'] as $node ) {
			$doc_records += count( $node['documentation_references'] ?? array() );
		}
		$this->assertGreaterThan( 0, $doc_records );
		foreach ( $graph['edges'] as $edge ) {
			$this->assertContains( $edge['from'], $node_ids );
			$this->assertContains( $edge['to'], $node_ids );
			$this->assertNotEmpty( $edge['classifications'] );
			foreach ( $edge['classifications'] as $classification ) {
				$this->assertContains( $classification, $classifiers );
				$this->assertNotSame( 'documentation', $classification );
			}
			$this->assertArrayHasKey( 'kinds', $edge );
			$this->assertNotEmpty( $edge['lines'] );
			foreach ( $edge['lines'] as $line ) {
				$this->assertIsInt( $line );
				$this->assertGreaterThanOrEqual( 0, $line );
			}
		}
		$loader = $graph['nodes']['PerformanceOptimise\\Inc\\Loader_Map'];
		$this->assertArrayHasKey( 'PerformanceOptimise\\Inc\\Cache', $loader['documentation_references'] );
		$loader_cache_edges = array_filter(
			$graph['edges'],
			static fn( array $edge ): bool => 'PerformanceOptimise\\Inc\\Loader_Map' === $edge['from']
				&& 'PerformanceOptimise\\Inc\\Cache' === $edge['to']
		);
		$this->assertSame( array(), array_values( $loader_cache_edges ), 'Documentation-only Loader_Map mentions must not create graph edges.' );

		$this->assertArrayHasKey( 'cycles_by_classification', $graph['metrics'] );
		foreach ( $graph['metrics']['cycles_by_classification'] as $classification => $cycles ) {
			$this->assertContains( $classification, $classifiers );
			foreach ( $cycles as $cycle ) {
				foreach ( $cycle['edges'] as $edge ) {
					$this->assertContains( $classification, $edge['classifications'] );
				}
			}
		}
	}

	/**
	 * Static state, method-size signals, SCCs, and duplicate candidates are populated.
	 *
	 * @return void
	 */
	public function test_heat_map_and_cycle_evidence_is_structurally_valid(): void {
		$graph                 = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$static_nodes          = 0;
		$static_property_total = 0;
		foreach ( $graph['nodes'] as $node ) {
			if ( $node['static_state'] ) {
				++$static_nodes;
			}
			$static_property_total += $node['static_property_count'];
		}
		$this->assertGreaterThan( 0, $static_nodes );
		$this->assertGreaterThan( 0, $static_property_total );
		$this->assertSame( $static_nodes, $graph['summary']['static_state_nodes'] );

		foreach ( $graph['metrics']['strongly_connected_components'] as $component ) {
			$sorted = $component;
			sort( $sorted, SORT_STRING );
			$this->assertSame( $sorted, $component );
			$this->assertSame( count( $component ), count( array_unique( $component ) ) );
		}
		$this->assertArrayHasKey( 'high_risk_hubs', $graph['metrics'] );
		$this->assertArrayHasKey( 'boundary_violations', $graph['metrics'] );
		$this->assertSame( 1, $graph['summary']['runtime_cyclic_components'] );
		$this->assertSame( 1, $graph['summary']['compatibility_cyclic_components'] );
		$this->assertCount( 1, $graph['metrics']['cycles_by_classification']['runtime'] );
		$this->assertCount( 68, $graph['metrics']['cycles_by_classification']['runtime'][0]['members'] );
		$this->assertCount( 338, $graph['metrics']['cycles_by_classification']['runtime'][0]['edges'] );
		$this->assertCount( 1, $graph['metrics']['cycles_by_classification']['compatibility'] );
		$this->assertCount( 22, $graph['metrics']['cycles_by_classification']['compatibility'][0]['members'] );
		$this->assertCount( 72, $graph['metrics']['cycles_by_classification']['compatibility'][0]['edges'] );

		$candidates = $graph['duplicate_candidates'];
		$this->assertCount( 17, $candidates );
		$ids    = array();
		$hashes = array();
		foreach ( $candidates as $candidate ) {
			$this->assertMatchesRegularExpression( '/^DUP-[A-F0-9]{12}$/', $candidate['id'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $candidate['shape_hash'] );
			$this->assertSame( 'DUP-' . strtoupper( substr( $candidate['shape_hash'], 0, 12 ) ), $candidate['id'] );
			$this->assertGreaterThanOrEqual( 12, $candidate['token_count'] );
			$this->assertGreaterThanOrEqual( 2, count( $candidate['nodes'] ) );
			$this->assertSame( count( $candidate['nodes'] ), count( array_unique( $candidate['nodes'] ) ) );
			$this->assertGreaterThanOrEqual( 2, count( $candidate['members'] ) );
			foreach ( $candidate['members'] as $member ) {
				$this->assertArrayHasKey( $member['node'], $graph['nodes'] );
				$this->assertFileExists( dirname( __DIR__, 2 ) . '/' . $member['file'] );
				$this->assertGreaterThan( 0, $member['line'] );
			}
			$ids[]    = $candidate['id'];
			$hashes[] = $candidate['shape_hash'];
		}
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
		$this->assertSame( count( $hashes ), count( array_unique( $hashes ) ) );
	}

	/**
	 * Read one committed architecture JSON artifact.
	 *
	 * @since NEXT
	 * @param string $filename Artifact filename.
	 * @return array<string|int,mixed>
	 */
	private function read_json( string $filename ): array {
		$path = dirname( __DIR__, 2 ) . '/docs/architecture/' . $filename;
		$this->assertFileExists( $path );
		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw );
		$data = json_decode( (string) $raw, true );
		$this->assertIsArray( $data );

		return $data;
	}
}
