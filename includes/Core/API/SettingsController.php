<?php
/**
 * Settings Controller Class
 *
 * Handles REST API endpoints for plugin settings management including
 * getting, updating, importing, and exporting settings.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Controller class for settings-related API endpoints.
 */
class SettingsController extends BaseController {


	/**
	 * Controller route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'settings';

	/**
	 * Settings schema for validation.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $settings_schema;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings_schema = $this->get_settings_schema();
	}

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Get all settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Update settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_rate_limited_admin_permissions' ),
				'args'                => array(
					'settings' => array(
						'required'    => true,
						'type'        => 'object',
						'description' => 'Settings data to update.',
					),
					'merge'    => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Whether to merge with existing settings or replace completely.',
					),
				),
			)
		);

		// Get specific setting group.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<group>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_setting_group' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Update specific setting group.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<group>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_setting_group' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'settings' => array(
						'required'    => true,
						'type'        => 'object',
						'description' => 'Settings data for the group.',
					),
				),
			)
		);

		// Reset settings to defaults.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reset',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'group' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Specific group to reset (optional).',
					),
				),
			)
		);

		// Export settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'format' => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'json',
						'enum'        => array( 'json', 'array' ),
						'description' => 'Export format.',
					),
				),
			)
		);

		// Import settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'settings' => array(
						'required'    => true,
						'description' => 'Settings data to import (JSON string or object).',
					),
					'validate' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Whether to validate settings before import.',
					),
				),
			)
		);

		// Validate settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'settings' => array(
						'required'    => true,
						'type'        => 'object',
						'description' => 'Settings data to validate.',
					),
				),
			)
		);
	}

	/**
	 * Check admin permissions with rate limiting
	 *
	 * @return bool|\WP_Error
	 */
	public function check_rate_limited_admin_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'performance-optimisation' ),
				array( 'status' => 403 )
			);
		}

		// Rate limit: 10 requests per minute.
		$key = 'settings_update_' . get_current_user_id();
		if ( \PerformanceOptimisation\Utils\RateLimiter::is_limited( $key, 10, 60 ) ) {
			return new \WP_Error(
				'rest_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'performance-optimisation' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Get all settings endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Get Settings' );

			$settings = get_option( 'wppo_settings', array() );
			$defaults = $this->get_default_settings();

			// Merge with defaults to ensure all settings are present.
			$complete_settings = array_replace_recursive( $defaults, $settings );

			return $this->send_success_response(
				array(
					'settings' => $complete_settings,
					'schema'   => $this->settings_schema,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get settings' );
		}
	}

	/**
	 * Update settings endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Update Settings' );

			// Validate request.
			$validation = $this->validate_request(
				$request,
				array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
					'merge'    => array( 'type' => 'boolean' ),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$data         = $validation['data'];
			$new_settings = $data['settings'];
			$merge        = $data['merge'] ?? true;

			// Validate settings structure.
			$settings_validation = $this->validate_settings_data( $new_settings );
			if ( ! $settings_validation['valid'] ) {
				return $this->send_error_response(
					'invalid_settings',
					'Settings validation failed.',
					400,
					array( 'validation_errors' => $settings_validation['errors'] )
				);
			}

			$current_settings = get_option( 'wppo_settings', array() );

			if ( $merge ) {
				// Merge with existing settings.
				$updated_settings = array_replace_recursive( $current_settings, $new_settings );
			} else {
				// Replace completely.
				$updated_settings = $new_settings;
			}

			// Check for critical changes that require cache clearing.
			$cache_clear_needed = $this->check_cache_clear_needed( $current_settings, $updated_settings );

			// Check if page cache was enabled/disabled.
			$page_cache_changed = $this->check_page_cache_changed( $current_settings, $updated_settings );

			// Update settings - update_option returns false if value hasn't changed.
			$success = update_option( 'wppo_settings', $updated_settings );

			// If update_option returns false, check if it's because the value is the same.
			if ( ! $success ) {
				$stored_value = get_option( 'wppo_settings' );
				// If the stored value matches what we're trying to save, consider it a success.
				if ( $stored_value === $updated_settings ) {
					$success = true;
				}
			}

			if ( ! $success ) {
				return $this->send_error_response(
					'update_failed',
					'Failed to update settings in database.',
					500
				);
			}

			// Handle advanced-cache.php drop-in.
			if ( $page_cache_changed ) {
				$this->manage_advanced_cache_dropin( $updated_settings );
			}

			// Handle browser cache .htaccess rules.
			$browser_cache_changed = $this->check_browser_cache_changed( $current_settings, $updated_settings );
			if ( $browser_cache_changed ) {
				$this->manage_browser_cache( $updated_settings );
			}

			// Clear cache if needed.
			if ( $cache_clear_needed ) {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
				}
				\PerformanceOptimise\Inc\Cache::clear_cache();
			}

			// Log the change using modern logging.
			\PerformanceOptimisation\Utils\LoggingUtil::info(
				'WPPO: Settings updated via API',
				array( 'time' => current_time( 'mysql' ) )
			);

			return $this->send_success_response(
				array(
					'message'       => 'Settings updated successfully.',
					'settings'      => $updated_settings,
					'cache_cleared' => $cache_clear_needed,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to update settings' );
		}
	}

	/**
	 * Get specific setting group endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function get_setting_group( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$group = $request->get_param( 'group' );
			$this->log_request( $request, "Get Setting Group: {$group}" );

			$all_settings = get_option( 'wppo_settings', array() );
			$defaults     = $this->get_default_settings();

			if ( ! isset( $defaults[ $group ] ) ) {
				return $this->send_error_response(
					'invalid_group',
					sprintf( 'Setting group "%s" does not exist.', $group ),
					404
				);
			}

			$group_settings         = $all_settings[ $group ] ?? array();
			$default_group_settings = $defaults[ $group ];

			// Merge with defaults.
			$complete_group_settings = array_replace_recursive( $default_group_settings, $group_settings );

			return $this->send_success_response(
				array(
					'group'    => $group,
					'settings' => $complete_group_settings,
					'schema'   => $this->settings_schema[ $group ] ?? array(),
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to get setting group' );
		}
	}

	/**
	 * Update specific setting group endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function update_setting_group( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$group = $request->get_param( 'group' );
			$this->log_request( $request, "Update Setting Group: {$group}" );

			// Validate request.
			$validation = $this->validate_request(
				$request,
				array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$new_group_settings = $validation['data']['settings'];

			// Check if group exists.
			$defaults = $this->get_default_settings();
			if ( ! isset( $defaults[ $group ] ) ) {
				return $this->send_error_response(
					'invalid_group',
					sprintf( 'Setting group "%s" does not exist.', $group ),
					404
				);
			}

			// Validate group settings.
			$group_validation = $this->validate_setting_group( $group, $new_group_settings );
			if ( ! $group_validation['valid'] ) {
				return $this->send_error_response(
					'invalid_settings',
					'Group settings validation failed.',
					400,
					array( 'validation_errors' => $group_validation['errors'] )
				);
			}

			$current_settings           = get_option( 'wppo_settings', array() );
			$current_settings[ $group ] = $new_group_settings;

			// Update settings.
			$success = update_option( 'wppo_settings', $current_settings );

			if ( ! $success ) {
				return $this->send_error_response(
					'update_failed',
					'Failed to update settings in database.',
					500
				);
			}

			// Log the change.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}
			new \PerformanceOptimise\Inc\Log(
				"Settings group '{$group}' updated via API on " . current_time( 'mysql' )
			);

			return $this->send_success_response(
				array(
					'message'  => sprintf( 'Setting group "%s" updated successfully.', $group ),
					'group'    => $group,
					'settings' => $new_group_settings,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to update setting group' );
		}
	}

	/**
	 * Reset settings endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function reset_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$group = $request->get_param( 'group' );
			$this->log_request( $request, $group ? "Reset Setting Group: {$group}" : 'Reset All Settings' );

			$defaults = $this->get_default_settings();

			if ( $group ) {
				// Reset specific group.
				if ( ! isset( $defaults[ $group ] ) ) {
					return $this->send_error_response(
						'invalid_group',
						sprintf( 'Setting group "%s" does not exist.', $group ),
						404
					);
				}

				$current_settings           = get_option( 'wppo_settings', array() );
				$current_settings[ $group ] = $defaults[ $group ];
				$success                    = update_option( 'wppo_settings', $current_settings );
				$message                    = sprintf( 'Setting group "%s" reset to defaults.', $group );
			} else {
				// Reset all settings.
				$success = update_option( 'wppo_settings', $defaults );
				$message = 'All settings reset to defaults.';
			}

			if ( ! $success ) {
				return $this->send_error_response(
					'reset_failed',
					'Failed to reset settings in database.',
					500
				);
			}

			// Clear cache after reset.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			}
			\PerformanceOptimise\Inc\Cache::clear_cache();

			// Log the change.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}
			new \PerformanceOptimise\Inc\Log( $message . ' on ' . current_time( 'mysql' ) );

			return $this->send_success_response(
				array(
					'message'  => $message,
					'settings' => $group ? $defaults[ $group ] : $defaults,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to reset settings' );
		}
	}

	/**
	 * Export settings endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function export_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$format = $request->get_param( 'format' );
			if ( ! $format ) {
				$format = 'json';
			}
			$this->log_request( $request, 'Export Settings' );

			$settings = get_option( 'wppo_settings', array() );

			$export_data = array(
				'settings' => $settings,
				'metadata' => array(
					'exported_at'       => current_time( 'mysql' ),
					'exported_by'       => get_current_user_id(),
					'plugin_version'    => WPPO_VERSION ?? '1.0.0',
					'wordpress_version' => get_bloginfo( 'version' ),
					'site_url'          => get_site_url(),
				),
			);

			if ( 'json' === $format ) {
				$export_data['json'] = wp_json_encode( $export_data, JSON_PRETTY_PRINT );
			}

			return $this->send_success_response( $export_data );

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to export settings' );
		}
	}

	/**
	 * Import settings endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function import_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Import Settings' );

			$settings_data = $request->get_param( 'settings' );
			$validate      = $request->get_param( 'validate' ) ?? true;

			// Parse JSON if string.
			if ( is_string( $settings_data ) ) {
				$parsed_data = json_decode( $settings_data, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return $this->send_error_response(
						'invalid_json',
						'Invalid JSON format for settings import.',
						400
					);
				}
				$settings_data = $parsed_data;
			}

			// Extract settings from import data.
			if ( isset( $settings_data['settings'] ) ) {
				$import_settings = $settings_data['settings'];
			} else {
				$import_settings = $settings_data;
			}

			if ( ! is_array( $import_settings ) ) {
				return $this->send_error_response(
					'invalid_format',
					'Settings data must be an array or object.',
					400
				);
			}

			// Validate settings if requested.
			if ( $validate ) {
				$validation = $this->validate_settings_data( $import_settings );
				if ( ! $validation['valid'] ) {
					return $this->send_error_response(
						'validation_failed',
						'Imported settings validation failed.',
						400,
						array( 'validation_errors' => $validation['errors'] )
					);
				}
			}

			// Update settings.
			$success = update_option( 'wppo_settings', $import_settings );

			if ( ! $success ) {
				return $this->send_error_response(
					'import_failed',
					'Failed to import settings to database.',
					500
				);
			}

			// Clear cache after import.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			}
			\PerformanceOptimise\Inc\Cache::clear_cache();

			// Log the change.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			}
			new \PerformanceOptimise\Inc\Log( 'Settings imported via API on ' . current_time( 'mysql' ) );

			return $this->send_success_response(
				array(
					'message'  => 'Settings imported successfully.',
					'settings' => $import_settings,
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to import settings' );
		}
	}

	/**
	 * Validate settings endpoint handler.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response Response object.
	 */
	public function validate_settings( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->log_request( $request, 'Validate Settings' );

			$validation = $this->validate_request(
				$request,
				array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
				)
			);

			if ( ! $validation['valid'] ) {
				return $this->send_validation_error_response( $validation['errors'] );
			}

			$settings          = $validation['data']['settings'];
			$validation_result = $this->validate_settings_data( $settings );

			return $this->send_success_response(
				array(
					'valid'    => $validation_result['valid'],
					'errors'   => $validation_result['errors'],
					'warnings' => $validation_result['warnings'] ?? array(),
				)
			);

		} catch ( \Exception $e ) {
			return $this->handle_exception( $e, 'Failed to validate settings' );
		}
	}

	/**
	 * Get settings schema for validation.
	 *
	 * @return array<string, array<string, mixed>> Settings schema.
	 */
	private function get_settings_schema(): array {
		return array(
			'cache_settings'     => array(
				'enablePageCaching'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'cacheExpiration'       => array(
					'type'    => 'integer',
					'default' => 3600,
					'min'     => 300,
				),
				'excludePages'          => array(
					'type'    => 'array',
					'default' => array(),
				),
				'cache_exclusions'      => array(
					'type'    => 'object',
					'default' => array(
						'urls'          => array(),
						'cookies'       => array( 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_' ),
						'user_roles'    => array(),
						'query_strings' => array(),
						'user_agents'   => array(),
						'post_types'    => array(),
					),
				),
				'enableGzipCompression' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'image_optimisation' => array(
				'lazyLoadImages'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'convertImg'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'format'            => array(
					'type'    => 'string',
					'default' => 'webp',
					'enum'    => array( 'webp', 'avif' ),
				),
				'quality'           => array(
					'type'    => 'integer',
					'default' => 85,
					'min'     => 1,
					'max'     => 100,
				),
				'preserve_exif'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'compression_level' => array(
					'type'    => 'integer',
					'default' => 6,
					'min'     => 0,
					'max'     => 9,
				),
				'exclude_by_class'  => array(
					'type'    => 'array',
					'default' => array( 'no-lazy', 'skip-lazy' ),
				),
				'exclude_by_id'     => array(
					'type'    => 'array',
					'default' => array(),
				),
				'exclude_by_ext'    => array(
					'type'    => 'array',
					'default' => array( 'svg', 'gif' ),
				),
			),
			'file_optimisation'  => array(
				'minifyHTML'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'minifyCSS'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'minifyJS'          => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'combineCSS'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'combineJS'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'exclude_css_files' => array(
					'type'    => 'array',
					'default' => array(),
				),
				'exclude_js_files'  => array(
					'type'    => 'array',
					'default' => array(),
				),
			),
			'preload_settings'   => array(
				'enablePreloadCache' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'enableCronJobs'     => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'preloadPages'       => array(
					'type'    => 'integer',
					'default' => 10,
					'min'     => 1,
					'max'     => 100,
				),
				'preload_fonts'      => array(
					'type'    => 'array',
					'default' => array(),
				),
				'preload_images'     => array(
					'type'    => 'array',
					'default' => array(),
				),
				'dns_prefetch'       => array(
					'type'    => 'array',
					'default' => array( 'fonts.googleapis.com', 'fonts.gstatic.com' ),
				),
				'preconnect'         => array(
					'type'    => 'array',
					'default' => array(),
				),
			),
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	private function get_default_settings(): array {
		$defaults = array();

		foreach ( $this->settings_schema as $group => $fields ) {
			$defaults[ $group ] = array();
			foreach ( $fields as $field => $config ) {
				$defaults[ $group ][ $field ] = $config['default'] ?? null;
			}
		}

		return $defaults;
	}

	/**
	 * Validate settings data against schema.
	 *
	 * @param array<string, mixed> $settings Settings to validate.
	 * @return array<string, mixed> Validation result.
	 */
	private function validate_settings_data( array $settings ): array {
		$errors   = array();
		$warnings = array();

		foreach ( $settings as $group => $group_settings ) {
			if ( ! isset( $this->settings_schema[ $group ] ) ) {
				$warnings[] = sprintf( 'Unknown setting group: %s', $group );
				continue;
			}

			$group_validation = $this->validate_setting_group( $group, $group_settings );
			$errors           = array_merge( $errors, $group_validation['errors'] );
			$warnings         = array_merge( $warnings, $group_validation['warnings'] );
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate specific setting group.
	 *
	 * @param string               $group Group name.
	 * @param array<string, mixed> $group_settings Group settings.
	 * @return array<string, mixed> Validation result.
	 */
	private function validate_setting_group( string $group, array $group_settings ): array {
		$errors   = array();
		$warnings = array();
		$schema   = $this->settings_schema[ $group ] ?? array();

		foreach ( $group_settings as $setting => $value ) {
			if ( ! isset( $schema[ $setting ] ) ) {
				$warnings[] = sprintf( 'Unknown setting: %s.%s', $group, $setting );
				continue;
			}

			$field_schema = $schema[ $setting ];
			$validation   = $this->validate_field_type( $value, $field_schema['type'], "{$group}.{$setting}" );

			if ( ! $validation['valid'] ) {
				$errors[] = $validation['error'];
				continue;
			}

			// Additional validations.
			if ( isset( $field_schema['min'] ) && is_numeric( $value ) && $value < $field_schema['min'] ) {
				$errors[] = sprintf( 'Setting %s.%s must be at least %s', $group, $setting, $field_schema['min'] );
			}

			if ( isset( $field_schema['max'] ) && is_numeric( $value ) && $value > $field_schema['max'] ) {
				$errors[] = sprintf( 'Setting %s.%s must be at most %s', $group, $setting, $field_schema['max'] );
			}

			if ( isset( $field_schema['enum'] ) && ! in_array( $value, $field_schema['enum'], true ) ) {
				$errors[] = sprintf(
					'Setting %s.%s must be one of: %s',
					$group,
					$setting,
					implode( ', ', $field_schema['enum'] )
				);
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Check if cache clearing is needed after settings change.
	 *
	 * @param array<string, mixed> $old_settings Old settings.
	 * @param array<string, mixed> $new_settings New settings.
	 * @return bool True if cache should be cleared.
	 */
	private function check_cache_clear_needed( array $old_settings, array $new_settings ): bool {
		// Settings that require cache clearing when changed.
		$cache_affecting_settings = array(
			'cache_settings.enablePageCaching',
			'file_optimisation.minifyCSS',
			'file_optimisation.minifyJS',
			'file_optimisation.minifyHTML',
			'file_optimisation.combineCSS',
			'file_optimisation.combineJS',
		);

		foreach ( $cache_affecting_settings as $setting_path ) {
			$path_parts = explode( '.', $setting_path );
			$group      = $path_parts[0];
			$setting    = $path_parts[1];

			$old_value = $old_settings[ $group ][ $setting ] ?? null;
			$new_value = $new_settings[ $group ][ $setting ] ?? null;

			if ( $old_value !== $new_value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if page cache setting changed.
	 *
	 * @param array<string, mixed> $old_settings Old settings.
	 * @param array<string, mixed> $new_settings New settings.
	 * @return bool True if page cache setting changed.
	 */
	private function check_page_cache_changed( array $old_settings, array $new_settings ): bool {
		$old_enabled = $old_settings['cache_settings']['page_cache_enabled'] ?? false;
		$new_enabled = $new_settings['cache_settings']['page_cache_enabled'] ?? false;

		return $old_enabled !== $new_enabled;
	}

	/**
	 * Check if browser cache setting changed.
	 *
	 * @param array<string, mixed> $old_settings Old settings.
	 * @param array<string, mixed> $new_settings New settings.
	 * @return bool True if browser cache setting changed.
	 */
	private function check_browser_cache_changed( array $old_settings, array $new_settings ): bool {
		$old_enabled = $old_settings['cache_settings']['browser_cache_enabled'] ?? false;
		$new_enabled = $new_settings['cache_settings']['browser_cache_enabled'] ?? false;

		return $old_enabled !== $new_enabled;
	}

	/**
	 * Manage browser cache .htaccess rules based on settings.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function manage_browser_cache( array $settings ): void {
		$browser_cache_enabled = $settings['cache_settings']['browser_cache_enabled'] ?? false;

		try {
			$container             = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
			$browser_cache_service = $container->get( 'PerformanceOptimisation\\Services\\BrowserCacheService' );

			if ( $browser_cache_enabled ) {
				$browser_cache_service->enable();
				\PerformanceOptimisation\Utils\LoggingUtil::info(
					'WPPO: Browser cache enabled, .htaccess rules added'
				);
			} else {
				$browser_cache_service->disable();
				\PerformanceOptimisation\Utils\LoggingUtil::info(
					'WPPO: Browser cache disabled, .htaccess rules removed'
				);
			}
		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error(
				'WPPO: Failed to manage browser cache: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Manage advanced-cache.php drop-in based on settings.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private function manage_advanced_cache_dropin( array $settings ): void {
		$page_cache_enabled = $settings['cache_settings']['page_cache_enabled'] ?? false;

		try {
			$container          = \PerformanceOptimisation\Core\ServiceContainer::getInstance();
			$page_cache_service = $container->get( 'PerformanceOptimisation\\Services\\PageCacheService' );

			if ( $page_cache_enabled ) {
				$page_cache_service->enable_cache();
				\PerformanceOptimisation\Utils\LoggingUtil::info(
					'WPPO: Page cache enabled, advanced-cache.php created'
				);
			} else {
				$page_cache_service->disable_cache();
				\PerformanceOptimisation\Utils\LoggingUtil::info(
					'WPPO: Page cache disabled, advanced-cache.php removed'
				);
			}
		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error(
				'WPPO: Failed to manage advanced-cache.php: ' . $e->getMessage()
			);
		}
	}
}
