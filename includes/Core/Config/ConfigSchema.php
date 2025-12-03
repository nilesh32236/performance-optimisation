<?php
/**
 * Configuration Schema
 *
 * @package PerformanceOptimisation\Core\Config
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConfigSchema
 *
 * Defines the default configuration schema.
 *
 * @package PerformanceOptimisation\Core\Config
 */
class ConfigSchema {

	/**
	 * Get configuration schema.
	 *
	 * @return array Configuration schema.
	 */
	public function getSchema(): array {
		return array(
			'caching'      => array(
				'page_cache_enabled'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'cache_ttl'              => array(
					'type'    => 'integer',
					'min'     => 300,
					'max'     => 86400,
					'default' => 3600,
				),
				'cache_exclusions'       => array(
					'type'    => 'array',
					'default' => array(),
				),
				'object_cache_enabled'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'fragment_cache_enabled' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'minification' => array(
				'minify_css'          => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'minify_js'           => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'minify_html'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'combine_css'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'combine_js'          => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'inline_critical_css' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'images'       => array(
				'convert_to_webp'        => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'auto_convert_on_upload' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'convert_to_avif'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'lazy_loading'           => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'compression_quality'    => array(
					'type'    => 'integer',
					'min'     => 1,
					'max'     => 100,
					'default' => 85,
				),
				'resize_large_images'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'max_image_width'        => array(
					'type'    => 'integer',
					'min'     => 100,
					'max'     => 5000,
					'default' => 1920,
				),
				'max_image_height'       => array(
					'type'    => 'integer',
					'min'     => 100,
					'max'     => 5000,
					'default' => 1080,
				),
			),
			'preloading'   => array(
				'dns_prefetch'         => array(
					'type'    => 'array',
					'default' => array(),
				),
				'preconnect'           => array(
					'type'    => 'array',
					'default' => array(),
				),
				'preload_fonts'        => array(
					'type'    => 'array',
					'default' => array(),
				),
				'preload_critical_css' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'database'     => array(
				'cleanup_revisions' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'cleanup_spam'      => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'cleanup_trash'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'optimize_tables'   => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'advanced'     => array(
				'disable_emojis'       => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'disable_embeds'       => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'remove_query_strings' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'defer_js'             => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'async_js'             => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		);
	}
}
