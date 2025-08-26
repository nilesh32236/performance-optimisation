<?php
/**
 * JS Optimizer
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use MatthiasMullie\Minify\JS as MatthiasJsMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsOptimizer implements OptimizerInterface {

	public function optimize( string $content, array $options = [] ): string {
		try {
			$minifier = new MatthiasJsMinifier( $content );
			return $minifier->minify();
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'JS Minification Error: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}
}
