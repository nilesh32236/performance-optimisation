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
}
