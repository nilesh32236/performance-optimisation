<?php
/**
 * Shared WPDB mock for unit tests.
 *
 * Extracted from DatabaseCleanupTest to allow isolated test runs
 * (WPPO_DB_Mock was previously defined only in that file, relying on
 * alphabetical load order).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
if ( ! class_exists( 'WPPO_DB_Mock' ) ) {
	class WPPO_DB_Mock {
		public $prefix = 'wp_';
		public $last_error = '';
		// phpcs:disable Squiz.Commenting.VariableComment -- Table-name stand-ins mirror $wpdb.
		public $posts              = 'wp_posts';
		public $postmeta           = 'wp_postmeta';
		public $comments           = 'wp_comments';
		public $commentmeta        = 'wp_commentmeta';
		public $options            = 'wp_options';
		public $usermeta           = 'wp_usermeta';
		public $users              = 'wp_users';
		public $terms              = 'wp_terms';
		public $term_taxonomy      = 'wp_term_taxonomy';
		public $termmeta           = 'wp_termmeta';
		public $term_relationships = 'wp_term_relationships';
		// phpcs:enable Squiz.Commenting.VariableComment
		public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}
		public function get_var( $query = null, $x = 0, $y = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return null;
		}
		public function get_row( $query = null, $output = OBJECT, $y = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return null;
		}
		public function get_results( $query = null, $output = OBJECT ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}
		public function query( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return 0;
		}
		public function prepare( $query, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return $query;
		}
		public function esc_like( $text ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $text;
		}
		public function db_version() { // @since NEXT
			return '8.0.33';
		}
	}
}
