<?php
/**
 * Tokenizer-based source analyzer for the architecture inventory CLI.
 *
 * This file is development tooling. It is not loaded by WordPress and is not
 * part of the Composer classmap. The generator requires it explicitly.
 *
 * @package PerformanceOptimise\Architecture
 * @since   NEXT
 */

namespace PerformanceOptimise\Architecture;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI architecture analyzer reads local first-party PHP source outside WordPress.

use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Parse first-party PHP source into symbol, reference, and method metrics.
 *
 * The analyzer deliberately records executable syntax separately from comments.
 * Raw `ClassName::` regular expressions cannot distinguish those cases, nor can
 * they reliably see `new`, imports, traits, reflection strings, or loader
 * paths. This class uses `PhpToken::tokenize()` and keeps occurrence lines so
 * the architecture graph can be audited back to source.
 *
 * @since NEXT
 */
final class Source_Analyzer {

	/**
	 * Tokens that never represent a source reference by themselves.
	 *
	 * @since NEXT
	 * @var array<int,int>
	 */
	private const SKIP_TOKENS = array(
		T_WHITESPACE,
		T_COMMENT,
		T_DOC_COMMENT,
		T_OPEN_TAG,
		T_OPEN_TAG_WITH_ECHO,
		T_CLOSE_TAG,
	);

	/**
	 * Function-like constructs that tokenize as T_STRING but are not calls.
	 *
	 * @since NEXT
	 * @var array<string,bool>
	 */
	private const LANGUAGE_CONSTRUCTS = array(
		'array'        => true,
		'bool'         => true,
		'callable'     => true,
		'catch'        => true,
		'class'        => true,
		'clone'        => true,
		'const'        => true,
		'do'           => true,
		'echo'         => true,
		'empty'        => true,
		'eval'         => true,
		'exit'         => true,
		'float'        => true,
		'fn'           => true,
		'for'          => true,
		'foreach'      => true,
		'function'     => true,
		'global'       => true,
		'goto'         => true,
		'if'           => true,
		'int'          => true,
		'isset'        => true,
		'iterable'     => true,
		'mixed'        => true,
		'never'        => true,
		'new'          => true,
		'null'         => true,
		'object'       => true,
		'parent'       => true,
		'print'        => true,
		'private'      => true,
		'protected'    => true,
		'public'       => true,
		'readonly'     => true,
		'require'      => true,
		'require_once' => true,
		'return'       => true,
		'self'         => true,
		'static'       => true,
		'switch'       => true,
		'throw'        => true,
		'trait'        => true,
		'try'          => true,
		'unset'        => true,
		'use'          => true,
		'var'          => true,
		'void'         => true,
		'while'        => true,
		'yield'        => true,
	);

	/**
	 * Files that contain WordPress hooks registered through helper functions.
	 *
	 * @since NEXT
	 * @var array<string,bool>
	 */
	private const HOOK_FUNCTIONS = array(
		'add_action'                 => true,
		'add_filter'                 => true,
		'register_activation_hook'   => true,
		'register_deactivation_hook' => true,
	);

	/**
	 * Analyze a set of absolute PHP paths.
	 *
	 * @since NEXT
	 * @param string[] $absolute_files Absolute paths in deterministic order.
	 * @param string   $plugin_root    Absolute plugin root used for relative paths.
	 * @return array<string,array<string,mixed>> Resolved node records keyed by node ID.
	 * @throws RuntimeException When a source file cannot be read or tokenized.
	 */
	public function analyze( array $absolute_files, string $plugin_root ): array {
		$raw_nodes = array();
		$class_map = array();

		foreach ( $absolute_files as $absolute_file ) {
			foreach ( $this->analyze_file( (string) $absolute_file, $plugin_root ) as $node ) {
				$raw_nodes[ $node['id'] ] = $node;
				if ( null !== $node['fqcn'] ) {
					$class_map[ strtolower( $node['fqcn'] ) ] = $node['id'];
					$short_key                                = strtolower( $node['name'] );
					if ( ! isset( $class_map[ $short_key ] ) ) {
						$class_map[ $short_key ] = $node['id'];
					}
				}
			}
		}

		$delegating_methods = $this->delegating_methods_by_class( $raw_nodes );
		$resolved           = array();

		foreach ( $raw_nodes as $node_id => $node ) {
			$resolved[ $node_id ] = $this->resolve_node( $node, $class_map, $delegating_methods );
		}

		ksort( $resolved, SORT_STRING );

		return $resolved;
	}

	/**
	 * Analyze one PHP file into one class/trait node or one procedural node.
	 *
	 * @since NEXT
	 * @param string $absolute_file Absolute source path.
	 * @param string $plugin_root   Absolute plugin root.
	 * @return array<int,array<string,mixed>> Raw node records.
	 * @throws RuntimeException When the source cannot be read or tokenized.
	 */
	private function analyze_file( string $absolute_file, string $plugin_root ): array {
		$source = file_get_contents( $absolute_file );
		if ( false === $source ) {
			throw new RuntimeException( 'Cannot read architecture source.' );
		}

		try {
			$tokens = PhpToken::tokenize( $source, TOKEN_PARSE );
		} catch ( ParseError ) {
			throw new RuntimeException( 'Cannot tokenize architecture source.' );
		}
		$header = $this->parse_header( $tokens );
		$symbol = $this->find_symbol( $tokens );
		$rel    = ltrim( str_replace( '\\', '/', substr( $absolute_file, strlen( $plugin_root ) ) ), '/' );

		if ( null === $symbol ) {
			return array(
				$this->build_procedural_node( $rel, $source, $tokens, $header ),
			);
		}

		$class_data = $this->parse_symbol( $tokens, $symbol );
		$references = $this->parse_references( $tokens, $header, $class_data );
		$hooks      = $this->parse_hooks( $tokens );
		$node_id    = $class_data['fqcn'];

		return array(
			array(
				'id'                       => $node_id,
				'name'                     => $class_data['name'],
				'fqcn'                     => $class_data['fqcn'],
				'kind'                     => $class_data['kind'],
				'file'                     => $rel,
				'domain'                   => $this->domain_for_file( $rel ),
				'namespace'                => $header['namespace'],
				'lines'                    => $this->line_count( $source ),
				'methods'                  => $class_data['methods'],
				'property_count'           => $class_data['property_count'],
				'static_property_count'    => $class_data['static_property_count'],
				'public_property_count'    => $class_data['public_property_count'],
				'protected_property_count' => $class_data['protected_property_count'],
				'private_property_count'   => $class_data['private_property_count'],
				'extends'                  => $class_data['extends'],
				'implements'               => $class_data['implements'],
				'trait_usage'              => $class_data['trait_usage'],
				'imports'                  => $header['imports'],
				'raw_references'           => $references,
				'hooks'                    => $hooks,
				'documentation'            => $this->documentation_references( $tokens ),
				'top_level_functions'      => $this->top_level_functions( $tokens, $class_data['open_index'], $class_data['close_index'] ),
			),
		);
	}

	/**
	 * Parse namespace and top-level imports.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @return array{namespace:string,imports:array<int,array{name:string,alias:string,type:string}>}
	 */
	private function parse_header( array $tokens ): array {
		$namespace = '';
		$imports   = array();
		$count     = count( $tokens );
		$symbol    = $this->find_symbol( $tokens );
		$end_index = null === $symbol ? $count : $symbol['name_index'];

		for ( $index = 0; $index < $end_index; $index++ ) {
			$token = $tokens[ $index ];
			if ( T_NAMESPACE === $token->id ) {
				$namespace = $this->read_name_until( $tokens, $index + 1, array( ';', '{' ) );
				continue;
			}
			if ( T_USE !== $token->id ) {
				continue;
			}
			$end       = $this->find_next( $tokens, $index + 1, array( ';' ) );
			$use_type  = 'class';
			$use_start = $index + 1;
			$next      = $this->next_meaningful( $tokens, $index + 1 );
			if ( null !== $next && in_array( strtolower( $tokens[ $next ]->text ), array( 'function', 'const' ), true ) ) {
				$use_type  = strtolower( $tokens[ $next ]->text );
				$use_start = $next + 1;
			}
			$end = null === $end ? $count - 1 : $end;
			foreach ( $this->parse_import_list( $tokens, $use_start, $end ) as $import ) {
				$import['type'] = $use_type;
				$imports[]      = $import;
			}
			$index = $end;
		}

		return array(
			'namespace' => $namespace,
			'imports'   => $imports,
		);
	}

	/**
	 * Find the first named class, interface, or trait declaration.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @return array<string,mixed>|null Symbol metadata, or null for procedural code.
	 */
	private function find_symbol( array $tokens ): ?array {
		$count = count( $tokens );
		for ( $index = 0; $index < $count; $index++ ) {
			$token = $tokens[ $index ];
			if ( ! in_array( $token->id, array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
				continue;
			}
			$previous = $this->previous_meaningful( $tokens, $index );
			if ( null !== $previous && in_array( $tokens[ $previous ]->id, array( T_NEW, T_DOUBLE_COLON ), true ) ) {
				continue;
			}
			$name_index = $this->next_meaningful( $tokens, $index + 1 );
			if ( null === $name_index || T_STRING !== $tokens[ $name_index ]->id ) {
				continue;
			}
			$open_index = $this->find_next( $tokens, $name_index + 1, array( '{' ) );
			if ( null === $open_index ) {
				continue;
			}
			$close_index = $this->matching_delimiter( $tokens, $open_index );
			if ( null === $close_index ) {
				continue;
			}

			return array(
				'kind'        => $this->symbol_kind( $token->id ),
				'name'        => $tokens[ $name_index ]->text,
				'name_index'  => $name_index,
				'open_index'  => $open_index,
				'close_index' => $close_index,
			);
		}

		return null;
	}

	/**
	 * Parse symbol declaration, direct properties, methods, and trait usage.
	 *
	 * @since NEXT
	 * @param PhpToken[]          $tokens Tokenized source.
	 * @param array<string,mixed> $symbol Symbol metadata.
	 * @return array<string,mixed>
	 */
	private function parse_symbol( array $tokens, array $symbol ): array {
		$namespace                = $this->namespace_before( $tokens, $symbol['name_index'] );
		$extends                  = array();
		$implements               = array();
		$section                  = '';
		$index                    = $symbol['name_index'] + 1;
		$methods                  = array();
		$trait_usage              = array();
		$property_count           = 0;
		$static_property_count    = 0;
		$public_property_count    = 0;
		$protected_property_count = 0;
		$private_property_count   = 0;
		$visibility               = 'public';
		$is_static                = false;
		$depth                    = 0;

		while ( $index < $symbol['open_index'] ) {
			$token = $tokens[ $index ];
			if ( T_EXTENDS === $token->id ) {
				$section = 'extends';
				++$index;
				continue;
			}
			if ( T_IMPLEMENTS === $token->id ) {
				$section = 'implements';
				++$index;
				continue;
			}
			if ( ',' === $token->text ) {
				++$index;
				continue;
			}
			if ( $this->is_name_token( $token ) && '' !== $section ) {
				$name_start = $index;
				while ( $index + 1 < $symbol['open_index'] && $this->is_name_part( $tokens[ $index + 1 ] ) ) {
					++$index;
				}
				$name = $this->tokens_name( $tokens, $name_start, $index );
				if ( 'extends' === $section ) {
					$extends[] = $name;
				} else {
					$implements[] = $name;
				}
			}
			++$index;
		}

		$index     = $symbol['open_index'] + 1;
		$last      = $symbol['close_index'];
		$modifiers = array();

		while ( $index < $last ) {
			$token = $tokens[ $index ];
			if ( '{' === $token->text ) {
				++$depth;
				++$index;
				continue;
			}
			if ( '}' === $token->text ) {
				--$depth;
				++$index;
				continue;
			}
			if ( 0 === $depth && in_array( $token->id, array( T_PUBLIC, T_PROTECTED, T_PRIVATE ), true ) ) {
				$visibility = strtolower( $token->text );
				++$index;
				continue;
			}
			if ( 0 === $depth && T_STATIC === $token->id ) {
				$is_static = true;
				++$index;
				continue;
			}
			if ( 0 === $depth && in_array( $token->id, array( T_ABSTRACT, T_FINAL, T_READONLY ), true ) ) {
				$modifiers[] = strtolower( $token->text );
				++$index;
				continue;
			}
			if ( 0 === $depth && T_USE === $token->id ) {
				$end   = $this->find_token_at_depth( $tokens, $index + 1, array( '{', ';' ), 0 );
				$stop  = null === $end ? $last : $end;
				$parts = $this->parse_import_list( $tokens, $index + 1, $stop );
				foreach ( $parts as $part ) {
					$trait_usage[] = $part['name'];
				}
				$index = null === $end ? $last : $end + 1;
				continue;
			}
			if ( 0 === $depth && T_FUNCTION === $token->id ) {
				$method = $this->parse_method( $tokens, $index, $symbol['close_index'], $visibility, $is_static );
				if ( null !== $method ) {
					$methods[]  = $method['method'];
					$index      = $method['next_index'];
					$visibility = 'public';
					$is_static  = false;
					$modifiers  = array();
					continue;
				}
			}
			if ( 0 === $depth && T_VARIABLE === $token->id ) {
				++$property_count;
				if ( $is_static ) {
					++$static_property_count;
				}
				if ( 'private' === $visibility ) {
					++$private_property_count;
				} elseif ( 'protected' === $visibility ) {
					++$protected_property_count;
				} else {
					++$public_property_count;
				}
			}
			if ( 0 === $depth && ';' === $token->text ) {
				$visibility = 'public';
				$is_static  = false;
				$modifiers  = array();
			}
			++$index;
		}

		$fqcn = '' === $namespace ? $symbol['name'] : $namespace . '\\' . $symbol['name'];

		return array(
			'name'                     => $symbol['name'],
			'fqcn'                     => $fqcn,
			'kind'                     => $symbol['kind'],
			'open_index'               => $symbol['open_index'],
			'close_index'              => $symbol['close_index'],
			'methods'                  => $methods,
			'property_count'           => $property_count,
			'static_property_count'    => $static_property_count,
			'public_property_count'    => $public_property_count,
			'protected_property_count' => $protected_property_count,
			'private_property_count'   => $private_property_count,
			'extends'                  => $extends,
			'implements'               => $implements,
			'trait_usage'              => $trait_usage,
		);
	}

	/**
	 * Parse one named method and its closing line.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens      Tokenized source.
	 * @param int        $index       T_FUNCTION index.
	 * @param int        $class_close Closing class brace index.
	 * @param string     $visibility  Method visibility.
	 * @param bool       $is_static   Whether static modifier was seen.
	 * @return array{method:array<string,mixed>,next_index:int}|null
	 */
	private function parse_method( array $tokens, int $index, int $class_close, string $visibility, bool $is_static ): ?array {
		$name_index = $this->next_meaningful( $tokens, $index + 1 );
		if ( null === $name_index || T_STRING !== $tokens[ $name_index ]->id ) {
			return null;
		}
		$open_index = null;
		for ( $cursor = $name_index + 1; $cursor < $class_close; $cursor++ ) {
			if ( '{' === $tokens[ $cursor ]->text || ';' === $tokens[ $cursor ]->text ) {
				$open_index = $cursor;
				break;
			}
		}
		if ( null === $open_index ) {
			return null;
		}
		$close_index = $open_index;
		if ( '{' === $tokens[ $open_index ]->text ) {
			$close_index = $this->matching_delimiter( $tokens, $open_index );
		}
		if ( null === $close_index || $close_index > $class_close ) {
			return null;
		}
		$body_tokens = '{' === $tokens[ $open_index ]->text
			? array_slice( $tokens, $open_index + 1, max( 0, $close_index - $open_index - 1 ) )
			: array();
		$body_key    = $this->normalized_method_key( $body_tokens );
		$delegates   = $this->method_delegates( $body_tokens );

		return array(
			'method'     => array(
				'name'       => $tokens[ $name_index ]->text,
				'line'       => $tokens[ $name_index ]->line,
				'end_line'   => $tokens[ $close_index ]->line,
				'lines'      => $tokens[ $close_index ]->line - $tokens[ $name_index ]->line + 1,
				'visibility' => $visibility,
				'static'     => $is_static,
				'body_key'   => $body_key,
				'body_size'  => count( $body_key ),
				'delegates'  => $delegates,
			),
			'next_index' => $close_index + 1,
		);
	}

	/**
	 * Parse executable references and external dependency signals.
	 *
	 * @since NEXT
	 * @param PhpToken[]          $tokens Tokenized source.
	 * @param array<string,mixed> $header Parsed namespace/imports.
	 * @param array<string,mixed> $symbol Parsed symbol data.
	 * @return array<string,mixed>
	 */
	private function parse_references( array $tokens, array $header, array $symbol ): array {
		$references             = array(
			'static_calls'              => array(),
			'instance_calls'            => array(),
			'new_expressions'           => array(),
			'class_references'          => array(),
			'class_constant_references' => array(),
			'class_string_references'   => array(),
			'class_exists'              => array(),
			'method_exists'             => array(),
			'interface_exists'          => array(),
			'trait_exists'              => array(),
			'reflection'                => array(),
			'loader_references'         => array(),
			'file_references'           => array(),
			'external_classes'          => array(),
			'external_functions'        => array(),
			'wordpress_functions'       => array(),
			'filesystem_functions'      => array(),
			'network_functions'         => array(),
			'database_signals'          => array(),
			'dynamic_references'        => array(),
		);
		$count                  = count( $tokens );
		$class_ids              = array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM );
		$static_method_tokens   = array();
		$instance_method_tokens = array();
		$local_functions        = array();
		foreach ( $this->top_level_functions( $tokens, (int) $symbol['open_index'], (int) $symbol['close_index'] ) as $function ) {
			$local_functions[ strtolower( $function['name'] ) ] = true;
		}

		for ( $index = 0; $index < $count; $index++ ) {
			if ( isset( $static_method_tokens[ $index ] ) || isset( $instance_method_tokens[ $index ] ) ) {
				continue;
			}
			$token = $tokens[ $index ];
			if ( in_array( $token->id, self::SKIP_TOKENS, true ) ) {
				continue;
			}

			$next = $this->next_meaningful( $tokens, $index + 1 );
			if ( in_array( $token->id, $class_ids, true ) ) {
				$previous = $this->previous_meaningful( $tokens, $index );
				if ( null !== $previous && in_array( $tokens[ $previous ]->id, array( T_NEW, T_DOUBLE_COLON ), true ) ) {
					continue;
				}
			}

			if ( T_VARIABLE === $token->id && null !== $next && T_DOUBLE_COLON === $tokens[ $next ]->id ) {
				$after = $this->next_meaningful( $tokens, $next + 1 );
				if ( null !== $after && T_STRING === $tokens[ $after ]->id ) {
					$static_method_tokens[ $after ] = true;
				}
				$this->add_reference( $references['dynamic_references'], 'static::' . $token->text, $token->line );
				continue;
			}

			if ( $this->is_name_token( $token ) && null !== $next && T_DOUBLE_COLON === $tokens[ $next ]->id ) {
				$after = $this->next_meaningful( $tokens, $next + 1 );
				$raw   = $this->qualified_name_at( $tokens, $index );
				if ( null !== $after && ( T_CLASS === $tokens[ $after ]->id || 'class' === strtolower( $tokens[ $after ]->text ) ) ) {
					$before   = $this->previous_meaningful( $tokens, $index );
					$is_guard = null !== $before && T_STRING === $tokens[ $before ]->id
						&& in_array( strtolower( $tokens[ $before ]->text ), array( 'class_exists', 'method_exists', 'interface_exists', 'trait_exists' ), true );
					if ( ! $is_guard ) {
						$this->add_reference( $references['class_references'], $raw, $token->line );
					}
				} elseif ( null !== $after && T_STRING === $tokens[ $after ]->id ) {
					$after_method                   = $this->next_meaningful( $tokens, $after + 1 );
					$method                         = $tokens[ $after ]->text;
					$static_method_tokens[ $after ] = true;
					if ( null !== $after_method && '(' === $tokens[ $after_method ]->text ) {
						$this->add_reference( $references['static_calls'], $raw . '::' . $method, $token->line );
						if ( 'Loader_Map' === $this->short_name( $raw ) ) {
							$this->add_reference( $references['loader_references'], $raw . '::' . $method, $token->line );
						}
					} else {
						$this->add_reference( $references['class_constant_references'], $raw, $token->line );
					}
				} else {
					$this->add_reference( $references['dynamic_references'], 'static::' . $raw, $token->line );
				}
				continue;
			}

			if ( T_NEW === $token->id ) {
				if ( null !== $next && in_array( $tokens[ $next ]->id, $class_ids, true ) ) {
					$this->add_reference( $references['dynamic_references'], 'new anonymous class', $token->line );
				} elseif ( null !== $next && $this->is_name_token( $tokens[ $next ] ) ) {
					$raw = $this->qualified_name_at( $tokens, $next );
					$this->add_reference( $references['new_expressions'], $raw, $token->line );
					if ( 0 === strpos( $raw, 'Reflection' ) ) {
						$this->add_reference( $references['reflection'], 'new ' . $raw, $token->line );
					}
				} else {
					$this->add_reference( $references['dynamic_references'], 'new <dynamic>', $token->line );
				}
				continue;
			}

			if ( T_VARIABLE === $token->id && null !== $next && in_array( $tokens[ $next ]->id, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				$member_index = $this->next_meaningful( $tokens, $next + 1 );
				if ( null !== $member_index ) {
					$after_member = $this->next_meaningful( $tokens, $member_index + 1 );
					$member       = T_STRING === $tokens[ $member_index ]->id ? $tokens[ $member_index ]->text : '<dynamic>';
					$is_call      = null !== $after_member && '(' === $tokens[ $after_member ]->text;
					if ( $is_call ) {
						$instance_method_tokens[ $member_index ] = true;
						$this->add_reference( $references['instance_calls'], $token->text . '->' . $member, $token->line );
						$receiver = strtolower( ltrim( $token->text, '$' ) );
						if ( in_array( $receiver, array( 'wpdb', 'db' ), true ) ) {
							$this->add_reference( $references['database_signals'], $token->text . '->' . $member, $token->line );
						}
						if ( in_array( $receiver, array( 'curl', 'curl_multi', 'curl_share', 'http' ), true ) ) {
							$this->add_reference( $references['network_functions'], $token->text . '->' . $member, $token->line );
						}
						if ( in_array( $receiver, array( 'fs', 'filesystem', 'wp_filesystem', 'wppo_filesystem' ), true ) ) {
							$this->add_reference( $references['filesystem_functions'], $token->text . '->' . $member, $token->line );
						}
					}
				}
				continue;
			}

			if ( $this->is_name_token( $token ) && null !== $next && '(' === $tokens[ $next ]->text ) {
				$previous = $this->previous_meaningful( $tokens, $index );
				if ( null !== $previous && T_FUNCTION === $tokens[ $previous ]->id ) {
					continue;
				}
				$function_name = ltrim( $token->text, '\\' );
				$function      = strtolower( $function_name );
				$is_local      = isset( $local_functions[ $function ] );
				if ( ! $is_local && ! isset( self::LANGUAGE_CONSTRUCTS[ $function ] ) ) {
					$this->add_reference( $references['external_functions'], $token->text, $token->line );
					if ( $this->is_wordpress_function( $function ) ) {
						$this->add_reference( $references['wordpress_functions'], $token->text, $token->line );
					}
					if ( $this->is_filesystem_function( $function ) ) {
						$this->add_reference( $references['filesystem_functions'], $token->text, $token->line );
					}
					if ( $this->is_network_function( $function ) ) {
						$this->add_reference( $references['network_functions'], $token->text, $token->line );
					}
					if ( in_array( $function, array( 'dbdelta', 'maybe_serialize', 'maybe_unserialize' ), true ) ) {
						$this->add_reference( $references['database_signals'], $token->text, $token->line );
					}
				}
				if ( in_array( $function, array( 'class_exists', 'method_exists', 'interface_exists', 'trait_exists' ), true ) ) {
					$argument = $this->first_argument_reference( $tokens, $next );
					if ( null !== $argument ) {
						$references[ $function ][] = array(
							'name'     => $argument['name'],
							'method'   => $argument['method'],
							'absolute' => $argument['absolute'],
							'line'     => $token->line,
						);
					}
				}
				if ( 0 === strpos( $function, 'reflection' ) ) {
					$this->add_reference( $references['reflection'], $function, $token->line );
				}
				if ( 'spl_autoload_register' === $function ) {
					$this->add_reference( $references['loader_references'], $function, $token->line );
				}
				continue;
			}

			if ( in_array( $token->id, array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
				if ( null !== $next && T_CONSTANT_ENCAPSED_STRING === $tokens[ $next ]->id ) {
					$this->add_reference( $references['file_references'], $this->decode_string( $tokens[ $next ]->text ), $token->line );
				} else {
					$this->add_reference( $references['file_references'], '<dynamic>', $token->line );
				}
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $token->id ) {
				$value    = $this->decode_string( $token->text );
				$previous = $this->previous_meaningful( $tokens, $index );
				$is_guard = null !== $previous && T_STRING === $tokens[ $previous ]->id
					&& in_array( strtolower( $tokens[ $previous ]->text ), array( 'class_exists', 'method_exists', 'interface_exists', 'trait_exists' ), true );
				if ( ! $is_guard && $this->looks_like_class_name( $value ) ) {
					$this->add_reference( $references['class_string_references'], $value, $token->line );
				}
				if ( $this->looks_like_file_reference( $value ) ) {
					$this->add_reference( $references['file_references'], $value, $token->line );
				}
			}
		}

		unset( $header, $symbol );

		return $references;
	}

	/**
	 * Parse literal hooks from executable function calls.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @return array<int,array{name:string,line:int}>
	 */
	private function parse_hooks( array $tokens ): array {
		$hooks = array();
		$count = count( $tokens );
		for ( $index = 0; $index < $count; $index++ ) {
			$token = $tokens[ $index ];
			if ( T_STRING !== $token->id || ! isset( self::HOOK_FUNCTIONS[ strtolower( $token->text ) ] ) ) {
				continue;
			}
			$open = $this->next_meaningful( $tokens, $index + 1 );
			if ( null === $open || '(' !== $tokens[ $open ]->text ) {
				continue;
			}
			$argument = $this->first_argument_reference( $tokens, $open );
			if ( null !== $argument && '' !== $argument['name'] ) {
				$hooks[] = array(
					'name' => $argument['name'],
					'line' => $token->line,
				);
			}
		}

		return $hooks;
	}

	/**
	 * Build a procedural node for files without a named class/trait/interface.
	 *
	 * @since NEXT
	 * @param string              $rel    Relative file path.
	 * @param string              $source File contents.
	 * @param PhpToken[]          $tokens Tokenized source.
	 * @param array<string,mixed> $header Parsed header.
	 * @return array<string,mixed>
	 */
	private function build_procedural_node( string $rel, string $source, array $tokens, array $header ): array {
		$close_index = count( $tokens ) - 1;
		$references  = $this->parse_references(
			$tokens,
			$header,
			array(
				'open_index'  => -1,
				'close_index' => $close_index,
			)
		);

		return array(
			'id'                       => 'file:' . $rel,
			'name'                     => basename( $rel ),
			'fqcn'                     => null,
			'kind'                     => 'procedural',
			'file'                     => $rel,
			'domain'                   => $this->domain_for_file( $rel ),
			'namespace'                => $header['namespace'],
			'lines'                    => $this->line_count( $source ),
			'methods'                  => array(),
			'property_count'           => 0,
			'static_property_count'    => 0,
			'public_property_count'    => 0,
			'protected_property_count' => 0,
			'private_property_count'   => 0,
			'extends'                  => array(),
			'implements'               => array(),
			'trait_usage'              => array(),
			'imports'                  => $header['imports'],
			'raw_references'           => $references,
			'hooks'                    => $this->parse_hooks( $tokens ),
			'documentation'            => $this->documentation_references( $tokens ),
			'top_level_functions'      => $this->top_level_functions( $tokens, -1, $close_index ),
		);
	}

	/**
	 * Resolve raw references to first-party nodes and executable dependencies.
	 *
	 * @since NEXT
	 * @param array<string,mixed>              $node                Raw node.
	 * @param array<string,string>             $class_map          Lowercase class lookup.
	 * @param array<string,array<string,bool>> $delegating_methods Delegating method lookup.
	 * @return array<string,mixed>
	 */
	private function resolve_node( array $node, array $class_map, array $delegating_methods ): array {
		$import_aliases = array();
		$imports        = array();
		foreach ( $node['imports'] as $import ) {
			$name      = $this->resolve_import_name( $import['name'] );
			$alias     = '' !== $import['alias'] ? $import['alias'] : $this->short_name( $name );
			$imports[] = $import['type'] . ':' . $name . ( '' !== $import['alias'] ? ' as ' . $import['alias'] : '' );
			if ( 'class' === $import['type'] ) {
				$import_aliases[ strtolower( $alias ) ] = $name;
			}
		}
		$imports = array_values( array_unique( $imports ) );
		sort( $imports, SORT_STRING );

		$raw                 = $node['raw_references'];
		$static_calls        = array();
		$instance_calls      = array();
		$new_expressions     = array();
		$class_refs          = array();
		$class_constant_refs = array();
		$class_string_refs   = array();
		$class_exists        = array();
		$method_exists       = array();
		$interface_exists    = array();
		$trait_exists        = array();
		$reflection          = array();
		$loader_refs         = array();
		$file_refs           = array();
		$external_classes    = array();
		$plugin_dependencies = array();
		$extends             = array();
		$implements          = array();
		$trait_usage         = array();

		foreach ( array(
			'extends'     => 'extends',
			'implements'  => 'implements',
			'trait_usage' => 'trait_usage',
		) as $source_key => $dependency_kind ) {
			foreach ( $node[ $source_key ] as $reference_name ) {
				$resolved = $this->resolve_name( (string) $reference_name, $node['namespace'], $import_aliases );
				$this->add_reference( ${$source_key}, $resolved, 0 );
				$target = $this->plugin_node( $resolved, $class_map );
				if ( null === $target ) {
					$this->add_reference( $external_classes, $resolved, 0 );
					continue;
				}
				$this->add_plugin_dependency( $plugin_dependencies, $node['id'], $target, 'runtime', $dependency_kind, 0 );
			}
		}

		$static_records         = $this->reference_records( $raw['static_calls'] );
		$instance_records       = $this->reference_records( $raw['instance_calls'] );
		$new_records            = $this->reference_records( $raw['new_expressions'] );
		$class_records          = $this->reference_records( $raw['class_references'] );
		$class_constant_records = $this->reference_records( $raw['class_constant_references'] );
		$class_string_records   = $this->reference_records( $raw['class_string_references'] );
		$reflection_records     = $this->reference_records( $raw['reflection'] );
		$loader_records         = $this->reference_records( $raw['loader_references'] );
		$file_records           = $this->reference_records( $raw['file_references'] );

		foreach ( $static_records as $reference ) {
			list( $class_name, $method ) = array_pad( explode( '::', $reference['name'], 2 ), 2, '' );
			$resolved                    = $this->resolve_name( $class_name, $node['namespace'], $import_aliases );
			$this->add_reference( $static_calls, $resolved . '::' . $method, $reference['line'] );
			if ( $this->is_self_reference( $resolved ) ) {
				continue;
			}
			$target = $this->plugin_node( $resolved, $class_map );
			if ( null === $target ) {
				$this->add_reference( $external_classes, $resolved, $reference['line'] );
				continue;
			}
			$is_loader = 'Loader_Map' === $this->short_name( $resolved );
			$is_proxy  = $this->is_delegating_method( $target, $method, $delegating_methods );
			$this->add_plugin_dependency(
				$plugin_dependencies,
				$node['id'],
				$target,
				$is_loader ? 'loader' : ( $is_proxy ? 'compatibility' : 'runtime' ),
				'static_call',
				$reference['line']
			);
		}

		foreach ( $instance_records as $reference ) {
			$this->add_reference( $instance_calls, $reference['name'], $reference['line'] );
		}

		foreach ( $new_records as $reference ) {
			$resolved = $this->resolve_name( $reference['name'], $node['namespace'], $import_aliases );
			$this->add_reference( $new_expressions, $resolved, $reference['line'] );
			if ( $this->is_self_reference( $resolved ) ) {
				continue;
			}
			$target = $this->plugin_node( $resolved, $class_map );
			if ( null === $target ) {
				$this->add_reference( $external_classes, $resolved, $reference['line'] );
				continue;
			}
			$this->add_plugin_dependency( $plugin_dependencies, $node['id'], $target, 'runtime', 'new_expression', $reference['line'] );
		}

		foreach ( $class_records as $reference ) {
			$resolved = $this->resolve_name( $reference['name'], $node['namespace'], $import_aliases );
			$this->add_reference( $class_refs, $resolved, $reference['line'] );
			if ( $this->is_self_reference( $resolved ) ) {
				continue;
			}
			$target = $this->plugin_node( $resolved, $class_map );
			if ( null === $target ) {
				$this->add_reference( $external_classes, $resolved, $reference['line'] );
				continue;
			}
			$this->add_plugin_dependency( $plugin_dependencies, $node['id'], $target, 'runtime', 'class_reference', $reference['line'] );
		}

		foreach ( $class_constant_records as $reference ) {
			$resolved = $this->resolve_name( $reference['name'], $node['namespace'], $import_aliases );
			$this->add_reference( $class_constant_refs, $resolved, $reference['line'] );
			if ( $this->is_self_reference( $resolved ) ) {
				continue;
			}
			$target = $this->plugin_node( $resolved, $class_map );
			if ( null === $target ) {
				$this->add_reference( $external_classes, $resolved, $reference['line'] );
				continue;
			}
			$this->add_plugin_dependency( $plugin_dependencies, $node['id'], $target, 'runtime', 'class_constant_reference', $reference['line'] );
		}

		foreach ( $class_string_records as $reference ) {
			$resolved = $this->resolve_string_name( $reference['name'] );
			$this->add_reference( $class_string_refs, $resolved, $reference['line'] );
			if ( $this->is_self_reference( $resolved ) ) {
				continue;
			}
			$target = $this->plugin_node( $resolved, $class_map );
			if ( null === $target ) {
				$this->add_reference( $external_classes, $resolved, $reference['line'] );
				continue;
			}
			$this->add_plugin_dependency( $plugin_dependencies, $node['id'], $target, 'runtime', 'class_string_reference', $reference['line'] );
		}

		foreach ( array( 'class_exists', 'method_exists', 'interface_exists', 'trait_exists' ) as $kind ) {
			foreach ( $raw[ $kind ] as $reference ) {
				$resolved = ! empty( $reference['absolute'] )
					? $this->resolve_string_name( (string) $reference['name'] )
					: $this->resolve_name( (string) $reference['name'], $node['namespace'], $import_aliases );
				$label    = $resolved;
				if ( '' !== (string) $reference['method'] ) {
					$label .= '::' . $reference['method'];
				}
				$this->add_reference( ${$kind}, $label, $reference['line'] );
				if ( $this->is_self_reference( $resolved ) ) {
					continue;
				}
				$target = $this->plugin_node( $resolved, $class_map );
				if ( null === $target ) {
					$this->add_reference( $external_classes, $resolved, $reference['line'] );
					continue;
				}
				$classification = ( 'Bootstrap' === $node['domain'] || 'Loader_Map' === $this->short_name( $resolved ) )
					? 'loader'
					: 'compatibility';
				$this->add_plugin_dependency( $plugin_dependencies, $node['id'], $target, $classification, $kind, $reference['line'] );
			}
		}

		foreach ( $reflection_records as $reference ) {
			$this->add_reference( $reflection, $reference['name'], $reference['line'] );
		}
		foreach ( $loader_records as $reference ) {
			$this->add_reference( $loader_refs, $reference['name'], $reference['line'] );
		}
		foreach ( $file_records as $reference ) {
			$this->add_reference( $file_refs, $reference['name'], $reference['line'] );
		}

		$wordpress_functions  = $raw['wordpress_functions'];
		$filesystem_functions = $raw['filesystem_functions'];
		$network_functions    = $raw['network_functions'];
		$database_signals     = $raw['database_signals'];
		$external_functions   = $raw['external_functions'];
		unset( $external_functions['ReflectionClass'], $external_functions['ReflectionMethod'], $external_functions['ReflectionProperty'] );

		$runtime_dependencies       = array();
		$compatibility_dependencies = array();
		$loader_dependencies        = array();
		foreach ( $plugin_dependencies as $target => $dependency ) {
			foreach ( $dependency['classifications'] as $classification ) {
				if ( 'runtime' === $classification ) {
					$runtime_dependencies[] = $target;
				} elseif ( 'compatibility' === $classification ) {
					$compatibility_dependencies[] = $target;
				} elseif ( 'loader' === $classification ) {
					$loader_dependencies[] = $target;
				}
			}
		}
		$runtime_dependencies       = array_values( array_unique( $runtime_dependencies ) );
		$compatibility_dependencies = array_values( array_unique( $compatibility_dependencies ) );
		$loader_dependencies        = array_values( array_unique( $loader_dependencies ) );
		sort( $runtime_dependencies, SORT_STRING );
		sort( $compatibility_dependencies, SORT_STRING );
		sort( $loader_dependencies, SORT_STRING );

		$documentation = array();
		foreach ( $node['documentation'] as $reference ) {
			$resolved        = $this->resolve_name( $reference['name'], $node['namespace'], $import_aliases );
			$documentation[] = array(
				'name' => $resolved,
				'line' => $reference['line'],
			);
		}

		$method_details = array();
		$delegating     = array();
		foreach ( $node['methods'] as $method ) {
			$method_details[] = array(
				'name'       => $method['name'],
				'line'       => $method['line'],
				'end_line'   => $method['end_line'],
				'lines'      => $method['lines'],
				'visibility' => $method['visibility'],
				'static'     => $method['static'],
				'body_size'  => $method['body_size'],
				'body_key'   => $method['body_key'],
				'delegates'  => $method['delegates'],
			);
			if ( $method['delegates'] ) {
				$delegating[] = $method['name'];
			}
		}

		return array(
			'id'                         => $node['id'],
			'name'                       => $node['name'],
			'fqcn'                       => $node['fqcn'],
			'kind'                       => $node['kind'],
			'file'                       => $node['file'],
			'domain'                     => $node['domain'],
			'namespace'                  => $node['namespace'],
			'lines'                      => $node['lines'],
			'method_details'             => $method_details,
			'top_level_functions'        => $node['top_level_functions'],
			'property_count'             => $node['property_count'],
			'static_property_count'      => $node['static_property_count'],
			'public_property_count'      => $node['public_property_count'],
			'protected_property_count'   => $node['protected_property_count'],
			'private_property_count'     => $node['private_property_count'],
			'extends'                    => $extends,
			'implements'                 => $implements,
			'trait_usage'                => $trait_usage,
			'imports'                    => $imports,
			'references'                 => array(
				'static_calls'              => $static_calls,
				'instance_calls'            => $instance_calls,
				'new_expressions'           => $new_expressions,
				'class_references'          => $class_refs,
				'class_constant_references' => $class_constant_refs,
				'class_string_references'   => $class_string_refs,
				'class_exists'              => $class_exists,
				'method_exists'             => $method_exists,
				'interface_exists'          => $interface_exists,
				'trait_exists'              => $trait_exists,
				'reflection'                => $reflection,
				'loader_references'         => $loader_refs,
				'file_references'           => $file_refs,
				'external_classes'          => $external_classes,
				'external_functions'        => $external_functions,
				'wordpress_functions'       => $wordpress_functions,
				'filesystem_dependencies'   => $filesystem_functions,
				'network_dependencies'      => $network_functions,
				'database_dependencies'     => $database_signals,
				'dynamic_references'        => $raw['dynamic_references'],
			),
			'plugin_dependencies'        => $plugin_dependencies,
			'runtime_dependencies'       => $runtime_dependencies,
			'compatibility_dependencies' => $compatibility_dependencies,
			'loader_dependencies'        => $loader_dependencies,
			'documentation_references'   => $documentation,
			'delegating_methods'         => $delegating,
			'hooks'                      => $this->unique_hooks( $node['hooks'] ),
		);
	}

	/**
	 * Build method-delegation lookup for facade classification.
	 *
	 * @since NEXT
	 * @param array<string,array<string,mixed>> $nodes Raw nodes.
	 * @return array<string,array<string,bool>>
	 */
	private function delegating_methods_by_class( array $nodes ): array {
		$lookup = array();
		foreach ( $nodes as $node_id => $node ) {
			foreach ( $node['methods'] as $method ) {
				if ( $method['delegates'] ) {
					$lookup[ $node_id ][ $method['name'] ] = true;
				}
			}
		}

		return $lookup;
	}

	/**
	 * Check whether a target method is a thin delegation candidate.
	 *
	 * @since NEXT
	 * @param string                           $target_node_id Target node ID.
	 * @param string                           $method_name    Static method name.
	 * @param array<string,array<string,bool>> $lookup         Delegation lookup.
	 * @return bool
	 */
	private function is_delegating_method( string $target_node_id, string $method_name, array $lookup ): bool {
		return isset( $lookup[ $target_node_id ][ $method_name ] );
	}

	/**
	 * Flatten a name-to-lines reference map into occurrence records.
	 *
	 * @since NEXT
	 * @param array<string,array<int,int>> $map Reference map.
	 * @return array<int,array{name:string,line:int}>
	 */
	private function reference_records( array $map ): array {
		$records = array();
		foreach ( $map as $name => $lines ) {
			foreach ( $lines as $line ) {
				$records[] = array(
					'name' => (string) $name,
					'line' => (int) $line,
				);
			}
		}

		return $records;
	}

	/**
	 * Add or merge a source reference occurrence map.
	 *
	 * @since NEXT
	 * @param array<string,array<int,int>> $map   Reference map.
	 * @param string                       $name  Reference name.
	 * @param int                          $line  Source line.
	 * @return void
	 */
	private function add_reference( array &$map, string $name, int $line ): void {
		if ( ! isset( $map[ $name ] ) ) {
			$map[ $name ] = array();
		}
		if ( ! in_array( $line, $map[ $name ], true ) ) {
			$map[ $name ][] = $line;
		}
		ksort( $map[ $name ], SORT_NUMERIC );
	}

	/**
	 * Add or merge a first-party dependency occurrence.
	 *
	 * @since NEXT
	 * @param array<string,array<string,mixed>> $dependencies   Dependency map.
	 * @param string                            $from           Source node ID.
	 * @param string                            $to             Target node ID.
	 * @param string                            $classification Edge classification.
	 * @param string                            $kind           Reference kind.
	 * @param int                               $line           Source line.
	 * @return void
	 */
	private function add_plugin_dependency( array &$dependencies, string $from, string $to, string $classification, string $kind, int $line ): void {
		if ( $from === $to ) {
			return;
		}
		if ( ! isset( $dependencies[ $to ] ) ) {
			$dependencies[ $to ] = array(
				'classifications' => array(),
				'kinds'           => array(),
				'lines'           => array(),
			);
		}
		if ( ! in_array( $classification, $dependencies[ $to ]['classifications'], true ) ) {
			$dependencies[ $to ]['classifications'][] = $classification;
		}
		if ( ! in_array( $kind, $dependencies[ $to ]['kinds'], true ) ) {
			$dependencies[ $to ]['kinds'][] = $kind;
		}
		if ( ! in_array( $line, $dependencies[ $to ]['lines'], true ) ) {
			$dependencies[ $to ]['lines'][] = $line;
		}
		sort( $dependencies[ $to ]['classifications'], SORT_STRING );
		sort( $dependencies[ $to ]['kinds'], SORT_STRING );
		sort( $dependencies[ $to ]['lines'], SORT_NUMERIC );
	}

	/**
	 * Resolve an import list containing comma-separated names and aliases.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $start  Start index.
	 * @param int        $end    End index (inclusive).
	 * @return array<int,array{name:string,alias:string,type:string}>
	 */
	private function parse_import_list( array $tokens, int $start, int $end ): array {
		$imports = array();
		$name    = '';
		$alias   = '';
		$mode    = 'name';
		$prefix  = '';
		for ( $index = $start; $index <= $end; $index++ ) {
			$token = $tokens[ $index ];
			if ( '{' === $token->text ) {
				$prefix = rtrim( $name, '\\' ) . '\\';
				$name   = '';
				$alias  = '';
				$mode   = 'name';
				continue;
			}
			if ( '}' === $token->text ) {
				if ( '' !== $name ) {
					$imports[] = array(
						'name'  => $prefix . $name,
						'alias' => $alias,
						'type'  => 'class',
					);
				}
				$name   = '';
				$alias  = '';
				$mode   = 'name';
				$prefix = '';
				continue;
			}
			if ( ',' === $token->text ) {
				if ( '' !== $name ) {
					$imports[] = array(
						'name'  => $prefix . $name,
						'alias' => $alias,
						'type'  => 'class',
					);
				}
				$name  = '';
				$alias = '';
				$mode  = 'name';
				continue;
			}
			if ( T_AS === $token->id ) {
				$mode = 'alias';
				continue;
			}
			if ( $this->is_name_part( $token ) || '\\' === $token->text ) {
				if ( 'alias' === $mode ) {
					$alias .= $token->text;
				} else {
					$name .= $token->text;
				}
			}
		}
		if ( '' !== $name ) {
			$imports[] = array(
				'name'  => $prefix . $name,
				'alias' => $alias,
				'type'  => 'class',
			);
		}

		return $imports;
	}

	/**
	 * Read a namespace or qualified name until a terminator.
	 *
	 * @since NEXT
	 * @param PhpToken[]    $tokens     Tokenized source.
	 * @param int           $start      Start index.
	 * @param array<string> $terminators Stop token texts.
	 * @return string
	 */
	private function read_name_until( array $tokens, int $start, array $terminators ): string {
		$name  = '';
		$count = count( $tokens );
		for ( $index = $start; $index < $count; $index++ ) {
			$text = $tokens[ $index ]->text;
			if ( in_array( $text, $terminators, true ) ) {
				break;
			}
			if ( $this->is_name_part( $tokens[ $index ] ) || '\\' === $text ) {
				$name .= $text;
			}
		}

		return trim( $name, '\\' );
	}

	/**
	 * Extract a name and optional method from a function's first argument.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $open   Opening parenthesis index.
	 * @return array{name:string,method:string,absolute:bool}|null
	 */
	private function first_argument_reference( array $tokens, int $open ): ?array {
		$count    = count( $tokens );
		$depth    = 1;
		$raw_name = '';
		$method   = '';
		$absolute = false;
		$position = 'name';
		for ( $index = $open + 1; $index < $count; $index++ ) {
			$token = $tokens[ $index ];
			if ( in_array( $token->text, array( '(', '[', '{' ), true ) ) {
				++$depth;
			}
			if ( in_array( $token->text, array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			}
			if ( 1 !== $depth ) {
				continue;
			}
			if ( ',' === $token->text && 'name' === $position ) {
				$position = 'method';
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $token->id ) {
				if ( 'name' === $position ) {
					$raw_name = $this->decode_string( $token->text );
					$absolute = true;
				} elseif ( 'method' === $position && '' === $method ) {
					$method = $this->decode_string( $token->text );
				}
				continue;
			}
			if ( $this->is_name_token( $token ) ) {
				$next = $this->next_meaningful( $tokens, $index + 1 );
				if ( null !== $next && T_DOUBLE_COLON === $tokens[ $next ]->id ) {
					$raw_name = $this->qualified_name_at( $tokens, $index );
					$after    = $this->next_meaningful( $tokens, $next + 1 );
					if ( null !== $after && ( T_CLASS === $tokens[ $after ]->id || 'class' === strtolower( $tokens[ $after ]->text ) ) ) {
						continue;
					}
					continue;
				}
				if ( 'name' === $position && '' === $raw_name ) {
					$raw_name = $token->text;
				}
			}
		}

		if ( '' === $raw_name ) {
			return null;
		}

		return array(
			'name'     => $raw_name,
			'method'   => $method,
			'absolute' => $absolute,
		);
	}

	/**
	 * Capture comments as documentation-only references.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @return array<int,array{name:string,line:int}>
	 */
	private function documentation_references( array $tokens ): array {
		$references = array();
		foreach ( $tokens as $token ) {
			if ( T_DOC_COMMENT !== $token->id && T_COMMENT !== $token->id ) {
				continue;
			}
			if ( preg_match_all( '/(?:\\\\?PerformanceOptimise\\\\+Inc\\\\+[A-Za-z_][A-Za-z0-9_\\\\]*)(?:::([A-Za-z_][A-Za-z0-9_]*))?/', $token->text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$name  = $match[0];
					$split = strpos( $name, '::' );
					if ( false !== $split ) {
						$name = substr( $name, 0, $split );
					}
					$references[] = array(
						'name' => $name,
						'line' => $token->line,
					);
				}
			}
		}

		return $references;
	}

	/**
	 * Capture named functions declared at file scope.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens     Tokenized source.
	 * @param int        $class_open Class opening index, or -1.
	 * @param int        $class_close Class closing index, or EOF.
	 * @return array<int,array{name:string,line:int}>
	 */
	private function top_level_functions( array $tokens, int $class_open, int $class_close ): array {
		$functions = array();
		$count     = count( $tokens );
		for ( $index = 0; $index < $count; $index++ ) {
			if ( $class_open >= 0 && $index > $class_open && $index < $class_close ) {
				continue;
			}
			$token = $tokens[ $index ];
			if ( T_FUNCTION !== $token->id ) {
				continue;
			}
			$name_index = $this->next_meaningful( $tokens, $index + 1 );
			if ( null === $name_index || T_STRING !== $tokens[ $name_index ]->id ) {
				continue;
			}
			$functions[] = array(
				'name' => $tokens[ $name_index ]->text,
				'line' => $tokens[ $name_index ]->line,
			);
			$open_index  = $this->find_next( $tokens, $name_index + 1, array( '{' ) );
			if ( null !== $open_index ) {
				$close_index = $this->matching_delimiter( $tokens, $open_index );
				if ( null !== $close_index ) {
					$index = $close_index;
				}
			}
		}

		return $functions;
	}

	/**
	 * Normalize a method body for exact-shape duplicate candidate detection.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Method body tokens.
	 * @return string[]
	 */
	private function normalized_method_key( array $tokens ): array {
		$key = array();
		foreach ( $tokens as $token ) {
			if ( in_array( $token->id, self::SKIP_TOKENS, true ) ) {
				continue;
			}
			if ( T_VARIABLE === $token->id ) {
				$key[] = '<variable>';
				continue;
			}
			if ( in_array( $token->id, array( T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
				$key[] = '<literal>';
				continue;
			}
			$key[] = strtolower( $token->text );
		}

		return $key;
	}

	/**
	 * Detect a thin method that only delegates to another object/class.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Method body tokens.
	 * @return bool
	 */
	private function method_delegates( array $tokens ): bool {
		$meaningful = array();
		foreach ( $tokens as $token ) {
			if ( ! in_array( $token->id, self::SKIP_TOKENS, true ) ) {
				$meaningful[] = $token;
			}
		}
		if ( count( $meaningful ) > 14 ) {
			return false;
		}
		$has_delegate = false;
		$has_branch   = false;
		$count        = count( $meaningful );
		for ( $index = 0; $index < $count; $index++ ) {
			$token = $meaningful[ $index ];
			if ( in_array( $token->id, array( T_IF, T_ELSEIF, T_ELSE, T_SWITCH, T_FOR, T_FOREACH, T_WHILE, T_TRY, T_CATCH, T_MATCH ), true ) ) {
				$has_branch = true;
			}
			if ( $this->is_name_token( $token ) ) {
				$next = $this->next_meaningful( $meaningful, $index + 1 );
				if ( null !== $next && T_DOUBLE_COLON === $meaningful[ $next ]->text ) {
					$has_delegate = true;
				}
			}
			if ( T_NEW === $token->id ) {
				$has_delegate = true;
			}
		}

		return $has_delegate && ! $has_branch;
	}

	/**
	 * Resolve a class token sequence to a qualified name.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $index  Name token index.
	 * @return string
	 */
	private function qualified_name_at( array $tokens, int $index ): string {
		$name  = '';
		$count = count( $tokens );
		for ( $cursor = $index; $cursor < $count; $cursor++ ) {
			$token = $tokens[ $cursor ];
			if ( $cursor === $index ? $this->is_name_token( $token ) : $this->is_name_part( $token ) ) {
				$name .= $token->text;
				if ( $cursor + 1 >= $count || ( ! $this->is_name_part( $tokens[ $cursor + 1 ] ) && '\\' !== $tokens[ $cursor + 1 ]->text ) ) {
					break;
				}
			}
		}

		return $name;
	}

	/**
	 * Resolve a PHP import name without adding the current namespace.
	 *
	 * @since NEXT
	 * @param string $raw Import name from a `use` declaration.
	 * @return string
	 */
	private function resolve_import_name( string $raw ): string {
		$raw = trim( $raw );
		if ( in_array( strtolower( $raw ), array( 'self', 'static', 'parent' ), true ) ) {
			return strtolower( $raw );
		}

		return ltrim( $raw, '\\' );
	}

	/**
	 * Resolve a class string as an exact class name.
	 *
	 * PHP class strings passed to class_exists/method_exists and callback
	 * arrays are not lexical namespace tokens. A value such as
	 * `PerformanceOptimise\\Inc\\Util` or `WP_CLI\\Utils` must not receive the
	 * current namespace prefix.
	 *
	 * @since NEXT
	 * @param string $raw Exact class string.
	 * @return string
	 */
	private function resolve_string_name( string $raw ): string {
		$raw = trim( $raw );
		if ( in_array( strtolower( $raw ), array( 'self', 'static', 'parent' ), true ) ) {
			return strtolower( $raw );
		}

		return ltrim( $raw, '\\' );
	}

	/**
	 * Resolve a raw PHP name with namespace/import semantics.
	 *
	 * @since NEXT
	 * @param string               $raw             Raw name.
	 * @param string               $namespace_name Current namespace.
	 * @param array<string,string> $import_aliases Lowercase alias lookup.
	 * @return string
	 */
	private function resolve_name( string $raw, string $namespace_name, array $import_aliases ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( in_array( strtolower( $raw ), array( 'self', 'static', 'parent' ), true ) ) {
			return strtolower( $raw );
		}
		$leading = str_starts_with( $raw, '\\' );
		$raw     = ltrim( $raw, '\\' );
		if ( str_starts_with( $raw, $namespace_name . '\\' ) || str_starts_with( $raw, 'PerformanceOptimise\\' ) ) {
			return $raw;
		}
		$parts = explode( '\\', $raw );
		$first = strtolower( $parts[0] );
		if ( isset( $import_aliases[ $first ] ) ) {
			$parts[0] = ltrim( $import_aliases[ $first ], '\\' );
			$raw      = implode( '\\', $parts );
		} elseif ( ! $leading && '' !== $namespace_name && false === strpos( $raw, '\\' ) ) {
			$raw = $namespace_name . '\\' . $raw;
		} elseif ( ! $leading && '' !== $namespace_name ) {
			$raw = $namespace_name . '\\' . $raw;
		}

		return $leading ? '\\' . $raw : $raw;
	}

	/**
	 * Resolve a plugin class to its graph node ID.
	 *
	 * @since NEXT
	 * @param string               $resolved Resolved class name.
	 * @param array<string,string> $class_map Lowercase class lookup.
	 * @return string|null
	 */
	private function plugin_node( string $resolved, array $class_map ): ?string {
		$key = strtolower( ltrim( $resolved, '\\' ) );

		return $class_map[ $key ] ?? null;
	}

	/**
	 * Whether a resolved name is a class-scope reference.
	 *
	 * @since NEXT
	 * @param string $name Resolved class name.
	 * @return bool
	 */
	private function is_self_reference( string $name ): bool {
		return in_array( strtolower( ltrim( $name, '\\' ) ), array( 'self', 'static', 'parent' ), true );
	}

	/**
	 * Get the final segment of a class name.
	 *
	 * @since NEXT
	 * @param string $name Class name.
	 * @return string
	 */
	private function short_name( string $name ): string {
		$name = ltrim( $name, '\\' );
		$pos  = strrpos( $name, '\\' );

		return false === $pos ? $name : substr( $name, $pos + 1 );
	}

	/**
	 * Find the next index containing one of the supplied token texts.
	 *
	 * @since NEXT
	 * @param PhpToken[]    $tokens Tokenized source.
	 * @param int           $start  Start index.
	 * @param array<string> $texts  Token texts.
	 * @return int|null
	 */
	private function find_next( array $tokens, int $start, array $texts ): ?int {
		$count = count( $tokens );
		for ( $index = $start; $index < $count; $index++ ) {
			if ( in_array( $tokens[ $index ]->text, $texts, true ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Find a token at a relative curly depth.
	 *
	 * @since NEXT
	 * @param PhpToken[]    $tokens Tokenized source.
	 * @param int           $start  Start index.
	 * @param array<string> $texts        Stop token texts.
	 * @param int           $target_depth Relative curly depth to match.
	 * @return int|null
	 */
	private function find_token_at_depth( array $tokens, int $start, array $texts, int $target_depth ): ?int {
		$count = count( $tokens );
		$depth = $target_depth;
		for ( $index = $start; $index < $count; $index++ ) {
			$text = $tokens[ $index ]->text;
			if ( '{' === $text ) {
				++$depth;
				continue;
			}
			if ( '}' === $text ) {
				--$depth;
				continue;
			}
			if ( $depth === $target_depth && in_array( $text, $texts, true ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Find the matching closing delimiter for an opening token.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $open   Opening delimiter index.
	 * @return int|null
	 */
	private function matching_delimiter( array $tokens, int $open ): ?int {
		$pairs  = array(
			'{' => '}',
			'(' => ')',
			'[' => ']',
		);
		$opench = $tokens[ $open ]->text;
		if ( ! isset( $pairs[ $opench ] ) ) {
			return null;
		}
		$close = $pairs[ $opench ];
		$depth = 0;
		$count = count( $tokens );
		for ( $index = $open; $index < $count; $index++ ) {
			$text = $tokens[ $index ]->text;
			if ( $opench === $text ) {
				++$depth;
			} elseif ( $close === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index;
				}
			}
		}

		return null;
	}

	/**
	 * Find the next meaningful token index.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $start  Start index.
	 * @return int|null
	 */
	private function next_meaningful( array $tokens, int $start ): ?int {
		$count = count( $tokens );
		for ( $index = $start; $index < $count; $index++ ) {
			if ( ! in_array( $tokens[ $index ]->id, self::SKIP_TOKENS, true ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Find the previous meaningful token index.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $start  Start index exclusive.
	 * @return int|null
	 */
	private function previous_meaningful( array $tokens, int $start ): ?int {
		for ( $index = $start - 1; $index >= 0; $index-- ) {
			if ( ! in_array( $tokens[ $index ]->id, self::SKIP_TOKENS, true ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Determine namespace before a declaration.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $index  Declaration index.
	 * @return string
	 */
	private function namespace_before( array $tokens, int $index ): string {
		$namespace = '';
		for ( $cursor = 0; $cursor < $index; $cursor++ ) {
			if ( T_NAMESPACE === $tokens[ $cursor ]->id ) {
				$namespace = $this->read_name_until( $tokens, $cursor + 1, array( ';', '{' ) );
			}
		}

		return $namespace;
	}

	/**
	 * Map a first-party file to its architecture domain.
	 *
	 * @since NEXT
	 * @param string $file Relative plugin file path.
	 * @return string
	 */
	private function domain_for_file( string $file ): string {
		$file = str_replace( '\\', '/', $file );
		if ( 'performance-optimisation.php' === $file ) {
			return 'Bootstrap';
		}
		if ( 'uninstall.php' === $file ) {
			return 'Lifecycle';
		}
		if ( str_starts_with( $file, 'templates/' ) ) {
			return 'DropIn';
		}
		if ( str_starts_with( $file, 'includes/minify/' ) ) {
			return 'Minify';
		}
		$parts = explode( '/', $file );
		if ( count( $parts ) >= 3 && 'includes' === $parts[0] ) {
			return $parts[1];
		}
		if ( count( $parts ) >= 2 && 'includes' === $parts[0] ) {
			return 'Shared';
		}

		return 'Runtime';
	}

	/**
	 * Decode a PHP string literal sufficiently for class/file references.
	 *
	 * @since NEXT
	 * @param string $literal Token text including quotes.
	 * @return string
	 */
	private function decode_string( string $literal ): string {
		if ( strlen( $literal ) < 2 ) {
			return $literal;
		}
		$quote = $literal[0];
		$value = substr( $literal, 1, -1 );
		if ( "'" === $quote ) {
			return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $value );
		}
		$decoded = json_decode( $literal, true );

		return is_string( $decoded ) ? $decoded : stripcslashes( $value );
	}

	/**
	 * Convert token text to a count using wc-compatible newline semantics.
	 *
	 * @since NEXT
	 * @param string $source File contents.
	 * @return int
	 */
	private function line_count( string $source ): int {
		$lines = substr_count( $source, "\n" );
		if ( ! str_ends_with( $source, "\n" ) ) {
			++$lines;
		}

		return $lines;
	}

	/**
	 * Determine whether a token can begin a qualified class/function name.
	 *
	 * @since NEXT
	 * @param PhpToken $token Token object.
	 * @return bool
	 */
	private function is_name_token( PhpToken $token ): bool {
		return in_array( $token->id, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ), true );
	}

	/**
	 * Determine whether a token is part of a qualified name.
	 *
	 * @since NEXT
	 * @param PhpToken $token Token object.
	 * @return bool
	 */
	private function is_name_part( PhpToken $token ): bool {
		return in_array( $token->id, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ), true ) || ( T_NS_SEPARATOR === $token->id );
	}

	/**
	 * Join a token span into a qualified name.
	 *
	 * @since NEXT
	 * @param PhpToken[] $tokens Tokenized source.
	 * @param int        $start  Start index.
	 * @param int        $end    End index.
	 * @return string
	 */
	private function tokens_name( array $tokens, int $start, int $end ): string {
		$name = '';
		for ( $index = $start; $index <= $end; $index++ ) {
			$name .= $tokens[ $index ]->text;
		}

		return $name;
	}

	/**
	 * Map a token ID to a symbol kind.
	 *
	 * @since NEXT
	 * @param int $id Token ID.
	 * @return string
	 */
	private function symbol_kind( int $id ): string {
		if ( T_TRAIT === $id ) {
			return 'trait';
		}
		if ( T_INTERFACE === $id ) {
			return 'interface';
		}
		if ( T_ENUM === $id ) {
			return 'enum';
		}

		return 'class';
	}

	/**
	 * Deduplicate hook occurrences by name and retain source lines.
	 *
	 * @since NEXT
	 * @param array<int,array{name:string,line:int}> $hooks Hook occurrences.
	 * @return array<string,array<int,int>>
	 */
	private function unique_hooks( array $hooks ): array {
		$result = array();
		foreach ( $hooks as $hook ) {
			$this->add_reference( $result, $hook['name'], $hook['line'] );
		}
		ksort( $result, SORT_STRING );

		return $result;
	}

	/**
	 * Heuristically identify a class-name string.
	 *
	 * @since NEXT
	 * @param string $value Decoded string.
	 * @return bool
	 */
	private function looks_like_class_name( string $value ): bool {
		$value = ltrim( $value, '\\' );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value ) ) {
			return false;
		}
		$prefixes = array(
			'ActionScheduler\\',
			'League\\',
			'MatthiasMullie\\',
			'PerformanceOptimise\\',
			'Psr\\',
			'Redis',
			'Symfony\\',
			'voku\\',
			'WooCommerce\\',
			'WP_CLI\\',
		);
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $value, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Heuristically identify a first-party file path literal.
	 *
	 * @since NEXT
	 * @param string $value Decoded string.
	 * @return bool
	 */
	private function looks_like_file_reference( string $value ): bool {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			return false;
		}

		if ( ! str_ends_with( strtolower( $value ), '.php' ) ) {
			return false;
		}

		return str_contains( $value, '/' )
			|| str_contains( $value, '\\' )
			|| str_starts_with( $value, 'includes/' )
			|| str_starts_with( $value, 'templates/' )
			|| str_starts_with( $value, 'vendor/' );
	}

	/**
	 * Identify WordPress API calls.
	 *
	 * @since NEXT
	 * @param string $function_name Lowercase function name.
	 * @return bool
	 */
	private function is_wordpress_function( string $function_name ): bool {
		if ( str_starts_with( $function_name, 'wppo_' ) ) {
			return false;
		}
		if ( str_starts_with( $function_name, 'wp_' ) || str_starts_with( $function_name, '_wp_' ) ) {
			return true;
		}

		return in_array(
			$function_name,
			array(
				'absint',
				'add_action',
				'add_filter',
				'add_option',
				'add_menu_page',
				'add_meta_box',
				'add_options_page',
				'add_submenu_page',
				'add_query_arg',
				'add_rewrite_rule',
				'admin_url',
				'apply_filters',
				'checked',
				'content_url',
				'current_time',
				'current_user_can',
				'delete_metadata',
				'delete_option',
				'delete_transient',
				'date_i18n',
				'dbdelta',
				'did_action',
				'do_action',
				'esc_attr',
				'esc_html',
				'esc_js',
				'esc_textarea',
				'esc_url',
				'esc_url_raw',
				'get_admin_page_title',
				'get_attached_file',
				'get_bloginfo',
				'get_current_user_id',
				'get_current_blog_id',
				'get_current_screen',
				'get_header_textcolor',
				'get_home_path',
				'get_home_url',
				'get_interim_image_tag',
				'get_locale',
				'get_editable_roles',
				'get_option',
				'get_page_template',
				'get_page_templates',
				'get_post',
				'get_permalink',
				'get_post_field',
				'get_post_meta',
				'get_post_mime_type',
				'get_post_type',
				'get_post_type_archive_link',
				'get_post_types',
				'get_post_thumbnail_id',
				'get_posts',
				'get_query_var',
				'get_rest_url',
				'get_site_option',
				'get_site_url',
				'get_stylesheet',
				'get_taxonomy',
				'get_temp_dir',
				'get_term_link',
				'get_theme_mod',
				'get_the_ID',
				'get_the_date',
				'get_the_time',
				'get_the_title',
				'get_transient',
				'get_user_by',
				'get_userdata',
				'get_user_meta',
				'home_url',
				'human_time_diff',
				'is_admin',
				'is_archive',
				'is_feed',
				'is_front_page',
				'is_home',
				'is_multisite',
				'is_page',
				'is_search',
				'is_singular',
				'is_ssl',
				'is_trackback',
				'is_embed',
				'is_admin_bar_showing',
				'is_paged',
				'is_plugin_active',
				'is_robots',
				'is_user_logged_in',
				'is_wp_error',
				'is_404',
				'load_plugin_textdomain',
				'map_deep',
				'plugins_url',
				'register_activation_hook',
				'register_deactivation_hook',
				'register_rest_route',
				'remove_action',
				'remove_filter',
				'remove_theme_support',
				'remove_query_arg',
				'rest_ensure_response',
				'rest_sanitize_boolean',
				'rest_url',
				'sanitize_email',
				'sanitize_file_name',
				'sanitize_hex_color',
				'sanitize_key',
				'sanitize_text_field',
				'sanitize_textarea_field',
				'sanitize_title',
				'sanitize_url',
				'site_url',
				'trailingslashit',
				'untrailingslashit',
				'update_post_meta',
				'delete_post_meta',
				'update_option',
				'update_user_meta',
				'user_can',
				'wp_die',
				'wp_doing_ajax',
				'wp_doing_cron',
				'wp_get_current_user',
				'wp_get_attachment_image_src',
				'wp_get_attachment_metadata',
				'wp_get_attachment_url',
				'wp_insert_post',
				'wp_list_pluck',
				'wp_parse_args',
				'wp_send_json',
				'wp_timezone',
				'wp_update_post',
				'wp_update_attachment_metadata',
			),
			true
		);
	}

	/**
	 * Identify direct filesystem operations.
	 *
	 * @since NEXT
	 * @param string $function_name Lowercase function name.
	 * @return bool
	 */
	private function is_filesystem_function( string $function_name ): bool {
		$prefixes = array( 'file_', 'opendir_', 'readdir_', 'scandir_' );
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $function_name, $prefix ) ) {
				return true;
			}
		}

		return in_array(
			$function_name,
			array(
				'basename',
				'chmod',
				'clearstatcache',
				'copy',
				'dirname',
				'file',
				'file_exists',
				'filemtime',
				'filesize',
				'fclose',
				'fopen',
				'fgets',
				'fread',
				'fwrite',
				'glob',
				'is_dir',
				'is_executable',
				'is_file',
				'is_link',
				'is_readable',
				'is_writable',
				'lstat',
				'mkdir',
				'pathinfo',
				'readfile',
				'realpath',
				'rename',
				'rmdir',
				'stat',
				'touch',
				'unlink',
			),
			true
		);
	}

	/**
	 * Identify direct network operations.
	 *
	 * @since NEXT
	 * @param string $function_name Lowercase function name.
	 * @return bool
	 */
	private function is_network_function( string $function_name ): bool {
		return str_starts_with( $function_name, 'curl_' )
			|| in_array(
				$function_name,
				array(
					'download_url',
					'get_headers',
					'wp_remote_get',
					'wp_remote_head',
					'wp_remote_post',
					'wp_remote_request',
					'wp_safe_remote_get',
					'wp_safe_remote_head',
					'wp_safe_remote_post',
					'wp_safe_remote_request',
				),
				true
			);
	}
}
