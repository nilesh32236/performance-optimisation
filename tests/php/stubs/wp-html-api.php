<?php
/**
 * Minimal functional stand-ins for the WP_HTML_* classes used by unit tests.
 *
 * WordPress core is not loaded in the bare PHPUnit environment, so the HTML
 * transformation helpers in Image_Optimisation cannot run against the real
 * WP_HTML_Tag_Processor / WP_HTML_Processor. These stubs implement the small
 * subset of the public API the plugin relies on (token iteration, attribute
 * read/write, and serialization) so the Pass A / Pass B logic can be exercised.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile, WordPress.Files.FileName

if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	/**
	 * Minimal functional stand-in for WP_HTML_Tag_Processor.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_HTML_Tag_Processor {

		/**
		 * HTML tokens ([#text, #tag]) parsed from the buffer.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		protected $tokens = array();

		/**
		 * Cursor pointing at the current token.
		 *
		 * @var int
		 */
		protected $cursor = -1;

		/**
		 * Constructor.
		 *
		 * @param string $html The HTML buffer to process.
		 */
		public function __construct( $html ) {
			$this->tokens = $this->tokenize( (string) $html );
		}

		/**
		 * Move the cursor to the next non-closing tag matching the query.
		 *
		 * @param array<string, string> $query Optional tag_name filter.
		 * @return bool True when a matching tag is found.
		 */
		public function next_tag( $query = null ) {
			$tag_name = isset( $query['tag_name'] ) ? strtolower( (string) $query['tag_name'] ) : null;
			$total    = count( $this->tokens );

			while ( ++$this->cursor < $total ) {
				$token = $this->tokens[ $this->cursor ];
				if ( '#tag' !== $token['type'] || $token['closer'] ) {
					continue;
				}
				if ( null !== $tag_name && strtolower( $token['tag'] ) !== $tag_name ) {
					continue;
				}
				return true;
			}

			return false;
		}

		/**
		 * Get the tag name of the current token.
		 *
		 * @return string Tag name, uppercased.
		 */
		public function get_tag() {
			return isset( $this->tokens[ $this->cursor ] ) ? $this->tokens[ $this->cursor ]['tag'] : '';
		}

		/**
		 * Get an attribute value on the current token.
		 *
		 * @param string $name Attribute name.
		 * @return string|bool|null Attribute value, true for boolean attributes, null when absent.
		 */
		public function get_attribute( $name ) {
			$token = isset( $this->tokens[ $this->cursor ] ) ? $this->tokens[ $this->cursor ] : null;
			if ( ! $token || '#tag' !== $token['type'] ) {
				return null;
			}

			$name = strtolower( (string) $name );
			foreach ( $token['attrs'] as $attr ) {
				if ( $attr['name'] === $name ) {
					return $attr['value'];
				}
			}

			return null;
		}

		/**
		 * Set an attribute value on the current token.
		 *
		 * @param string $name  Attribute name.
		 * @param mixed  $value Attribute value.
		 * @return bool True on success.
		 */
		public function set_attribute( $name, $value ) {
			$idx   = $this->cursor;
			$token = isset( $this->tokens[ $idx ] ) ? $this->tokens[ $idx ] : null;
			if ( ! $token || '#tag' !== $token['type'] ) {
				return false;
			}

			$name = strtolower( (string) $name );
			foreach ( $token['attrs'] as $i => $attr ) {
				if ( $attr['name'] === $name ) {
					$this->tokens[ $idx ]['attrs'][ $i ]['value'] = (string) $value;
					$this->tokens[ $idx ]['modified']             = true;
					return true;
				}
			}

			$this->tokens[ $idx ]['attrs'][]  = array(
				'name'  => $name,
				'value' => (string) $value,
			);
			$this->tokens[ $idx ]['modified'] = true;
			return true;
		}

		/**
		 * Remove an attribute from the current token.
		 *
		 * @param string $name Attribute name.
		 * @return bool True on success.
		 */
		public function remove_attribute( $name ) {
			$idx   = $this->cursor;
			$token = isset( $this->tokens[ $idx ] ) ? $this->tokens[ $idx ] : null;
			if ( ! $token || '#tag' !== $token['type'] ) {
				return false;
			}

			$name = strtolower( (string) $name );
			foreach ( $token['attrs'] as $i => $attr ) {
				if ( $attr['name'] === $name ) {
					array_splice( $this->tokens[ $idx ]['attrs'], $i, 1 );
					$this->tokens[ $idx ]['modified'] = true;
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether the current token carries the given CSS class.
		 *
		 * @param string $class_name Class name to look for.
		 * @return bool True when the class is present.
		 */
		public function has_class( $class_name ) {
			$existing = $this->get_attribute( 'class' );
			if ( ! $existing ) {
				return false;
			}

			$classes = preg_split( '/\s+/', trim( (string) $existing ) );
			return in_array( (string) $class_name, $classes, true );
		}

		/**
		 * Add a CSS class to the current token's class attribute.
		 *
		 * @param string $class_name Class name to add.
		 * @return bool True on success or when the class is already present.
		 */
		public function add_class( $class_name ) {
			$idx   = $this->cursor;
			$token = isset( $this->tokens[ $idx ] ) ? $this->tokens[ $idx ] : null;
			if ( ! $token || '#tag' !== $token['type'] ) {
				return false;
			}

			if ( $this->has_class( $class_name ) ) {
				return true;
			}

			$existing = $this->get_attribute( 'class' );
			$new      = $existing ? $existing . ' ' . $class_name : $class_name;
			return $this->set_attribute( 'class', $new );
		}

		/**
		 * Serialize the processed HTML, applying any token modifications.
		 *
		 * @return string The updated HTML.
		 */
		public function get_updated_html() {
			$html = '';
			foreach ( $this->tokens as $token ) {
				if ( '#tag' !== $token['type'] || empty( $token['modified'] ) ) {
					$html .= $token['full'];
					continue;
				}
				$html .= $this->render_token( $token );
			}
			return $html;
		}

		/**
		 * Rebuild the HTML for a modified tag token.
		 *
		 * @param array<string, mixed> $token Tag token data.
		 * @return string The rendered tag.
		 */
		protected function render_token( array $token ) {
			$self_closing = '/>' === substr( rtrim( $token['full'] ), -2 );
			$out          = '<' . strtolower( $token['tag'] );

			foreach ( $token['attrs'] as $attr ) {
				$out .= ' ' . $attr['name'];
				if ( true !== $attr['value'] ) {
					$out .= '="' . $attr['value'] . '"';
				}
			}

			return $out . ( $self_closing ? ' />' : '>' );
		}

		/**
		 * Tokenize an HTML string into text and tag tokens.
		 *
		 * @param string $html The HTML buffer.
		 * @return array<int, array<string, mixed>> List of tokens.
		 */
		private function tokenize( $html ) {
			$tokens = array();
			$last   = 0;

			if ( preg_match_all( '#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:\s[^<>]*?)?)(/?)>#s', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as $i => $full ) {
					$full_str = $full[0];
					$full_off = $full[1];

					if ( $full_off > $last ) {
						$tokens[] = array(
							'type' => '#text',
							'full' => substr( $html, $last, $full_off - $last ),
						);
					}

					$is_closer = '/' === $matches[1][ $i ][0];
					$tokens[]  = array(
						'type'     => '#tag',
						'tag'      => strtoupper( $matches[2][ $i ][0] ),
						'full'     => $full_str,
						'closer'   => $is_closer,
						'attrs'    => $is_closer ? array() : $this->parse_attrs( $matches[3][ $i ][0] ),
						'modified' => false,
					);

					$last = $full_off + strlen( $full_str );
				}
			}

			if ( $last < strlen( $html ) ) {
				$tokens[] = array(
					'type' => '#text',
					'full' => substr( $html, $last ),
				);
			}

			return $tokens;
		}

		/**
		 * Parse the attribute list of a tag.
		 *
		 * @param string $attr_str The raw attribute substring.
		 * @return array<int, array<string, mixed>> List of attributes.
		 */
		private function parse_attrs( $attr_str ) {
			$attrs = array();

			if ( preg_match_all( '#([a-zA-Z_:][a-zA-Z0-9_:.\-]*)\s*=\s*(["\'])(.*?)\2|([a-zA-Z_:][a-zA-Z0-9_:.\-]*)\s*=\s*([^\s"\'<>]+)|([a-zA-Z_:][a-zA-Z0-9_:.\-]*)#s', $attr_str, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					if ( ! empty( $match[1] ) ) {
						$attrs[] = array(
							'name'  => strtolower( $match[1] ),
							'value' => $match[3],
						);
					} elseif ( ! empty( $match[4] ) ) {
						$attrs[] = array(
							'name'  => strtolower( $match[4] ),
							'value' => $match[5],
						);
					} elseif ( ! empty( $match[6] ) ) {
						$attrs[] = array(
							'name'  => strtolower( $match[6] ),
							'value' => true,
						);
					}
				}
			}

			return $attrs;
		}
	}
}

if ( ! class_exists( 'WP_HTML_Processor' ) ) {
	/**
	 * Minimal functional stand-in for WP_HTML_Processor.
	 *
	 * Streams every token (text, comments, tags) and rebuilds the document via
	 * serialize_token() — the pattern used by the WP 6.9+ enhancement path.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_HTML_Processor extends WP_HTML_Tag_Processor {

		/**
		 * Whether the parser bailed on unsupported markup.
		 *
		 * @var string|null
		 */
		private $last_error = null;

		/**
		 * Create a full parser for the given HTML.
		 *
		 * @param string $html The HTML document.
		 * @return WP_HTML_Processor|null A parser instance, or null on failure.
		 */
		public static function create_full_parser( $html ) {
			return new self( (string) $html );
		}

		/**
		 * Move the cursor to the next token (text or tag).
		 *
		 * @return bool True while tokens remain.
		 */
		public function next_token() {
			$total = count( $this->tokens );
			return ++$this->cursor < $total;
		}
		/**
		 * Get the current token type.
		 *
		 * @return string '#tag' for tags, '#text' otherwise.
		 */
		public function get_token_type() {
			return isset( $this->tokens[ $this->cursor ] ) ? $this->tokens[ $this->cursor ]['type'] : '#text';
		}

		/**
		 * Whether the current token is a closing tag.
		 *
		 * @return bool True for closing tags.
		 */
		public function is_tag_closer() {
			return isset( $this->tokens[ $this->cursor ] ) && ! empty( $this->tokens[ $this->cursor ]['closer'] );
		}

		/**
		 * Serialize the current token (applying modifications).
		 *
		 * @return string The token's HTML.
		 */
		public function serialize_token() {
			$token = isset( $this->tokens[ $this->cursor ] ) ? $this->tokens[ $this->cursor ] : null;
			if ( ! $token ) {
				return '';
			}
			if ( '#tag' === $token['type'] && ! empty( $token['modified'] ) ) {
				return $this->render_token( $token );
			}
			return $token['full'];
		}

		/**
		 * Get the last parse error.
		 *
		 * @return string|null Null when parsing succeeded.
		 */
		public function get_last_error() {
			return $this->last_error;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile
