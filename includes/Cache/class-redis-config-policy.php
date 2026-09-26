<?php
/**
 * Redis configuration value policy shared by REST, CLI, and Object_Cache adapters.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Redis_Config_Policy' ) ) {
	/**
	 * Normalize and validate Redis connection configuration values.
	 *
	 * This boundary owns the complete Object_Cache key manifest and its value
	 * rules. It deliberately does not connect to Redis, persist configuration,
	 * publish drop-ins, or mutate Object_Cache lifecycle state.
	 *
	 * @since NEXT
	 */
	final class Redis_Config_Policy {

		/**
		 * Complete Redis configuration key manifest.
		 *
		 * Object_Cache::ALLOWED_KEYS aliases this list so existing callers keep
		 * the same public constant while REST and CLI share this value owner.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const ALLOWED_KEYS = array( 'mode', 'host', 'port', 'password', 'database', 'timeout', 'prefix', 'nodes', 'master_name', 'use_tls', 'persistent', 'compression' );

		/**
		 * Build a sanitized Redis configuration from values and optional defaults.
		 *
		 * Explicit values win over defaults. Only the complete key manifest is
		 * considered, and every accepted value passes through sanitize_value().
		 * Historic standalone/loopback defaults are applied last.
		 *
		 * @since NEXT
		 * @param array $values  Request or adapter values.
		 * @param array $defaults Optional values used for omitted keys.
		 * @return array<string,mixed> Sanitized Redis connection configuration.
		 */
		public static function build( array $values, array $defaults = array() ): array {
			$config = array();

			foreach ( self::ALLOWED_KEYS as $key ) {
				if ( isset( $values[ $key ] ) ) {
					$config[ $key ] = self::sanitize_value( $key, $values[ $key ] );
				}
			}

			foreach ( self::ALLOWED_KEYS as $key ) {
				if ( ! array_key_exists( $key, $config ) && array_key_exists( $key, $defaults ) ) {
					$config[ $key ] = self::sanitize_value( $key, $defaults[ $key ] );
				}
			}

			$config['mode'] = $config['mode'] ?? 'standalone';
			$config['host'] = $config['host'] ?? '127.0.0.1';
			$config['port'] = $config['port'] ?? 6379;

			return $config;
		}

		/**
		 * Sanitize one Redis configuration value according to its key contract.
		 *
		 * @since NEXT
		 * @param string $key   Redis configuration key.
		 * @param mixed  $value Raw value.
		 * @return mixed Sanitized value.
		 */
		public static function sanitize_value( string $key, $value ) {
			switch ( $key ) {
				case 'host':
				case 'master_name':
					$host = strtolower( trim( sanitize_text_field( (string) $value ) ) );
					if ( '' !== $host && 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-\.]{0,251}[a-z0-9])?|\/[\w\/\.\-]+)$/', $host ) ) {
						return '';
					}
					return substr( $host, 0, 255 );

				case 'compression':
					$compression = sanitize_text_field( (string) $value );
					return in_array( $compression, array( '', 'none', 'lz4', 'zstd' ), true ) ? $compression : '';

				case 'mode':
					$mode = sanitize_text_field( (string) $value );
					return in_array( $mode, array( 'standalone', 'sentinel', 'cluster' ), true ) ? $mode : 'standalone';

				case 'port':
					return max( 1, min( 65535, (int) $value ) );

				case 'database':
					return max( 0, min( 15, (int) $value ) );

				case 'timeout':
					if ( is_array( $value ) || is_object( $value ) ) {
						return 1.0;
					}
					return max( 0.1, min( 30.0, (float) $value ) );

				case 'prefix':
					if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
						return '';
					}
					$prefix = sanitize_text_field( (string) $value );
					$prefix = (string) preg_replace( '/[^A-Za-z0-9_\-:]/', '', $prefix );
					return substr( $prefix, 0, 64 );

				case 'password':
					if ( defined( 'WPPO_REDIS_PASSWORD' ) && ! apply_filters( 'wppo_redis_allow_request_password', false ) ) {
						return '';
					}
					if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
						return '';
					}
					return substr( (string) $value, 0, 512 );

				case 'use_tls':
				case 'persistent':
					return (bool) $value;

				case 'nodes':
					return self::sanitize_nodes( $value );

				default:
					return $value;
			}
		}

		/**
		 * Normalize Redis nodes into an indexed array of safe, non-empty strings.
		 *
		 * Scalar and array inputs share one node validator. Schemes, userinfo,
		 * unsafe host forms, and out-of-range ports fail closed instead of being
		 * rewritten into a different connection target.
		 *
		 * @since NEXT
		 * @param mixed $nodes Scalar node, node list, or unsupported value.
		 * @return string[] Indexed, sanitized node list.
		 */
		public static function sanitize_nodes( $nodes ): array {
			$sanitize_node = static function ( $node ): string {
				if ( ! is_string( $node ) && ! is_numeric( $node ) ) {
					return '';
				}

				$candidate = strtolower( trim( sanitize_text_field( (string) $node ) ) );
				if ( '' === $candidate || false !== strpos( $candidate, '://' ) || false !== strpos( $candidate, '@' ) ) {
					return '';
				}

				if ( 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-\.]{0,251}[a-z0-9])?|\/[\w\/\.\-]+)(?::([0-9]{1,5}))?$/', $candidate, $matches ) ) {
					return '';
				}

				if ( isset( $matches[1] ) ) {
					$port = (int) $matches[1];
					if ( $port < 1 || $port > 65535 ) {
						return '';
					}
				}

				return substr( $candidate, 0, 255 );
			};

			if ( is_array( $nodes ) ) {
				return array_values( array_filter( array_map( $sanitize_node, $nodes ) ) );
			}

			$single = $sanitize_node( $nodes );
			return '' !== $single ? array( $single ) : array();
		}
	}
}
