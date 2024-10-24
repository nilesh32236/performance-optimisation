<?php

class DB_Optimisation {

	private array $options;
	private $wpdb;
	public function __construct( $options ) {
		global $wpdb;

		$this->wpdb    = $wpdb;
		$this->options = $options;

		add_action( 'qtpo_database_optimisation', array( $this, 'database_optimisation' ) );
	}

	public function database_optimisation() {
		$results = array(
			'repaired'  => array(),
			'optimized' => array(),
			'analyzed'  => array(),
			'errors'    => array(),
		);

		$tables = $this->wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		foreach ( $tables as $table ) {
			$table_name = $table['Name'];
			$engine     = $table['Engine'];

			if ( 'InnoDB' === $engine ) {

				$analyze_result = $this->wpdb->query( "ANALYZE TABLE $table_name" );
				if ( false === $analyze_result ) {
					$results['errors'][] = "$table_name (Analyze failed)";
				} else {
					$results['analyzed'][] = $table_name;
				}

				$optimize_result = $this->wpdb->query( "OPTIMIZE TABLE $table_name" );
				if ( false === $optimize_result ) {
					$results['errors'][] = "$table_name (Optimize failed)";
				} else {
					$results['optimized'][] = $table_name;
				}
			} else {
				$repair_result = $this->wpdb->query( "REPAIR TABLE $table_name" );
				if ( false === $repair_result ) {
					$results['errors'][] = "$table_name (Repair failed)";
				} else {
					$results['repaired'][] = $table_name;
				}

				$optimize_result = $this->wpdb->query( "OPTIMIZE TABLE $table_name" );
				if ( false === $optimize_result ) {
					$results['errors'][] = "$table_name (Optimize failed)";
				} else {
					$results['optimized'][] = $table_name;
				}
			}
		}

		return $results;
	}
}
