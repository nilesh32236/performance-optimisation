<?php
/**
 * Suggestion_Engine Class
 *
 * Maps telemetry and PageSpeed metric values to threshold-based status ratings
 * and returns actionable suggestion objects with fix_action fields that the
 * React UI uses to navigate the user directly to the relevant WPPO setting.
 *
 * Design principles:
 * - Accepts native PHP types from class-telemetry.php (bool, float) — never
 *   compares against localised string representations like 'Enabled'.
 * - Every returned suggestion object always contains all 6 required fields.
 * - fix_action is always validated against VALID_FIX_ACTIONS before returning.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.6.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Suggestion_Engine' ) ) {

	/**
	 * Class Suggestion_Engine
	 *
	 * Produces suggestion objects from telemetry scan results and PageSpeed
	 * Insights data. Each suggestion maps a metric to a WPPO tab or action
	 * via the fix_action field.
	 *
	 * @since 1.6.0
	 */
	class Suggestion_Engine {

		/**
		 * Enumerated set of valid fix_action values.
		 *
		 * The React UI maps these to WPPO tab names via FIX_ACTION_TAB_MAP.
		 * Any fix_action not in this list is replaced with 'no_action_required'.
		 *
		 * @since 1.6.0
		 * @var string[]
		 */
		const VALID_FIX_ACTIONS = array(
			'open_object_cache_tab',
			'open_image_optimization_tab',
			'open_file_optimization_tab',
			'enable_server_rules',
			'open_preload_tab',
			'no_action_required',
		);

		/**
		 * Build suggestion objects from a local telemetry scan result.
		 *
		 * Expects native PHP types from class-telemetry.php:
		 *   - ttfb                      : float  (milliseconds)
		 *   - uses_modern_image_formats : float  (0.0–100.0 percentage)
		 *   - gzip_brotli_compression   : bool
		 *   - cache_control_headers     : bool
		 *
		 * Never compares boolean fields against string literals like 'Enabled'.
		 *
		 * @since  1.6.0
		 * @param  array $telemetry Result from Telemetry::scan().
		 * @return array[] Array of suggestion objects.
		 */
		public static function from_telemetry( array $telemetry ): array {
			$suggestions = array();

			// --- TTFB — lower is better ---
			$ttfb          = (float) ( $telemetry['ttfb'] ?? 0 );
			$suggestions[] = self::make(
				'ttfb',
				$ttfb,
				'ms',
				array(
					'good' => 200,
					'poor' => 500,
				),
				'open_object_cache_tab',
				'Time to First Byte'
			);

			// --- Modern image formats — higher is better (percentage 0–100) ---
			// 95%+ = good, 50–94% = needs_improvement, <50% = poor.
			$modern_pct    = (float) ( $telemetry['uses_modern_image_formats'] ?? 0.0 );
			$suggestions[] = self::make_higher_is_better(
				'uses_modern_image_formats',
				$modern_pct,
				'%',
				array(
					'good' => 95.0,
					'poor' => 50.0,
				),
				'open_image_optimization_tab',
				'Modern Image Formats Usage'
			);

			// --- Gzip/Brotli compression — strict boolean check ---
			// Never compare against 'Enabled' string; use native bool from Telemetry.
			$compression   = (bool) ( $telemetry['gzip_brotli_compression'] ?? false );
			$suggestions[] = self::make_boolean(
				'gzip_brotli_compression',
				true === $compression,
				'enable_server_rules',
				'Gzip/Brotli Compression'
			);

			// --- Cache-Control headers — strict boolean check ---
			$cc            = (bool) ( $telemetry['cache_control_headers'] ?? false );
			$suggestions[] = self::make_boolean(
				'cache_control_headers',
				true === $cc,
				'enable_server_rules',
				'Cache-Control Headers'
			);

			return array_values( array_filter( $suggestions ) );
		}

		/**
		 * Build suggestion objects from a Google PageSpeed Insights result.
		 *
		 * @since  1.6.0
		 * @param  array $pagespeed Result from Pagespeed::get_results().
		 * @return array[] Array of suggestion objects.
		 */
		public static function from_pagespeed( array $pagespeed ): array {
			$suggestions = array();
			$diagnostics = $pagespeed['diagnostics'] ?? array();
			$vitals      = $pagespeed['vitals'] ?? array();

			// --- LCP — lower is better (seconds) ---
			$lcp_ms        = (float) ( $vitals['lcp']['value'] ?? 0 );
			$lcp_s         = $lcp_ms / 1000;
			$suggestions[] = self::make(
				'lcp',
				$lcp_s,
				's',
				array(
					'good' => 2.5,
					'poor' => 4.0,
				),
				'open_image_optimization_tab',
				'Largest Contentful Paint'
			);

			// --- Render-blocking resources ---
			$rbs = (float) ( $diagnostics['render-blocking-resources']['score'] ?? 1.0 );
			if ( $rbs < 0.9 ) {
				$suggestions[] = self::make_score(
					'render_blocking_resources',
					$rbs,
					'open_file_optimization_tab',
					'Render-Blocking Resources'
				);
			}

			// --- Unused JavaScript ---
			$ujs = (float) ( $diagnostics['unused-javascript']['score'] ?? 1.0 );
			if ( $ujs < 0.9 ) {
				$suggestions[] = self::make_score(
					'unused_javascript',
					$ujs,
					'open_file_optimization_tab',
					'Unused JavaScript'
				);
			}

			// --- Unused CSS ---
			$ucss = (float) ( $diagnostics['unused-css-rules']['score'] ?? 1.0 );
			if ( $ucss < 0.9 ) {
				$suggestions[] = self::make_score(
					'unused_css',
					$ucss,
					'open_file_optimization_tab',
					'Unused CSS'
				);
			}

			// --- Text compression ---
			$tc = (float) ( $diagnostics['uses-text-compression']['score'] ?? 1.0 );
			if ( $tc < 0.9 ) {
				$suggestions[] = self::make_score(
					'text_compression',
					$tc,
					'enable_server_rules',
					'Text Compression'
				);
			}

			// --- Server response time ---
			$srt = (float) ( $diagnostics['server-response-time']['score'] ?? 1.0 );
			if ( $srt < 0.9 ) {
				$suggestions[] = self::make_score(
					'server_response_time',
					$srt,
					'open_object_cache_tab',
					'Server Response Time'
				);
			}

			return array_values( array_filter( $suggestions ) );
		}

		/**
		 * Build a suggestion for a "lower is better" numeric metric.
		 *
		 * Status logic:
		 *   value <= thresholds['good'] → good
		 *   value <= thresholds['poor'] → needs_improvement
		 *   value >  thresholds['poor'] → poor
		 *
		 * @since  1.6.0
		 * @param  string $metric      Metric identifier.
		 * @param  float  $value       Metric value.
		 * @param  string $unit        Unit label (e.g. 'ms', 's', '%').
		 * @param  array  $thresholds  { good: float, poor: float }.
		 * @param  string $fix_action  fix_action value (validated against VALID_FIX_ACTIONS).
		 * @param  string $description Human-readable metric name.
		 * @return array Suggestion object.
		 */
		private static function make( string $metric, float $value, string $unit, array $thresholds, string $fix_action, string $description ): array {
			if ( $value <= $thresholds['good'] ) {
				$status     = 'good';
				$fix_action = 'no_action_required';
			} elseif ( $value <= $thresholds['poor'] ) {
				$status = 'needs_improvement';
			} else {
				$status = 'poor';
			}

			return self::build( $metric, $value, $unit, $status, $description, $fix_action );
		}

		/**
		 * Build a suggestion for a "higher is better" numeric metric (e.g. percentages).
		 *
		 * Status logic (reversed comparator):
		 *   value >= thresholds['good'] → good
		 *   value >= thresholds['poor'] → needs_improvement
		 *   value <  thresholds['poor'] → poor
		 *
		 * @since  1.6.0
		 * @param  string $metric      Metric identifier.
		 * @param  float  $value       Metric value.
		 * @param  string $unit        Unit label.
		 * @param  array  $thresholds  { good: float, poor: float }.
		 * @param  string $fix_action  fix_action value.
		 * @param  string $description Human-readable metric name.
		 * @return array Suggestion object.
		 */
		private static function make_higher_is_better( string $metric, float $value, string $unit, array $thresholds, string $fix_action, string $description ): array {
			if ( $value >= $thresholds['good'] ) {
				$status     = 'good';
				$fix_action = 'no_action_required';
			} elseif ( $value >= $thresholds['poor'] ) {
				$status = 'needs_improvement';
			} else {
				$status = 'poor';
			}

			return self::build( $metric, $value, $unit, $status, $description, $fix_action );
		}

		/**
		 * Build a suggestion for a Lighthouse audit score (0.0–1.0).
		 *
		 * Score thresholds follow Google's standard:
		 *   score >= 0.9 → good (filtered out before calling this method)
		 *   score >= 0.5 → needs_improvement
		 *   score <  0.5 → poor
		 *
		 * @since  1.6.0
		 * @param  string $metric      Metric identifier.
		 * @param  float  $score       Lighthouse score (0.0–1.0).
		 * @param  string $fix_action  fix_action value.
		 * @param  string $description Human-readable metric name.
		 * @return array Suggestion object.
		 */
		private static function make_score( string $metric, float $score, string $fix_action, string $description ): array {
			$status = $score >= 0.5 ? 'needs_improvement' : 'poor';
			return self::build( $metric, $score, 'score', $status, $description, $fix_action );
		}

		/**
		 * Build a suggestion for a boolean pass/fail metric.
		 *
		 * @since  1.6.0
		 * @param  string $metric      Metric identifier.
		 * @param  bool   $passing     True if the check passed.
		 * @param  string $fix_action  fix_action value when failing.
		 * @param  string $description Human-readable metric name.
		 * @return array Suggestion object.
		 */
		private static function make_boolean( string $metric, bool $passing, string $fix_action, string $description ): array {
			return self::build(
				$metric,
				$passing ? 'pass' : 'fail',
				'boolean',
				$passing ? 'good' : 'poor',
				$description,
				$passing ? 'no_action_required' : $fix_action
			);
		}

		/**
		 * Assemble the final suggestion object, validating fix_action.
		 *
		 * Guarantees all 6 required fields are always present.
		 * Replaces any unrecognised fix_action with 'no_action_required'.
		 *
		 * @since  1.6.0
		 * @param  string       $metric      Metric identifier.
		 * @param  float|string $value       Metric value.
		 * @param  string       $unit        Unit label.
		 * @param  string       $status      'good', 'needs_improvement', or 'poor'.
		 * @param  string       $description Human-readable metric name.
		 * @param  string       $fix_action  fix_action value.
		 * @return array Suggestion object with all 6 required fields.
		 */
		private static function build( string $metric, $value, string $unit, string $status, string $description, string $fix_action ): array {
			// Invariant: fix_action must always be a member of VALID_FIX_ACTIONS.
			if ( ! in_array( $fix_action, self::VALID_FIX_ACTIONS, true ) ) {
				$fix_action = 'no_action_required';
			}

			// Invariant: good status never needs a fix action.
			if ( 'good' === $status ) {
				$fix_action = 'no_action_required';
			}

			return array(
				'metric'      => $metric,
				'value'       => $value,
				'unit'        => $unit,
				'status'      => $status,
				'description' => $description,
				'fix_action'  => $fix_action,
			);
		}
	}
}
