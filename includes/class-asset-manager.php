<?php
/**
 * Asset Manager functionality.
 *
 * Provides per-page/post control over which scripts and styles are loaded
 * on the frontend. Admins can selectively disable assets from the post
 * editor meta box.
 *
 * @package PerformanceOptimise
 * @since   1.1.0
 */

namespace PerformanceOptimise\Inc;

if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Class Asset_Manager
 *
 * Handles capturing of enqueued assets and dequeuing disabled ones
 * based on per-post meta data.
 *
 * @since 1.1.0
 */
class Asset_Manager
{

    /**
     * Core WordPress script handles that should never be deregistered.
     *
     * @var   array
     * @since 1.1.0
     */
    private static $protected_scripts = array(
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
    private static $protected_styles = array(
    'admin-bar',
    'dashicons',
    'wp-block-library',
    );

    /**
     * Constructor.
     *
     * Registers the hooks for asset dequeuing and capturing.
     *
     * @since 1.1.0
     */
    public function __construct()
    {
        // Dequeue disabled assets on the frontend at a very late priority.
        add_action('wp_enqueue_scripts', array( $this, 'dequeue_selected_assets' ), 9999);

        // Capture all enqueued assets on the frontend for the admin meta box.
        add_action('wp_footer', array( $this, 'capture_page_assets' ), PHP_INT_MAX);
    }

    /**
     * Dequeue and deregister assets that the admin has disabled for this post.
     *
     * Only runs on the frontend, not in the admin area.
     *
     * @since 1.1.0
     */
    public function dequeue_selected_assets()
    {
        if (is_admin() || is_user_logged_in() ) {
            return;
        }

        $post_id = get_the_ID();
        if (! $post_id ) {
            return;
        }

        $disabled_scripts = get_post_meta($post_id, '_wppo_disabled_scripts', true);
        $disabled_styles  = get_post_meta($post_id, '_wppo_disabled_styles', true);

        if (! empty($disabled_scripts) && is_array($disabled_scripts) ) {
            foreach ( $disabled_scripts as $handle ) {
                if (! in_array($handle, self::$protected_scripts, true) ) {
                    wp_dequeue_script($handle);
                    wp_deregister_script($handle);
                }
            }
        }

        if (! empty($disabled_styles) && is_array($disabled_styles) ) {
            foreach ( $disabled_styles as $handle ) {
                if (! in_array($handle, self::$protected_styles, true) ) {
                    wp_dequeue_style($handle);
                    wp_deregister_style($handle);
                }
            }
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
    public function capture_page_assets()
    {
        if (is_admin() ) {
            return;
        }

        $post_id = get_the_ID();
        if (! $post_id ) {
            return;
        }

        global $wp_scripts, $wp_styles;

        $scripts = array();
        $styles  = array();

        if ($wp_scripts instanceof \WP_Scripts ) {
            foreach ( $wp_scripts->done as $handle ) {
                if (isset($wp_scripts->registered[ $handle ]) ) {
                    $registered = $wp_scripts->registered[ $handle ];
                    $scripts[]  = array(
                     'handle' => $handle,
                     'src'    => $registered->src ? $registered->src : '',
                     'deps'   => $registered->deps,
                    );
                }
            }
        }

        if ($wp_styles instanceof \WP_Styles ) {
            foreach ( $wp_styles->done as $handle ) {
                if (isset($wp_styles->registered[ $handle ]) ) {
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

        $existing_assets = self::get_page_assets($post_id);
        $has_changed     = true;

        if (is_array($existing_assets) && isset($existing_assets['scripts'], $existing_assets['styles']) ) {
            if ($existing_assets['scripts'] === $scripts && $existing_assets['styles'] === $styles ) {
                $has_changed = false;
            }
        }

        if ($has_changed ) {
            // Store for 24 hours, keyed by post ID.
            set_transient('wppo_page_assets_' . $post_id, $assets, DAY_IN_SECONDS);
        }
    }

    /**
     * Get captured assets for a specific post.
     *
     * @param  int $post_id The post ID to get assets for.
     * @since  1.1.0
     * @return array|false The captured assets array, or false if not found.
     */
    public static function get_page_assets( $post_id )
    {
        return get_transient('wppo_page_assets_' . $post_id);
    }

    /**
     * Get the list of protected script handles.
     *
     * @since  1.1.0
     * @return array List of protected script handles.
     */
    public static function get_protected_scripts()
    {
        return self::$protected_scripts;
    }

    /**
     * Get the list of protected style handles.
     *
     * @since  1.1.0
     * @return array List of protected style handles.
     */
    public static function get_protected_styles()
    {
        return self::$protected_styles;
    }
}
