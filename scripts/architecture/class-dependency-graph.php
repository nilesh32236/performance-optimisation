<?php
/**
 * Dependency graph and architecture heat-map builder.
 *
 * Development tooling only; WordPress never loads this file.
 *
 * @package PerformanceOptimise\Architecture
 * @since   NEXT
 */

namespace PerformanceOptimise\Architecture;

/**
 * Build deterministic graph, cycle, boundary, and class heat-map metrics.
 *
 * @since NEXT
 */
final class Dependency_Graph {

	/**
	 * Methods at or above this source span are reported as large methods.
	 *
	 * @since NEXT
	 */
	public const LARGE_METHOD_LINES = 80;

	/**
	 * Exact normalized method shapes considered for duplicate review.
	 *
	 * @since NEXT
	 */
	public const DUPLICATE_MIN_TOKENS = 12;

	/**
	 * Resolved source nodes keyed by node ID.
	 *
	 * @since NEXT
	 * @var array<string,array<string,mixed>>
	 */
	private array $nodes;

	/**
	 * Flattened dependency edges keyed by source then target node ID.
	 *
	 * @since NEXT
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $edges = array();

	/**
	 * Duplicate candidate groups.
	 *
	 * @since NEXT
	 * @var array<int,array<string,mixed>>
	 */
	private array $duplicates = array();

	/**
	 * Build graph metrics for resolved analyzer nodes.
	 *
	 * @since NEXT
	 * @param array<string,array<string,mixed>> $nodes Resolved nodes.
	 */
	public function __construct( array $nodes ) {
		$this->nodes = $nodes;
		$this->build_edges();
		$this->duplicates = $this->find_duplicates();
	}

	/**
	 * Produce the committed dependency graph payload.
	 *
	 * @since NEXT
	 * @return array<string,mixed>
	 */
	public function graph(): array {
		$fan_in             = $this->fan_counts( 'in' );
		$fan_out            = $this->fan_counts( 'out' );
		$evidence_in        = $this->evidence_counts( 'in' );
		$evidence_out       = $this->evidence_counts( 'out' );
		$runtime_fan_in     = $this->fan_counts( 'in', 'runtime' );
		$runtime_fan_out    = $this->fan_counts( 'out', 'runtime' );
		$compat_fan_in      = $this->fan_counts( 'in', 'compatibility' );
		$compat_fan_out     = $this->fan_counts( 'out', 'compatibility' );
		$loader_fan_in      = $this->fan_counts( 'in', 'loader' );
		$loader_fan_out     = $this->fan_counts( 'out', 'loader' );
		$components         = $this->strongly_connected_components();
		$runtime_components = $this->strongly_connected_components( 'runtime' );
		$compat_components  = $this->strongly_connected_components( 'compatibility' );
		$loader_components  = $this->strongly_connected_components( 'loader' );
		$component_by_node  = $this->component_lookup( $components );
		$cross_domain       = $this->cross_domain_edges();
		$violations         = $this->boundary_violations();
		$bridge_candidates  = $this->bridge_candidates();
		$hubs               = $this->high_risk_hubs( $fan_in, $fan_out, $compat_fan_in, $cross_domain );
		$public_nodes       = array();

		foreach ( $this->nodes as $node_id => $node ) {
			$methods = $node['method_details'];
			usort(
				$methods,
				static function ( array $left, array $right ): int {
					return $right['lines'] <=> $left['lines'];
				}
			);
			$top_level_functions = array();
			foreach ( $node['top_level_functions'] as $function ) {
				$top_level_functions[] = array(
					'name' => $function['name'],
					'line' => $function['line'],
				);
			}
			$method_metrics = array();
			foreach ( array_slice( $methods, 0, 10 ) as $method ) {
				$method_metrics[] = array(
					'name'       => $method['name'],
					'line'       => $method['line'],
					'end_line'   => $method['end_line'],
					'lines'      => $method['lines'],
					'visibility' => $method['visibility'],
					'static'     => $method['static'],
					'delegates'  => $method['delegates'],
				);
			}
			$references    = $this->compact_reference_maps( $node['references'] );
			$documentation = array();
			foreach ( $node['documentation_references'] as $reference ) {
				$name = $reference['name'];
				if ( ! isset( $documentation[ $name ] ) || $reference['line'] < $documentation[ $name ] ) {
					$documentation[ $name ] = $reference['line'];
				}
			}
			ksort( $documentation, SORT_STRING );

			$public_nodes[ $node_id ] = array(
				'id'                         => $node_id,
				'name'                       => $node['name'],
				'fqcn'                       => $node['fqcn'],
				'kind'                       => $node['kind'],
				'file'                       => $node['file'],
				'domain'                     => $node['domain'],
				'namespace'                  => $node['namespace'],
				'lines'                      => $node['lines'],
				'property_count'             => $node['property_count'],
				'static_property_count'      => $node['static_property_count'],
				'public_property_count'      => $node['public_property_count'],
				'protected_property_count'   => $node['protected_property_count'],
				'private_property_count'     => $node['private_property_count'],
				'static_state'               => $node['static_property_count'] > 0,
				'method_count'               => count( $node['method_details'] ),
				'top_level_function_count'   => count( $node['top_level_functions'] ),
				'top_level_functions'        => $top_level_functions,
				'largest_methods'            => $method_metrics,
				'imports'                    => $node['imports'],
				'references'                 => $references,
				'extends'                    => $node['extends'],
				'implements'                 => $node['implements'],
				'trait_usage'                => $node['trait_usage'],
				'runtime_dependencies'       => $node['runtime_dependencies'],
				'compatibility_dependencies' => $node['compatibility_dependencies'],
				'loader_dependencies'        => $node['loader_dependencies'],
				'documentation_references'   => $documentation,
				'delegating_methods'         => $node['delegating_methods'],
				'hooks'                      => $node['hooks'],
				'metrics'                    => array(
					'fan_in'                       => $fan_in[ $node_id ] ?? 0,
					'fan_out'                      => $fan_out[ $node_id ] ?? 0,
					'incoming_reference_evidence'  => $evidence_in[ $node_id ] ?? 0,
					'outgoing_reference_evidence'  => $evidence_out[ $node_id ] ?? 0,
					'runtime_fan_in'               => $runtime_fan_in[ $node_id ] ?? 0,
					'runtime_fan_out'              => $runtime_fan_out[ $node_id ] ?? 0,
					'compatibility_fan_in'         => $compat_fan_in[ $node_id ] ?? 0,
					'compatibility_fan_out'        => $compat_fan_out[ $node_id ] ?? 0,
					'loader_fan_in'                => $loader_fan_in[ $node_id ] ?? 0,
					'loader_fan_out'               => $loader_fan_out[ $node_id ] ?? 0,
					'cross_domain_edges'           => $this->node_cross_domain_count( $node_id ),
					'boundary_violations'          => $this->node_violation_count( $node_id ),
					'strongly_connected_component' => $component_by_node[ $node_id ] ?? null,
				),
			);
		}

		$flat_edges = $this->flat_edges();
		$summary    = array(
			'files'                           => count( $this->nodes ),
			'class_like_nodes'                => count( array_filter( $this->nodes, static fn( array $node ): bool => null !== $node['fqcn'] ) ),
			'procedural_nodes'                => count( array_filter( $this->nodes, static fn( array $node ): bool => null === $node['fqcn'] ) ),
			'edges'                           => count( $flat_edges ),
			'runtime_edges'                   => count( array_filter( $flat_edges, static fn( array $edge ): bool => in_array( 'runtime', $edge['classifications'], true ) ) ),
			'compatibility_edges'             => count( array_filter( $flat_edges, static fn( array $edge ): bool => in_array( 'compatibility', $edge['classifications'], true ) ) ),
			'loader_edges'                    => count( array_filter( $flat_edges, static fn( array $edge ): bool => in_array( 'loader', $edge['classifications'], true ) ) ),
			'cross_domain_edges'              => count( $cross_domain ),
			'feature_to_feature_edges'        => count( array_filter( $cross_domain, static fn( array $edge ): bool => $edge['feature_to_feature'] ) ),
			'boundary_violations'             => count( $violations ),
			'bridge_candidates'               => count( $bridge_candidates ),
			'cyclic_components'               => count( array_filter( $components, static fn( array $component ): bool => count( $component ) > 1 ) ),
			'runtime_cyclic_components'       => count( array_filter( $runtime_components, static fn( array $component ): bool => count( $component ) > 1 ) ),
			'compatibility_cyclic_components' => count( array_filter( $compat_components, static fn( array $component ): bool => count( $component ) > 1 ) ),
			'duplicate_candidate_groups'      => count( $this->duplicates ),
			'static_state_nodes'              => count( array_filter( $this->nodes, static fn( array $node ): bool => $node['static_property_count'] > 0 ) ),
		);

		return array(
			'schema_version'       => 2,
			'generator'            => 'php scripts/generate-class-inventory.php',
			'analysis_method'      => 'PhpToken::tokenize(TOKEN_PARSE), comments excluded from runtime edges',
			'graph_scope'          => array(
				'includes'     => true,
				'templates'    => true,
				'plugin_entry' => true,
				'uninstall'    => true,
				'excluded'     => array( 'build', 'docs', 'node_modules', 'scripts', 'tests', 'vendor' ),
			),
			'definitions'          => array(
				'runtime'                 => 'Executable import resolution, static call, class-constant reference, class-string reference, new expression, inheritance, or trait use.',
				'loader'                  => 'Executable dependency on Loader_Map or the plugin autoload registration.',
				'compatibility'           => 'class_exists/method_exists/interface_exists/trait_exists probing or a call to a thin delegating facade method.',
				'documentation'           => 'Class names found only in comments/docblocks; recorded but never emitted as graph edges.',
				'large_method'            => 'Named method spanning at least ' . self::LARGE_METHOD_LINES . ' source lines.',
				'duplicate_candidate'     => 'Methods with at least ' . self::DUPLICATE_MIN_TOKENS . ' normalized tokens and the same exact token shape across nodes; semantic review is still required.',
				'wordpress_dependencies'  => 'Explicit WordPress API names plus the wp_/_wp_ prefixes; built-in PHP names are excluded from this signal.',
				'reference_line_evidence' => 'Node reference maps retain the first source line per name; edge evidence_count retains all occurrence lines, and the raw analyzer retains the complete line map.',
				'high_risk_hub_score'     => 'fan_in + fan_out + 2*compatibility_fan_in + cross_domain_edges + static_state + lines/1000.',
			),
			'summary'              => $summary,
			'nodes'                => $public_nodes,
			'edges'                => $flat_edges,
			'metrics'              => array(
				'fan_in'                        => $fan_in,
				'fan_out'                       => $fan_out,
				'incoming_reference_evidence'   => $evidence_in,
				'outgoing_reference_evidence'   => $evidence_out,
				'runtime_fan_in'                => $runtime_fan_in,
				'runtime_fan_out'               => $runtime_fan_out,
				'compatibility_fan_in'          => $compat_fan_in,
				'compatibility_fan_out'         => $compat_fan_out,
				'loader_fan_in'                 => $loader_fan_in,
				'loader_fan_out'                => $loader_fan_out,
				'strongly_connected_components' => $components,
				'dependency_cycles'             => $this->dependency_cycles( $components ),
				'cycles_by_classification'      => array(
					'runtime'       => $this->dependency_cycles( $runtime_components, 'runtime' ),
					'compatibility' => $this->dependency_cycles( $compat_components, 'compatibility' ),
					'loader'        => $this->dependency_cycles( $loader_components, 'loader' ),
				),
				'high_risk_hubs'                => $hubs,
				'cross_domain_edges'            => $cross_domain,
				'boundary_violations'           => $violations,
				'bridge_candidates'             => $bridge_candidates,
			),
			'duplicate_candidates' => $this->duplicates,
		);
	}

	/**
	 * Produce class-inventory entries for the requested source files.
	 *
	 * @since NEXT
	 * @param string[] $inventory_files Relative file paths to include.
	 * @return array<int,array<string,mixed>>
	 */
	public function inventory( array $inventory_files ): array {
		$graph        = $this->graph();
		$fan_in       = $graph['metrics']['fan_in'];
		$fan_out      = $graph['metrics']['fan_out'];
		$evidence_in  = $graph['metrics']['incoming_reference_evidence'];
		$evidence_out = $graph['metrics']['outgoing_reference_evidence'];
		$runtime_in   = $graph['metrics']['runtime_fan_in'];
		$runtime_out  = $graph['metrics']['runtime_fan_out'];
		$compat_in    = $graph['metrics']['compatibility_fan_in'];
		$compat_out   = $graph['metrics']['compatibility_fan_out'];
		$inventory    = array();

		foreach ( $inventory_files as $file ) {
			$node = $this->node_for_file( (string) $file );
			if ( null === $node ) {
				continue;
			}
			$node_id            = $node['id'];
			$large_methods      = array();
			$method_prefixes    = array();
			$public_methods     = 0;
			$external_classes   = $node['references']['external_classes'];
			$external_functions = $node['references']['external_functions'];
			foreach ( $node['method_details'] as $method ) {
				if ( self::LARGE_METHOD_LINES <= $method['lines'] ) {
					$large_methods[] = array(
						'name'  => $method['name'],
						'line'  => $method['line'],
						'lines' => $method['lines'],
					);
				}
				if ( 'public' === $method['visibility'] ) {
					++$public_methods;
				}
				$prefix = $this->method_prefix( $method['name'] );
				if ( '' !== $prefix ) {
					$method_prefixes[ $prefix ] = ( $method_prefixes[ $prefix ] ?? 0 ) + 1;
				}
			}
			usort(
				$large_methods,
				static function ( array $left, array $right ): int {
					return $right['lines'] <=> $left['lines'];
				}
			);
			arsort( $method_prefixes, SORT_NUMERIC );
			$feature_dependencies = array();
			foreach ( array_merge( $node['runtime_dependencies'], $node['compatibility_dependencies'], $node['loader_dependencies'] ) as $dependency ) {
				if ( isset( $this->nodes[ $dependency ] ) && in_array( $this->nodes[ $dependency ]['domain'], $this->feature_domains(), true ) ) {
					$feature_dependencies[ $dependency ] = true;
				}
			}
			$refs = array();
			foreach ( array_keys( $node['plugin_dependencies'] ) as $dependency ) {
				if ( isset( $this->nodes[ $dependency ] ) ) {
					$refs[] = $this->nodes[ $dependency ]['name'];
				}
			}
			$refs = array_values( array_unique( $refs ) );
			sort( $refs, SORT_STRING );
			$duplicate_count = count(
				array_filter(
					$this->duplicates,
					static fn( array $candidate ): bool => in_array( $node_id, $candidate['nodes'], true )
				)
			);
			$largest         = $large_methods[0] ?? null;
			if ( null === $largest ) {
				foreach ( $node['method_details'] as $method ) {
					if ( null === $largest || $method['lines'] > $largest['lines'] ) {
						$largest = array(
							'name'  => $method['name'],
							'line'  => $method['line'],
							'lines' => $method['lines'],
						);
					}
				}
			}

			$inventory[] = array(
				'file'                         => $node['file'],
				'class'                        => $node['name'],
				'fqcn'                         => $node['fqcn'],
				'kind'                         => $node['kind'],
				'scope'                        => $this->scope_for_file( $node['file'] ),
				'domain'                       => $node['domain'],
				'lines'                        => $node['lines'],
				'methods'                      => count( $node['method_details'] ),
				'public_methods'               => $public_methods,
				'large_methods'                => count( $large_methods ),
				'large_method_threshold_lines' => self::LARGE_METHOD_LINES,
				'largest_method'               => $largest,
				'method_prefixes'              => $method_prefixes,
				'hooks'                        => array_keys( $node['hooks'] ),
				'static_state'                 => $node['static_property_count'] > 0,
				'static_properties'            => $node['static_property_count'],
				'private_state_properties'     => $node['private_property_count'],
				'protected_state_properties'   => $node['protected_property_count'],
				'public_state_properties'      => $node['public_property_count'],
				'external_dependencies'        => count( $external_classes ) + count( $external_functions ),
				'wordpress_dependencies'       => count( $node['references']['wordpress_functions'] ),
				'filesystem_dependencies'      => count( $node['references']['filesystem_dependencies'] ),
				'network_dependencies'         => count( $node['references']['network_dependencies'] ),
				'database_dependencies'        => count( $node['references']['database_dependencies'] ),
				'feature_dependencies'         => count( $feature_dependencies ),
				'compat_facade_methods'        => count( $node['delegating_methods'] ),
				'duplicate_code_candidates'    => $duplicate_count,
				'fan_in'                       => $fan_in[ $node_id ] ?? 0,
				'fan_out'                      => $fan_out[ $node_id ] ?? 0,
				'incoming_reference_evidence'  => $evidence_in[ $node_id ] ?? 0,
				'outgoing_reference_evidence'  => $evidence_out[ $node_id ] ?? 0,
				'runtime_fan_in'               => $runtime_in[ $node_id ] ?? 0,
				'runtime_fan_out'              => $runtime_out[ $node_id ] ?? 0,
				'compatibility_fan_in'         => $compat_in[ $node_id ] ?? 0,
				'compatibility_fan_out'        => $compat_out[ $node_id ] ?? 0,
				'cross_domain_edges'           => $this->node_cross_domain_count( $node_id ),
				'refs'                         => $refs,
			);
		}

		usort(
			$inventory,
			static function ( array $left, array $right ): int {
				return strcmp( $left['file'], $right['file'] );
			}
		);

		return $inventory;
	}

	/**
	 * Compact reference maps to unique names with first source lines.
	 *
	 * Plugin edges retain every evidence line separately. This keeps node-level
	 * reference dictionaries useful for lookup without duplicating large line
	 * arrays for external APIs, self calls, and documentation-only names.
	 *
	 * @since NEXT
	 * @param array<string,mixed> $references Resolved reference maps.
	 * @return array<string,mixed>
	 */
	private function compact_reference_maps( array $references ): array {
		$compact = array();
		foreach ( $references as $kind => $map ) {
			$compact[ $kind ] = array();
			foreach ( $map as $name => $lines ) {
				$compact[ $kind ][ (string) $name ] = is_array( $lines ) ? (int) min( $lines ) : (int) $lines;
			}
			ksort( $compact[ $kind ], SORT_STRING );
		}

		return $compact;
	}

	/**
	 * Flatten nested edge storage.
	 *
	 * @since NEXT
	 * @return array<int,array<string,mixed>>
	 */
	private function flat_edges(): array {
		$flat = array();
		foreach ( $this->edges as $source => $targets ) {
			foreach ( $targets as $edge ) {
				$flat[] = $edge;
			}
		}
		usort(
			$flat,
			static function ( array $left, array $right ): int {
				$by_from = strcmp( $left['from'], $right['from'] );
				return 0 !== $by_from ? $by_from : strcmp( $left['to'], $right['to'] );
			}
		);

		return $flat;
	}

	/**
	 * Build source-to-target edges from resolved plugin dependencies.
	 *
	 * @since NEXT
	 * @return void
	 */
	private function build_edges(): void {
		foreach ( $this->nodes as $source => $node ) {
			foreach ( $node['plugin_dependencies'] as $target => $dependency ) {
				if ( $source === $target || ! isset( $this->nodes[ $target ] ) ) {
					continue;
				}
				$edge                              = array(
					'from'            => $source,
					'to'              => $target,
					'from_file'       => $node['file'],
					'to_file'         => $this->nodes[ $target ]['file'],
					'from_domain'     => $node['domain'],
					'to_domain'       => $this->nodes[ $target ]['domain'],
					'classifications' => $dependency['classifications'],
					'kinds'           => $dependency['kinds'],
					'lines'           => $dependency['lines'],
					'evidence_count'  => count( $dependency['lines'] ),
				);
				$edge['cross_domain']              = $edge['from_domain'] !== $edge['to_domain'];
				$edge['boundary_violation']        = $this->boundary_reason( $edge );
				$this->edges[ $source ][ $target ] = $edge;
			}
		}
	}

	/**
	 * Count unique incoming or outgoing edges.
	 *
	 * @since NEXT
	 * @param string      $direction     `in` or `out`.
	 * @param string|null $classification Optional classification filter.
	 * @return array<string,int>
	 */
	private function fan_counts( string $direction, ?string $classification = null ): array {
		$counts = array();
		foreach ( $this->nodes as $node_id => $node ) {
			$counts[ $node_id ] = 0;
		}
		foreach ( $this->flat_edges() as $edge ) {
			if ( null !== $classification && ! in_array( $classification, $edge['classifications'], true ) ) {
				continue;
			}
			$node_id = 'in' === $direction ? $edge['to'] : $edge['from'];
			if ( isset( $counts[ $node_id ] ) ) {
				++$counts[ $node_id ];
			}
		}
		ksort( $counts, SORT_STRING );

		return $counts;
	}

	/**
	 * Count all source occurrences behind incoming or outgoing edges.
	 *
	 * @since NEXT
	 * @param string $direction `in` or `out`.
	 * @return array<string,int>
	 */
	private function evidence_counts( string $direction ): array {
		$counts = array();
		foreach ( $this->nodes as $node_id => $node ) {
			$counts[ $node_id ] = 0;
		}
		foreach ( $this->flat_edges() as $edge ) {
			$node_id = 'in' === $direction ? $edge['to'] : $edge['from'];
			if ( isset( $counts[ $node_id ] ) ) {
				$counts[ $node_id ] += $edge['evidence_count'];
			}
		}
		ksort( $counts, SORT_STRING );

		return $counts;
	}

	/**
	 * Compute Tarjan strongly connected components deterministically.
	 *
	 * @since NEXT
	 * @param string|null $classification Optional edge classification filter.
	 * @return array<int,array<int,string>>
	 */
	private function strongly_connected_components( ?string $classification = null ): array {
		$adjacency = array();
		foreach ( $this->nodes as $node_id => $node ) {
			$targets = array();
			foreach ( $this->edges[ $node_id ] ?? array() as $target => $edge ) {
				if ( null === $classification || in_array( $classification, $edge['classifications'], true ) ) {
					$targets[] = $target;
				}
			}
			$adjacency[ $node_id ] = $targets;
			sort( $adjacency[ $node_id ], SORT_STRING );
		}
		$index    = 0;
		$stack    = array();
		$on_stack = array();
		$indices  = array();
		$lowlinks = array();
		$result   = array();

		foreach ( array_keys( $adjacency ) as $node_id ) {
			if ( ! isset( $indices[ $node_id ] ) ) {
				$this->tarjan_visit( $node_id, $adjacency, $index, $stack, $on_stack, $indices, $lowlinks, $result );
			}
		}
		foreach ( $result as &$component ) {
			sort( $component, SORT_STRING );
		}
		unset( $component );
		usort(
			$result,
			static function ( array $left, array $right ): int {
				return strcmp( $left[0], $right[0] );
			}
		);

		return $result;
	}

	/**
	 * Recursive Tarjan visit helper.
	 *
	 * @since NEXT
	 * @param string                          $node_id  Current node.
	 * @param array<string,array<int,string>> $adjacency Adjacency list.
	 * @param int                             $index    Tarjan index.
	 * @param array<int,string>               $stack    Tarjan stack.
	 * @param array<string,bool>              $on_stack Stack membership.
	 * @param array<string,int>               $indices  Node indices.
	 * @param array<string,int>               $lowlinks Node low links.
	 * @param array<int,array<int,string>>    $result   Components.
	 * @return void
	 */
	private function tarjan_visit( string $node_id, array $adjacency, int &$index, array &$stack, array &$on_stack, array &$indices, array &$lowlinks, array &$result ): void {
		$indices[ $node_id ]  = $index;
		$lowlinks[ $node_id ] = $index;
		++$index;
		$stack[]              = $node_id;
		$on_stack[ $node_id ] = true;

		foreach ( $adjacency[ $node_id ] as $target ) {
			if ( ! isset( $indices[ $target ] ) ) {
				$this->tarjan_visit( $target, $adjacency, $index, $stack, $on_stack, $indices, $lowlinks, $result );
				$lowlinks[ $node_id ] = min( $lowlinks[ $node_id ], $lowlinks[ $target ] );
			} elseif ( isset( $on_stack[ $target ] ) ) {
				$lowlinks[ $node_id ] = min( $lowlinks[ $node_id ], $indices[ $target ] );
			}
		}

		if ( $lowlinks[ $node_id ] !== $indices[ $node_id ] ) {
			return;
		}
		$component = array();
		do {
			$member = array_pop( $stack );
			if ( ! is_string( $member ) ) {
				return;
			}
			$component[] = $member;
			unset( $on_stack[ $member ] );
		} while ( $member !== $node_id );
		$result[] = $component;
	}

	/**
	 * Build node-to-component lookup.
	 *
	 * @since NEXT
	 * @param array<int,array<int,string>> $components SCC list.
	 * @return array<string,int>
	 */
	private function component_lookup( array $components ): array {
		$lookup = array();
		foreach ( $components as $index => $component ) {
			foreach ( $component as $node_id ) {
				$lookup[ $node_id ] = $index;
			}
		}

		return $lookup;
	}

	/**
	 * List only multi-node strongly connected components as cycles.
	 *
	 * @since NEXT
	 * @param array<int,array<int,string>> $components SCC list.
	 * @param string|null                  $classification Optional edge classification filter.
	 * @return array<int,array<string,mixed>>
	 */
	private function dependency_cycles( array $components, ?string $classification = null ): array {
		$cycles = array();
		foreach ( $components as $component ) {
			if ( count( $component ) < 2 ) {
				continue;
			}
			$members = array_fill_keys( $component, true );
			$edges   = array();
			foreach ( $this->flat_edges() as $edge ) {
				if ( ( null === $classification || in_array( $classification, $edge['classifications'], true ) )
					&& isset( $members[ $edge['from'] ], $members[ $edge['to'] ] ) ) {
					$edges[] = array(
						'from'            => $edge['from'],
						'to'              => $edge['to'],
						'classifications' => $edge['classifications'],
						'kinds'           => $edge['kinds'],
					);
				}
			}
			$cycles[] = array(
				'members' => $component,
				'edges'   => $edges,
			);
		}

		return $cycles;
	}

	/**
	 * Return cross-domain edges for coupling review.
	 *
	 * @since NEXT
	 * @return array<int,array<string,mixed>>
	 */
	private function cross_domain_edges(): array {
		$edges = array();
		foreach ( $this->flat_edges() as $edge ) {
			if ( $edge['cross_domain'] ) {
				$edge['feature_to_feature'] = in_array( $edge['from_domain'], $this->feature_domains(), true )
					&& in_array( $edge['to_domain'], $this->feature_domains(), true );
				$edges[]                    = $edge;
			}
		}

		return $edges;
	}

	/**
	 * Return strict dependency-direction violations.
	 *
	 * @since NEXT
	 * @return array<int,array<string,mixed>>
	 */
	private function boundary_violations(): array {
		$violations = array();
		foreach ( $this->flat_edges() as $edge ) {
			if ( '' === $edge['boundary_violation'] ) {
				continue;
			}
			$edge['reason'] = $edge['boundary_violation'];
			unset( $edge['boundary_violation'] );
			$edge['severity'] = 'review';
			if ( in_array( $edge['from_domain'], $this->feature_domains(), true )
				&& in_array( $edge['to_domain'], array( 'Admin', 'Core' ), true ) ) {
				$edge['severity'] = 'high';
			}
			$violations[] = $edge;
		}

		return $violations;
	}

	/**
	 * Return compatibility/facade edges for bridge cleanup review.
	 *
	 * @since NEXT
	 * @return array<int,array<string,mixed>>
	 */
	private function bridge_candidates(): array {
		$bridges = array();
		foreach ( $this->flat_edges() as $edge ) {
			if ( in_array( 'compatibility', $edge['classifications'], true )
				|| in_array( $this->short_name( $edge['to'] ), array( 'Main', 'Util', 'Cache', 'Critical_CSS', 'AI_Adaptive', 'Image_Optimisation' ), true ) ) {
				$bridges[] = $edge;
			}
		}

		return $bridges;
	}

	/**
	 * Rank high-risk hubs using documented graph signals.
	 *
	 * @since NEXT
	 * @param array<string,int>              $fan_in           Incoming unique edges.
	 * @param array<string,int>              $fan_out          Outgoing unique edges.
	 * @param array<string,int>              $compat_fan_in    Compatibility fan-in.
	 * @param array<int,array<string,mixed>> $cross_domain Cross-domain edges.
	 * @return array<int,array<string,mixed>>
	 */
	private function high_risk_hubs( array $fan_in, array $fan_out, array $compat_fan_in, array $cross_domain ): array {
		$cross_counts = array();
		foreach ( $cross_domain as $edge ) {
			foreach ( array( $edge['from'], $edge['to'] ) as $node_id ) {
				$cross_counts[ $node_id ] = ( $cross_counts[ $node_id ] ?? 0 ) + 1;
			}
		}
		$hubs = array();
		foreach ( $this->nodes as $node_id => $node ) {
			if ( null === $node['fqcn'] ) {
				continue;
			}
			$score  = ( $fan_in[ $node_id ] ?? 0 )
				+ ( $fan_out[ $node_id ] ?? 0 )
				+ 2 * ( $compat_fan_in[ $node_id ] ?? 0 )
				+ ( $cross_counts[ $node_id ] ?? 0 )
				+ ( $node['static_property_count'] > 0 ? 1 : 0 )
				+ ( $node['lines'] / 1000 );
			$hubs[] = array(
				'node'             => $node_id,
				'file'             => $node['file'],
				'score'            => round( $score, 2 ),
				'fan_in'           => $fan_in[ $node_id ] ?? 0,
				'fan_out'          => $fan_out[ $node_id ] ?? 0,
				'compatibility_in' => $compat_fan_in[ $node_id ] ?? 0,
				'cross_domain'     => $cross_counts[ $node_id ] ?? 0,
				'static_state'     => $node['static_property_count'] > 0,
				'lines'            => $node['lines'],
			);
		}
		usort(
			$hubs,
			static function ( array $left, array $right ): int {
				$by_score = $right['score'] <=> $left['score'];
				return 0 !== $by_score ? $by_score : strcmp( $left['node'], $right['node'] );
			}
		);

		return array_slice( $hubs, 0, 25 );
	}

	/**
	 * Find exact normalized method-body shapes across source nodes.
	 *
	 * @since NEXT
	 * @return array<int,array<string,mixed>>
	 */
	private function find_duplicates(): array {
		$groups = array();
		foreach ( $this->nodes as $node_id => $node ) {
			foreach ( $node['method_details'] as $method ) {
				if ( $method['body_size'] < self::DUPLICATE_MIN_TOKENS ) {
					continue;
				}
				$key = hash( 'sha256', implode( "\x1f", $method['body_key'] ) );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'token_count' => $method['body_size'],
						'members'     => array(),
					);
				}
				$groups[ $key ]['members'][ $node_id . '::' . $method['name'] . '@' . $method['line'] ] = array(
					'node'   => $node_id,
					'file'   => $node['file'],
					'method' => $method['name'],
					'line'   => $method['line'],
					'lines'  => $method['lines'],
				);
			}
		}
		$candidates = array();
		foreach ( $groups as $shape_hash => $group ) {
			$nodes = array_values( array_unique( array_column( $group['members'], 'node' ) ) );
			if ( count( $nodes ) < 2 ) {
				continue;
			}
			$members = array_values( $group['members'] );
			usort(
				$members,
				static function ( array $left, array $right ): int {
					$by_node = strcmp( $left['node'], $right['node'] );
					return 0 !== $by_node ? $by_node : $left['line'] <=> $right['line'];
				}
			);
			$candidates[] = array(
				'id'          => 'DUP-' . strtoupper( substr( (string) $shape_hash, 0, 12 ) ),
				'shape_hash'  => $shape_hash,
				'token_count' => $group['token_count'],
				'nodes'       => $nodes,
				'members'     => $members,
			);
		}
		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				$by_count = count( $right['members'] ) <=> count( $left['members'] );
				return 0 !== $by_count ? $by_count : strcmp( $left['id'], $right['id'] );
			}
		);

		return $candidates;
	}

	/**
	 * Count cross-domain edges touching a node.
	 *
	 * @since NEXT
	 * @param string $node_id Node ID.
	 * @return int
	 */
	private function node_cross_domain_count( string $node_id ): int {
		$count = 0;
		foreach ( $this->flat_edges() as $edge ) {
			if ( $edge['cross_domain'] && ( $edge['from'] === $node_id || $edge['to'] === $node_id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count strict boundary violations touching a node.
	 *
	 * @since NEXT
	 * @param string $node_id Node ID.
	 * @return int
	 */
	private function node_violation_count( string $node_id ): int {
		$count = 0;
		foreach ( $this->flat_edges() as $edge ) {
			if ( '' !== $edge['boundary_violation'] && ( $edge['from'] === $node_id || $edge['to'] === $node_id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Classify an edge against the intended dependency direction.
	 *
	 * @since NEXT
	 * @param array<string,mixed> $edge Flattened edge data.
	 * @return string Empty string when allowed, otherwise a stable reason code.
	 */
	private function boundary_reason( array $edge ): string {
		$from = $this->layer( $edge['from'] );
		$to   = $this->layer( $edge['to'] );

		if ( 'Infrastructure' === $from && in_array( $to, array( 'Domain', 'Application', 'Presentation', 'Core' ), true ) ) {
			return 'infrastructure_depends_on_upper_layer';
		}
		if ( 'Domain' === $from && in_array( $to, array( 'Presentation', 'Core' ), true ) ) {
			return 'domain_depends_on_presentation_or_orchestrator';
		}
		if ( 'Presentation' === $from && 'Core' === $to ) {
			return 'presentation_depends_on_orchestrator';
		}
		if ( 'Application' === $from && in_array( $to, array( 'Presentation', 'Core' ), true ) ) {
			return 'application_depends_on_presentation_or_orchestrator';
		}
		if ( 'SharedCompatibility' === $from && in_array( $to, array( 'Domain', 'Application', 'Presentation', 'Core' ), true ) ) {
			return 'shared_compatibility_depends_on_feature';
		}
		if ( 'ProtectedAdapter' === $from && in_array( $to, array( 'Domain', 'Application', 'Presentation', 'Core' ), true ) ) {
			return 'protected_adapter_depends_on_feature';
		}

		return '';
	}

	/**
	 * Assign an architectural layer to a node.
	 *
	 * @since NEXT
	 * @param string $node_id Node ID.
	 * @return string
	 */
	private function layer( string $node_id ): string {
		$node = $this->nodes[ $node_id ] ?? null;
		if ( null === $node ) {
			return 'Unknown';
		}
		$name = $node['name'];
		if ( in_array( $name, array( 'Wp_Version', 'Scheduler', 'Cache_Key', 'Settings_Store' ), true ) ) {
			return 'Infrastructure';
		}
		if ( 'Loader_Map' === $name || in_array( $name, array( 'Main', 'Hook_Registry' ), true ) ) {
			return 'Core';
		}
		if ( in_array( $name, array( 'Settings_Migrations', 'Sandbox_Preview', 'Cron' ), true ) ) {
			return 'Application';
		}
		if ( in_array( $name, array( 'Activate', 'Deactivate' ), true ) ) {
			return 'Lifecycle';
		}
		if ( 'Shared' === $node['domain'] && 'Util' === $name ) {
			return 'SharedCompatibility';
		}
		if ( in_array( $node['domain'], $this->feature_domains(), true ) ) {
			return 'Domain';
		}
		if ( 'Support' === $node['domain'] || 'Settings' === $node['domain'] ) {
			return 'Infrastructure';
		}
		if ( 'Admin' === $node['domain'] ) {
			return 'Presentation';
		}
		if ( 'Compatibility' === $node['domain'] ) {
			return 'Adapter';
		}
		if ( 'Minify' === $node['domain'] ) {
			return 'ProtectedAdapter';
		}
		if ( 'DropIn' === $node['domain'] ) {
			return 'InfrastructureAdapter';
		}
		if ( 'Bootstrap' === $node['domain'] ) {
			return 'Bootstrap';
		}
		if ( 'Lifecycle' === $node['domain'] ) {
			return 'Lifecycle';
		}

		return $node['domain'];
	}

	/**
	 * Return feature domain directory names.
	 *
	 * @since NEXT
	 * @return array<int,string>
	 */
	private function feature_domains(): array {
		return array( 'Assets', 'Cache', 'CSS', 'Database', 'Edge', 'Images', 'Insight', 'Integrations' );
	}

	/**
	 * Find a node by relative file path.
	 *
	 * @since NEXT
	 * @param string $file Relative file path.
	 * @return array<string,mixed>|null
	 */
	private function node_for_file( string $file ): ?array {
		foreach ( $this->nodes as $node ) {
			if ( $node['file'] === $file ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Classify inventory scope/protected status.
	 *
	 * @since NEXT
	 * @param string $file Relative path.
	 * @return string
	 */
	private function scope_for_file( string $file ): string {
		if ( str_starts_with( $file, 'includes/minify/' ) ) {
			return 'protected_vendor_adjacent';
		}
		if ( str_starts_with( $file, 'templates/' ) ) {
			return 'drop_in';
		}
		if ( 'uninstall.php' === $file ) {
			return 'lifecycle';
		}

		return 'runtime';
	}

	/**
	 * Extract a stable method-name verb/prefix signal.
	 *
	 * @since NEXT
	 * @param string $method Method name.
	 * @return string
	 */
	private function method_prefix( string $method ): string {
		$parts = preg_split( '/_+/', strtolower( $method ) );
		if ( ! is_array( $parts ) || empty( $parts[0] ) ) {
			return '';
		}
		$known = array( 'get', 'set', 'is', 'has', 'can', 'should', 'maybe', 'add', 'remove', 'update', 'delete', 'clear', 'reset', 'render', 'process', 'handle', 'build', 'create', 'init', 'register', 'enqueue', 'sanitize', 'validate', 'normalize', 'generate', 'load', 'save', 'write', 'read', 'fetch', 'purge', 'detect', 'check', 'run', 'do', 'apply', 'filter', 'format', 'parse', 'prepare', 'start', 'stop', 'schedule', 'unschedule', 'flush', 'discard', 'promote', 'rollback', 'convert', 'optimize', 'optimise' );
		if ( in_array( $parts[0], $known, true ) ) {
			return $parts[0];
		}

		return $parts[0];
	}

	/**
	 * Get a node's short class name.
	 *
	 * @since NEXT
	 * @param string $node_id Node ID.
	 * @return string
	 */
	private function short_name( string $node_id ): string {
		return $this->nodes[ $node_id ]['name'] ?? $node_id;
	}
}
