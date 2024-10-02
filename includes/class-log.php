<?php

namespace PerformanceOptimise\Inc;

class Log {
	public function __construct( $activity ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'qtpo_activity_logs';

		$wpdb->insert(
			$table_name,
			array(
				'activity' => $activity,
			),
			array(
				'%s',
			)
		);
	}

	public static function get_recent_activities( $params ) {
		global $wpdb;

		$page     = isset( $params['page'] ) ? (int) $params['page'] : 1;
		$per_page = isset( $params['per_page'] ) ? (int) $params['per_page'] : 10;

		// Calculate offset for pagination
		$offset = ( $page - 1 ) * $per_page;

		// Get total number of activities
		$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}qtpo_activity_logs" );

		// Calculate total pages
		$total_pages = ceil( $total_items / $per_page );

		// Fetch paginated results
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}qtpo_activity_logs ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		error_log( print_r( $results, true ) );

		foreach ( $results as $index => $result ) {
			$results[ $index ]['activity'] = $result['activity'] . $result['created_at'];
		}

		// Response data
		return array(
			'activities'   => $results,
			'total_items'  => (int) $total_items,
			'current_page' => (int) $page,
			'total_pages'  => (int) $total_pages,
			'per_page'     => (int) $per_page,
		);
	}
}
