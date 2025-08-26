<?php
/**
 * Settings Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\SettingsServiceInterface;
use PerformanceOptimisation\Utils\ValidationUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsService
 *
 * @package PerformanceOptimisation\Services
 */
class SettingsService implements SettingsServiceInterface {

	private const OPTION_NAME = 'wppo_settings';

	private array $settings;

	public function __construct() {
		$this->settings = get_option( self::OPTION_NAME, $this->get_default_settings() );
	}

	public function get_settings(): array {
		return $this->settings;
	}

	public function update_settings( array $new_settings ): bool {
		$validated_settings = $this->validate_settings( $new_settings );
		$this->settings     = array_replace_recursive( $this->settings, $validated_settings );
		return update_option( self::OPTION_NAME, $this->settings );
	}

	public function get_setting( string $group, string $key ) {
		return $this->settings[ $group ][ $key ] ?? null;
	}

	public function update_setting( string $group, string $key, $value ): bool {
		$this->settings[ $group ][ $key ] = ValidationUtil::sanitize_setting( $value, $this->get_setting_type( $group, $key ) );
		return update_option( self::OPTION_NAME, $this->settings );
	}

	private function validate_settings( array $settings ): array {
		$validated = [];
		foreach ( $settings as $group => $group_settings ) {
			foreach ( $group_settings as $key => $value ) {
				$validated[ $group ][ $key ] = ValidationUtil::sanitize_setting( $value, $this->get_setting_type( $group, $key ) );
			}
		}
		return $validated;
	}

	private function get_setting_type( string $group, string $key ): string {
		$types = [
			'file_optimisation' => [
				'minifyCss'         => 'bool',
				'combineCss'        => 'bool',
				'minifyJs'          => 'bool',
				'combineJs'         => 'bool',
				'minifyHtml'        => 'bool',
				'excludeCombineCSS' => 'url_list',
				'excludeCombineJS'  => 'url_list',
			],
			'image_optimisation' => [
				'webp_conversion' => 'bool',
				'avif_conversion' => 'bool',
				'quality'         => 'int',
				'lazy_loading'    => 'bool',
			],
			'preload_settings' => [
				'enablePreloadCache'  => 'bool',
				'excludePreloadCache' => 'url_list',
			],
		];

		return $types[ $group ][ $key ] ?? 'string';
	}

	private function get_default_settings(): array {
		return [
			'file_optimisation' => [
				'minifyCss'         => false,
				'combineCss'        => false,
				'minifyJs'          => false,
				'combineJs'         => false,
				'minifyHtml'        => false,
				'excludeCombineCSS' => '',
				'excludeCombineJS'  => '',
			],
			'image_optimisation' => [
				'webp_conversion' => false,
				'avif_conversion' => false,
				'quality'         => 82,
				'lazy_loading'    => false,
			],
			'preload_settings' => [
				'enablePreloadCache'  => false,
				'excludePreloadCache' => '',
			],
		];
	}
}
