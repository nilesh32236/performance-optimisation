<?php
/**
 * Asset Manager functionality.
 *
 * Provides per-page/post control over which scripts and styles are loaded
 * on the frontend. Admins can selectively disable assets from the post
 * editor meta box.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.1.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Asset_Manager' ) ) {
	/**
	 * Class Asset_Manager
	 *
	 * Handles capturing of enqueued assets and dequeuing disabled ones
	 * based on per-post meta data.
	 *
	 * @since 1.1.0
	 */
	class Asset_Manager {
		/**
		 * Prefix for the per-page asset transient key.
		 *
		 * @var   string
		 * @since 1.1.0
		 */
		const TRANSIENT_PREFIX = 'wppo_page_assets_';

		/**
		 * Core WordPress script handles that should never be deregistered.
		 *
		 * @var   array
		 * @since 1.1.0
		 */
		private static array $protected_scripts = array(
			'jquery',
			'jquery-core',
			'jquery-migrate',
			'wp-i18n',
			'wp-hooks',
			'wp-api-fetch',
			'wp-url',
			'wp-polyfill',
			'admin-bar',
			'heartbeat',
		);

		/**
		 * Core WordPress style handles that should never be deregistered.
		 *
		 * @var   array
		 * @since 1.1.0
		 */
		private static array $protected_styles = array(
			'admin-bar',
			'dashicons',
			'wp-block-library',
		);

		/**
		 * WooCommerce cart/checkout script handles that should never be dequeued.
		 *
		 * Cart fragments and checkout runtimes are enqueued site-wide by Woo
		 * and break guest carts when removed; the commerce-context bypass
		 * below additionally skips every dequeue on cart/checkout/account
		 * pages, so this list only guards non-commerce pages.
		 *
		 * @var   array
		 * @since NEXT
		 */
		private static array $commerce_scripts = array(
			'wc-cart-fragments',
			'wc_cart_fragments',
			'cart-fragments',
			'wc-add-to-cart',
			'wc-checkout',
		);

		/**
		 * Constructor.
		 *
		 * Registers the hooks for asset dequeuing and capturing.
		 *
		 * @since 1.1.0
		 */
		public function __construct() {
			// Capture assets after they've been printed so $done arrays are populated.
			add_action( 'wp_footer', array( $this, 'capture_page_assets' ), 9999 );

			// Dequeue disabled assets on the frontend at a very late priority.
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_selected_assets' ), 9999 );
		}

		/**
		 * Dequeue and deregister assets that the admin has disabled for this post.
		 *
		 * Only runs on the frontend, not in the admin area. Gated by the
		 * `file_optimisation.assetManagerEnabled` kill-switch (default OFF),
		 * refused for handles with active dependents (dependency guard), and
		 * skipped entirely on WooCommerce cart/checkout/account contexts.
		 * Fail-open by design: any guard refusal keeps assets enqueued
		 * (current behavior), never fatal.
		 *
		 * @since 1.1.0
		 * @since NEXT Added global gate, dependency guard, and commerce bypass.
		 */
		public function dequeue_selected_assets(): void {
			// Audit #1392: typed per convention.
			$is_sandbox_preview = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_sandbox_preview_active' ) ) {
					$is_sandbox_preview = (bool) Main::is_sandbox_preview_active();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$is_sandbox_preview = false;
			}
			if ( is_admin() ) {
				return;
			}
			// Sandbox preview (issue #1163): preview admins render staged
			// dequeue output; every other logged-in user keeps production
			// markup so visitors never see experimental output.
			if ( is_user_logged_in() && ! $is_sandbox_preview ) {
				return;
			}

			if ( ! is_singular() ) {
				return;
			}

			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return;
			}

			// Global kill-switch (issue #1406): default OFF. Absent or
			// malformed settings fail closed to OFF (assets stay enqueued).
			if ( ! self::is_enabled() ) {
				return;
			}

			// Commerce bypass (issue #1406): cart/checkout/account pages are
			// never stripped; the Woo self-test must keep passing.
			if ( self::is_commerce_context() ) {
				return;
			}

			$disabled_scripts = get_post_meta( $post_id, '_wppo_disabled_scripts', true );
			$disabled_styles  = get_post_meta( $post_id, '_wppo_disabled_styles', true );

			if ( ! is_array( $disabled_scripts ) ) {
				$disabled_scripts = array();
			}
			if ( ! is_array( $disabled_styles ) ) {
				$disabled_styles = array();
			}
			if ( empty( $disabled_scripts ) && empty( $disabled_styles ) ) {
				return;
			}

			global $wp_scripts, $wp_styles;

			$extra_allowlist = self::get_extra_allowlist();

			$safe_scripts = self::filter_safe_handles( $disabled_scripts, self::registered_map( $wp_scripts ), self::queue_list( $wp_scripts ), $extra_allowlist );
			foreach ( $safe_scripts as $handle ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}

			$safe_styles = self::filter_safe_handles( $disabled_styles, self::registered_map( $wp_styles ), self::queue_list( $wp_styles ), $extra_allowlist );
			foreach ( $safe_styles as $handle ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}

		/**
		 * Alias for dequeue_selected_assets() (issue #1406 naming parity).
		 *
		 * The proposed `maybe_dequeue_disabled()` dequeue entry point is this
		 * hardened method: late dequeue with dependency guard, commerce
		 * bypass, and global gate.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function maybe_dequeue_disabled(): void {
			$this->dequeue_selected_assets();
		}

		/**
		 * Whether the per-page Script & Style Manager is enabled.
		 *
		 * Reads `file_optimisation.assetManagerEnabled` via Util::get_settings().
		 * Fail-closed to false (manager off): any detection failure keeps
		 * assets enqueued. Multisite-safe: per-site settings only.
		 *
		 * @since NEXT
		 * @param array|null $settings Optional settings array (defaults to Util::get_settings()).
		 * @return bool True when the manager gate is open.
		 */
		public static function is_enabled( ?array $settings = null ): bool {
			try {
				if ( null === $settings ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
						return false;
					}
					$settings = Util::get_settings();
				}
				if ( ! is_array( $settings ) || ! isset( $settings['file_optimisation'] ) || ! is_array( $settings['file_optimisation'] ) ) {
					return false;
				}
				$value = $settings['file_optimisation']['assetManagerEnabled'] ?? false;
				if ( is_bool( $value ) ) {
					return $value;
				}
				if ( function_exists( 'filter_var' ) ) {
					$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					return null === $parsed ? false : (bool) $parsed;
				}
				return ! empty( $value );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Extra never-strip handles from settings (one per line).
		 *
		 * Parses `file_optimisation.assetManagerAllowlistExtra` into a clean
		 * unique handle list. Fail-open: any failure yields an empty list
		 * (the built-in protected + commerce allowlists still apply).
		 *
		 * @since NEXT
		 * @param array|null $settings Optional settings array (defaults to Util::get_settings()).
		 * @return string[] Extra allowlisted handles.
		 */
		public static function get_extra_allowlist( ?array $settings = null ): array {
			try {
				if ( null === $settings ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
						return array();
					}
					$settings = Util::get_settings();
				}
				if ( ! is_array( $settings ) || ! isset( $settings['file_optimisation'] ) || ! is_array( $settings['file_optimisation'] ) ) {
					return array();
				}
				$raw = $settings['file_optimisation']['assetManagerAllowlistExtra'] ?? '';
				if ( is_array( $raw ) ) {
					$lines = array_values( $raw );
				} elseif ( is_scalar( $raw ) || null === $raw ) {
					$lines = preg_split( '/[\r\n,]+/', (string) $raw );
					if ( ! is_array( $lines ) ) {
						return array();
					}
				} else {
					return array();
				}
				$handles = array();
				foreach ( $lines as $line ) {
					if ( ! is_scalar( $line ) ) {
						continue;
					}
					$handle = trim( (string) $line );
					if ( '' === $handle || in_array( $handle, $handles, true ) ) {
						continue;
					}
					if ( function_exists( 'sanitize_key' ) ) {
						$clean = sanitize_key( $handle );
					} else {
						$clean = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $handle ) );
					}
					if ( '' !== $clean && ! in_array( $clean, $handles, true ) ) {
						$handles[] = $clean;
					}
				}
				return $handles;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether the current request is a WooCommerce dynamic context.
		 *
		 * True on cart/checkout/account pages (conditional tags, guarded by
		 * function_exists()) or on Woo dynamic paths (Util::is_woo_dynamic_path,
		 * guarded by class_exists()/method_exists()). Fail-safe: any detection
		 * failure returns true (treated as commerce, dequeues skipped).
		 *
		 * @since NEXT
		 * @return bool True when dequeues must be skipped.
		 */
		public static function is_commerce_context(): bool {
			try {
				if ( function_exists( 'is_cart' ) ) {
					try {
						if ( is_cart() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'is_checkout' ) ) {
					try {
						if ( is_checkout() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'is_account_page' ) ) {
					try {
						if ( is_account_page() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_dynamic_path' ) ) {
					$path        = '/';
					$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
					if ( '' !== $request_uri ) {
						if ( function_exists( 'wp_parse_url' ) ) {
							$parsed = wp_parse_url( $request_uri, PHP_URL_PATH );
							if ( is_string( $parsed ) && '' !== $parsed ) {
								$path = $parsed;
							}
						} else {
							$parts = explode( '?', $request_uri, 2 );
							if ( isset( $parts[0] ) && '' !== $parts[0] ) {
								$path = $parts[0];
							}
						}
					}
					return Util::is_woo_dynamic_path( $path );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Normalize a WP_Scripts/WP_Styles registry into handle => deps.
		 *
		 * Pure extraction helper so the dependency guard never touches
		 * globals directly outside dequeue_selected_assets(). Fail-open:
		 * non-object registries yield an empty map.
		 *
		 * @since NEXT
		 * @param mixed $registry WP_Scripts/WP_Styles instance or null.
		 * @return array<string, string[]> Map of handle => dependency handles.
		 */
		public static function registered_map( $registry ): array {
			try {
				if ( ! is_object( $registry ) || ! isset( $registry->registered ) || ! is_array( $registry->registered ) ) {
					return array();
				}
				$map = array();
				foreach ( $registry->registered as $handle => $item ) {
					if ( ! is_scalar( $handle ) ) {
						continue;
					}
					$deps = array();
					if ( is_object( $item ) && isset( $item->deps ) && is_array( $item->deps ) ) {
						foreach ( $item->deps as $dep ) {
							if ( is_string( $dep ) && '' !== $dep ) {
								$deps[] = $dep;
							}
						}
					}
					$map[ (string) $handle ] = $deps;
				}
				return $map;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Active (queued-to-print) handles from a WP_Scripts/WP_Styles registry.
		 *
		 * Reads `->queue` (populated at `wp_enqueue_scripts`, before print).
		 * Fail-open: an unreadable queue yields every registered handle as
		 * active (most conservative: the guard refuses more, never less).
		 *
		 * @since NEXT
		 * @param mixed $registry WP_Scripts/WP_Styles instance or null.
		 * @return string[] Active handles.
		 */
		public static function queue_list( $registry ): array {
			try {
				$registered = self::registered_map( $registry );
				if ( ! is_object( $registry ) || ! isset( $registry->queue ) || ! is_array( $registry->queue ) ) {
					return array_keys( $registered );
				}
				$queue = array();
				foreach ( $registry->queue as $handle ) {
					if ( is_string( $handle ) && '' !== $handle ) {
						$queue[] = $handle;
					}
				}
				return $queue;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Filter disabled handles down to those safe to dequeue.
		 *
		 * A handle is refused (kept enqueued) when it is protected
		 * (core/admin-bar), a commerce fragment, extra-allowlisted, or has an
		 * active dependent: any active handle depending on it — directly or
		 * transitively — that is not itself also disabled. Disabling a whole
		 * dependent subtree at once is allowed.
		 *
		 * Pure helper (no I/O, no globals): fully unit-testable.
		 *
		 * @since NEXT
		 * @param string[]               $disabled   Requested disabled handles.
		 * @param array<string,string[]> $registered Map of handle => deps.
		 * @param string[]               $active     Handles queued to print.
		 * @param string[]               $extra      Extra never-strip handles.
		 * @return string[] Safe-to-dequeue handles (order-preserving, unique).
		 */
		public static function filter_safe_handles( array $disabled, array $registered, array $active, array $extra = array() ): array {
			try {
				$disabled_set = array();
				foreach ( $disabled as $handle ) {
					if ( is_string( $handle ) && '' !== $handle && ! in_array( $handle, $disabled_set, true ) ) {
						$disabled_set[] = $handle;
					}
				}
				if ( empty( $disabled_set ) ) {
					return array();
				}
				$never_strip = array_merge( self::$protected_scripts, self::$protected_styles, self::$commerce_scripts, $extra );

				// Reverse map: dependency => handles depending on it.
				$dependents = array();
				foreach ( $registered as $handle => $deps ) {
					if ( ! is_string( $handle ) || ! is_array( $deps ) ) {
						continue;
					}
					foreach ( $deps as $dep ) {
						if ( ! is_string( $dep ) || '' === $dep ) {
							continue;
						}
						if ( ! isset( $dependents[ $dep ] ) ) {
							$dependents[ $dep ] = array();
						}
						if ( ! in_array( $handle, $dependents[ $dep ], true ) ) {
							$dependents[ $dep ][] = $handle;
						}
					}
				}

				$active_set = array();
				foreach ( $active as $handle ) {
					if ( is_string( $handle ) && '' !== $handle ) {
						$active_set[ $handle ] = true;
					}
				}

				$safe = array();
				foreach ( $disabled_set as $handle ) {
					if ( in_array( $handle, $never_strip, true ) ) {
						continue;
					}
					if ( self::has_active_dependent( $handle, $dependents, $active_set, $disabled_set ) ) {
						continue;
					}
					$safe[] = $handle;
				}
				return $safe;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether a handle has an active dependent outside the disabled set.
		 *
		 * Breadth-first walk over the reverse-dependency graph: any reached
		 * handle that is active and not itself disabled blocks the dequeue.
		 * Disabled dependents are walked through (whole-subtree disables are
		 * allowed). Fail-safe: walk failure returns true (refuse).
		 *
		 * @since NEXT
		 * @param string   $handle     Handle under test.
		 * @param array    $dependents Reverse map (dependency => dependents).
		 * @param bool[]   $active_set Active handles as a set.
		 * @param string[] $disabled_set Disabled handles.
		 * @return bool True when an active non-disabled dependent exists.
		 */
		private static function has_active_dependent( string $handle, array $dependents, array $active_set, array $disabled_set ): bool {
			try {
				$disabled_lookup = array();
				foreach ( $disabled_set as $disabled ) {
					$disabled_lookup[ $disabled ] = true;
				}
				$visited = array( $handle => true );
				$queue   = isset( $dependents[ $handle ] ) && is_array( $dependents[ $handle ] ) ? array_values( $dependents[ $handle ] ) : array();
				$steps   = 0;
				while ( ! empty( $queue ) ) {
					++$steps;
					if ( $steps > 10000 ) {
						return true;
					}
					$current = array_shift( $queue );
					if ( ! is_string( $current ) || '' === $current || isset( $visited[ $current ] ) ) {
						continue;
					}
					$visited[ $current ] = true;
					if ( isset( $active_set[ $current ] ) && ! isset( $disabled_lookup[ $current ] ) ) {
						return true;
					}
					if ( isset( $dependents[ $current ] ) && is_array( $dependents[ $current ] ) ) {
						foreach ( $dependents[ $current ] as $next ) {
							if ( is_string( $next ) && '' !== $next && ! isset( $visited[ $next ] ) ) {
								$queue[] = $next;
							}
						}
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Capture all enqueued scripts and styles on the current page.
		 *
		 * Stores the list as a transient keyed by post ID so the admin meta box
		 * can display them.
		 *
		 * @since 1.1.0
		 */
		public function capture_page_assets(): void {
			// Audit #1392: typed per convention.
			if ( is_admin() ) {
				return;
			}

			if ( ! is_singular() ) {
				return;
			}

			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return;
			}

			global $wp_scripts, $wp_styles;

			$scripts = array();
			$styles  = array();

			if ( $wp_scripts instanceof \WP_Scripts ) {
				$per_page_strategies = get_post_meta( $post_id, '_wppo_delay_strategies', true );
				$per_page_priorities = get_post_meta( $post_id, '_wppo_delay_priorities', true );

				if ( ! is_array( $per_page_strategies ) ) {
					$per_page_strategies = array();
				}
				if ( ! is_array( $per_page_priorities ) ) {
					$per_page_priorities = array();
				}

				foreach ( $wp_scripts->done as $handle ) {
					if ( isset( $wp_scripts->registered[ $handle ] ) ) {
						$registered = $wp_scripts->registered[ $handle ];
						$scripts[]  = array(
							'handle'         => $handle,
							'src'            => $registered->src ? $registered->src : '',
							'deps'           => $registered->deps,
							'delay_strategy' => $per_page_strategies[ $handle ] ?? null,
							'delay_priority' => $per_page_priorities[ $handle ] ?? null,
						);
					}
				}
			}

			if ( $wp_styles instanceof \WP_Styles ) {
				foreach ( $wp_styles->done as $handle ) {
					if ( isset( $wp_styles->registered[ $handle ] ) ) {
						$registered = $wp_styles->registered[ $handle ];
						$styles[]   = array(
							'handle' => $handle,
							'src'    => $registered->src ? $registered->src : '',
							'deps'   => $registered->deps,
						);
					}
				}
			}

			$assets = array(
				'scripts'   => $scripts,
				'styles'    => $styles,
				'timestamp' => time(),
			);

			$existing_assets = self::get_page_assets( $post_id );
			$has_changed     = true;

			if ( is_array( $existing_assets ) && isset( $existing_assets['scripts'], $existing_assets['styles'] ) ) {
				if ( $existing_assets['scripts'] === $scripts && $existing_assets['styles'] === $styles ) {
					$has_changed = false;
				}
			}

			if ( $has_changed ) {
				// Store for 24 hours, keyed by post ID.
				set_transient( Util::transient_key( self::TRANSIENT_PREFIX . $post_id ), $assets, DAY_IN_SECONDS );
			}
		}

		/**
		 * Get captured assets for a specific post.
		 *
		 * @param  int $post_id The post ID to get assets for.
		 * @since  1.1.0
		 * @return array|false The captured assets array, or false if not found.
		 */
		public static function get_page_assets( $post_id ) {
			return get_transient( Util::transient_key( self::TRANSIENT_PREFIX . $post_id ) );
		}

		/**
		 * Get the list of protected script handles.
		 *
		 * @since  1.1.0
		 * @return array List of protected script handles.
		 */
		public static function get_protected_scripts() {
			return self::$protected_scripts;
		}

		/**
		 * Get the list of protected style handles.
		 *
		 * @since  1.1.0
		 * @return array List of protected style handles.
		 */
		public static function get_protected_styles() {
			return self::$protected_styles;
		}

		/**
		 * Get the list of WooCommerce commerce handles that are never dequeued.
		 *
		 * @since NEXT
		 * @return array List of commerce script handles.
		 */
		public static function get_commerce_handles() {
			return self::$commerce_scripts;
		}
	}
}
