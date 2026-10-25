<?php
/**
 * Plugin Name: WP DesignChecker
 * Description: A visual quality assurance and regression testing tool. Captures desktop and mobile screenshots, compare website layouts against approved baselines, detect unexpected design changes, and review visual differences.
 * Version: 0.1.0
 * Author: Favogab
 * Text Domain: wp-designchecker
 */

if (!defined('ABSPATH')) {
    exit;
}


require_once plugin_dir_path(__FILE__) . 'includes/class-api-client.php';

add_action('admin_menu', 'wp_designchecker_register_menu');
add_action('admin_post_wp_designchecker_scan', 'wp_designchecker_handle_scan');

function wp_designchecker_register_menu()
{
    add_menu_page(
        'WP DesignChecker',
        'DesignChecker',
        'manage_options',
        'wp-designchecker',
        'wp_designchecker_render_dashboard',
        'dashicons-visibility',
        65
    );
}

function wp_designchecker_render_dashboard()
{
    require plugin_dir_path(__FILE__) . 'admin/dashboard.php';
}


function wp_designchecker_handle_scan()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized.');
    }

    check_admin_referer('wp_designchecker_scan');

    $result = WP_DesignChecker_API_Client::run_scan();

    if (is_wp_error($result)) {
        $notice = $result->get_error_message();
    } elseif (!empty($result['scan_id'])) {
        update_user_meta(
            get_current_user_id(),
            'wp_designchecker_scan_id',
            sanitize_text_field($result['scan_id'])
        );

        $notice = 'Design check started. Refresh this page to view its progress.';
    } else {
        $notice = 'The scanning service did not return a scan ID.';
    }

    set_transient(
        'wp_designchecker_notice_' . get_current_user_id(),
        $notice,
        60
    );

    wp_safe_redirect(admin_url('admin.php?page=wp-designchecker'));
    exit;
}
