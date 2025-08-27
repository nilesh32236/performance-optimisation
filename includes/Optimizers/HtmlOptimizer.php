<?php
/**
 * HTML Optimizer
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use voku\helper\HtmlMin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HtmlOptimizer implements OptimizerInterface {

	public function optimize( string $content, array $options = array() ): string {
		try {
			$htmlMin = new HtmlMin();
			return $htmlMin->minify( $content );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'HTML Minification Error: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}
}
