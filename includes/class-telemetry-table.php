<?php
/**
 * Telemetry_Table class for managing the wppo_telemetry custom database table.
 *
 * Handles table creation, row insertion, retrieval, and truncation for the
 * High-Value Page Tracker feature (Phase 3, v1.7.0).
 *
 * @package PerformanceOptimise\Inc
 * @since   1.7.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Telemetry_Table' ) ) {

	/**
	 * Manages the wppo_telemetry custom database table.
	 *
	 * @since 1.7.0
	 */
	class Telemetry_Table {

		/**
		 * Returns the full prefixed table name.
		 *
		 * @since 1.7.0
		 * @return string
		 */
		public static function get_table_name(): string {
			global $wpdb;
			return $wpdb->prefix . 'wppo_telemetry';
		}

		/**
		 * Creates (or upgrades) the wppo_telemetry table using dbDelta().
		 *
		 * Safe to call on every plugin load — dbDelta() is idempotent and only
		 * applies changes when the schema differs from the live table.
		 *
		 * @since 1.7.0
		 * @return void
		 */
		public static function create_table(): void {
			global $wpdb;

			$table_name      = self::get_table_name();
			$charset_collate = $wpdb->get_charset_collate();

			/*
			 * IMPORTANT: dbDelta() is strict about SQL formatting.
			 * - Two spaces before PRIMARY KEY.
			 * - Each column definition on its own line.
			 * - No trailing comma after the last column.
			 */
			$sql = "CREATE TABLE $table_name (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(2083) NOT NULL DEFAULT '',
  scan_type varchar(20) NOT NULL DEFAULT 'manual',
  load_time float DEFAULT NULL,
  ttfb float DEFAULT NULL,
  fcp float DEFAULT NULL,
  lcp float DEFAULT NULL,
  tbt float DEFAULT NULL,
  cls float DEFAULT NULL,
  speed_index float DEFAULT NULL,
  performance_score float DEFAULT NULL,
  accessibility_score float DEFAULT NULL,
  seo_score float DEFAULT NULL,
  best_practices_score float DEFAULT NULL,
  css_count int(11) DEFAULT NULL,
  js_count int(11) DEFAULT NULL,
  media_count int(11) DEFAULT NULL,
  total_size bigint(20) DEFAULT NULL,
  uses_https tinyint(1) DEFAULT NULL,
  uses_modern_image_formats float DEFAULT NULL,
  image_alt_attributes float DEFAULT NULL,
  robots_txt_exists tinyint(1) DEFAULT NULL,
  gzip_brotli_compression tinyint(1) DEFAULT NULL,
  cache_control_headers tinyint(1) DEFAULT NULL,
  scanned_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY url_idx (url(191)),
  KEY scanned_at_idx (scanned_at)
) $charset_collate;";

			require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/upgrade.php' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			dbDelta( $sql );
		}

		/**
		 * Inserts a combined telemetry + PageSpeed row into the table.
		 *
		 * @since 1.7.0
		 *
		 * @param array  $scan_result Telemetry scan result (16-key array from Telemetry::scan()).
		 * @param array  $ps_result   PageSpeed prepared result (may be empty array).
		 * @param string $scan_type   'manual' or 'scheduled'.
		 * @return int|false Inserted row ID on success, false on failure.
		 */
		public static function insert( array $scan_result, array $ps_result, string $scan_type ) {
			global $wpdb;

			$url = $scan_result['url'] ?? '';
			if ( empty( $url ) ) {
				return false;
			}

			$data = array(
				'url'                       => sanitize_url( $url ),
				'scan_type'                 => in_array( $scan_type, array( 'manual', 'scheduled' ), true ) ? $scan_type : 'manual',
				'load_time'                 => isset( $scan_result['load_time'] ) ? (float) $scan_result['load_time'] : null,
				'ttfb'                      => isset( $scan_result['ttfb'] ) ? (float) $scan_result['ttfb'] : null,
				'css_count'                 => isset( $scan_result['css_count'] ) ? (int) $scan_result['css_count'] : null,
				'js_count'                  => isset( $scan_result['js_count'] ) ? (int) $scan_result['js_count'] : null,
				'media_count'               => isset( $scan_result['media_count'] ) ? (int) $scan_result['media_count'] : null,
				'total_size'                => isset( $scan_result['total_size'] ) ? (int) $scan_result['total_size'] : null,
				'uses_https'                => isset( $scan_result['uses_https'] ) ? ( (bool) $scan_result['uses_https'] ? 1 : 0 ) : null,
				'uses_modern_image_formats' => isset( $scan_result['uses_modern_image_formats'] ) ? (float) $scan_result['uses_modern_image_formats'] : null,
				'image_alt_attributes'      => isset( $scan_result['image_alt_attributes'] ) ? (float) $scan_result['image_alt_attributes'] : null,
				'robots_txt_exists'         => isset( $scan_result['robots_txt_exists'] ) ? ( (bool) $scan_result['robots_txt_exists'] ? 1 : 0 ) : null,
				'gzip_brotli_compression'   => isset( $scan_result['gzip_brotli_compression'] ) ? ( (bool) $scan_result['gzip_brotli_compression'] ? 1 : 0 ) : null,
				'cache_control_headers'     => isset( $scan_result['cache_control_headers'] ) ? ( (bool) $scan_result['cache_control_headers'] ? 1 : 0 ) : null,
				'scanned_at'                => current_time( 'mysql', true ),
			);

			// Merge PageSpeed fields when available.
			if ( ! empty( $ps_result ) && empty( $ps_result['error'] ) ) {
				$cwv = $ps_result['core_web_vitals'] ?? array();
				$cat = $ps_result['categories'] ?? array();

				$data['fcp']                  = isset( $cwv['fcp'] ) ? (float) $cwv['fcp'] : null;
				$data['lcp']                  = isset( $cwv['lcp'] ) ? (float) $cwv['lcp'] : null;
				$data['tbt']                  = isset( $cwv['tbt'] ) ? (float) $cwv['tbt'] : null;
				$data['cls']                  = isset( $cwv['cls'] ) ? (float) $cwv['cls'] : null;
				$data['speed_index']          = isset( $cwv['speed_index'] ) ? (float) $cwv['speed_index'] : null;
				$data['performance_score']    = isset( $cat['performance'] ) ? (float) $cat['performance'] : null;
				$data['accessibility_score']  = isset( $cat['accessibility'] ) ? (float) $cat['accessibility'] : null;
				$data['seo_score']            = isset( $cat['seo'] ) ? (float) $cat['seo'] : null;
				$data['best_practices_score'] = isset( $cat['best-practices'] ) ? (float) $cat['best-practices'] : null;
			}

			// Build format array matching $data keys.
			$format = array(
				'%s', // url.
				'%s', // scan_type.
				'%f', // load_time.
				'%f', // ttfb.
				'%d', // css_count.
				'%d', // js_count.
				'%d', // media_count.
				'%d', // total_size.
				'%d', // uses_https.
				'%f', // uses_modern_image_formats.
				'%f', // image_alt_attributes.
				'%d', // robots_txt_exists.
				'%d', // gzip_brotli_compression.
				'%d', // cache_control_headers.
				'%s', // scanned_at.
			);

			if ( ! empty( $ps_result ) && empty( $ps_result['error'] ) ) {
				$format = array_merge(
					$format,
					array( '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f' )
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( self::get_table_name(), $data, $format );

			return false !== $result ? $wpdb->insert_id : false;
		}

		/**
		 * Retrieves telemetry rows, optionally filtered by URL.
		 *
		 * @since 1.7.0
		 *
		 * @param string $url Optional URL to filter by. Empty string returns all rows.
		 * @return array Array of row objects ordered by scanned_at DESC.
		 */
		public static function get_rows( string $url = '' ): array {
			global $wpdb;

			$table = self::get_table_name();

			if ( ! empty( $url ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT * FROM $table WHERE url = %s ORDER BY scanned_at DESC",
						$url
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM $table ORDER BY scanned_at DESC"
				);
			}

			return ! empty( $rows ) ? $rows : array();
		}

		/**
		 * Truncates the telemetry table and returns the number of rows deleted.
		 *
		 * @since 1.7.0
		 * @return int Number of rows that existed before truncation.
		 */
		public static function truncate(): int {
			global $wpdb;

			$table = self::get_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM $table"
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return $count;
		}
	}
}
