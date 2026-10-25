<?php

/**
 * Plugin Name: WP DesignChecker
 * Description: A visual quality assurance and regression testing tool for WordPress websites.
 * Version: 0.1.0
 * Author: Favogab
 * Text Domain: wp-designchecker
 *
 * WP DesignChecker connects WordPress to a Python-based
 * visual regression testing engine.
 *
 * Features:
 * - Automated visual regression scans
 * - Desktop, tablet, and mobile screenshot comparison
 * - Scan progress monitoring
 * - Scan history
 * - Latest screenshot review
 * - Historical screenshot retrieval
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the authenticated Python API client.
require_once plugin_dir_path(__FILE__) . 'includes/class-api-client.php';


/**
 * Register WordPress administration hooks.
 *
 * These hooks initialize the dashboard, scan handling,
 * screenshot endpoints, and dashboard assets.
 */
add_action('admin_menu', 'wp_designchecker_register_menu');

add_action(
    'admin_post_wp_designchecker_scan',
    'wp_designchecker_handle_scan'
);

add_action(
    'wp_ajax_wp_designchecker_image',
    'wp_designchecker_handle_image'
);

add_action(
    'wp_ajax_wp_designchecker_historical_image',
    'wp_designchecker_handle_historical_image'
);

add_action(
    'admin_enqueue_scripts',
    'wp_designchecker_enqueue_assets'
);


/**
 * Register the WP DesignChecker administration menu.
 *
 * Creates a top-level WordPress dashboard menu accessible
 * only to administrators with the manage_options capability.
 *
 * @return void
 */
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


/**
 * Render the WP DesignChecker administration dashboard.
 *
 * Loads the dashboard template containing scan controls,
 * visual results, screenshot review, and scan history.
 *
 * @return void
 */
function wp_designchecker_render_dashboard()
{
    // Enforce permissions before rendering the dashboard.
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized.');
    }

    require plugin_dir_path(__FILE__) . 'admin/dashboard.php';
}


/**
 * Handle a new visual regression scan request.
 *
 * Validates the administrator's permissions and request nonce,
 * starts a background scan through the Python API, and stores
 * the scan identifier for progress monitoring.
 *
 * @return void
 */
function wp_designchecker_handle_scan()
{
    // Restrict scan creation to authorized administrators.
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized.');
    }

    // Protect the scan action against CSRF.
    check_admin_referer('wp_designchecker_scan');

    // Request a new scan from the Python service.
    $result = WP_DesignChecker_API_Client::run_scan();

    $notice = '';

    if (is_wp_error($result)) {

        // Display an error if the Python service rejects the request.
        $notice = $result->get_error_message();

    } elseif (!empty($result['scan_id'])) {

        // Store the scan ID for the current administrator.
        update_user_meta(
            get_current_user_id(),
            'wp_designchecker_scan_id',
            sanitize_text_field($result['scan_id'])
        );

        // Successful scans are monitored automatically by dashboard.js.
        // No manual-refresh notification is necessary.

    } else {

        // Handle unexpected API responses without a scan identifier.
        $notice = 'The scanning service did not return a scan ID.';
    }

    // Save error notifications temporarily for the dashboard.
    if ($notice !== '') {
        set_transient(
            'wp_designchecker_notice_' . get_current_user_id(),
            $notice,
            60
        );
    }

    // Return the administrator to the DesignChecker dashboard.
    wp_safe_redirect(
        admin_url('admin.php?page=wp-designchecker')
    );

    exit;
}


/**
 * Serve the latest screenshot through WordPress AJAX.
 *
 * Retrieves a baseline, current screenshot, or difference
 * image from the Python service.
 *
 * The endpoint is protected by administrator permissions
 * and a WordPress nonce.
 *
 * @return void
 */
function wp_designchecker_handle_image()
{
    // Verify that the current user can manage plugin settings.
    if (!current_user_can('manage_options')) {
        status_header(403);
        exit;
    }

    // Validate the screenshot request nonce.
    check_ajax_referer(
        'wp_designchecker_image',
        'nonce'
    );

    // Retrieve the requested screenshot type.
    $mode = isset($_GET['mode'])
        ? sanitize_key(wp_unslash($_GET['mode']))
        : '';

    // Retrieve the requested device viewport.
    $viewport = isset($_GET['viewport'])
        ? sanitize_key(wp_unslash($_GET['viewport']))
        : '';

    // Fetch the screenshot through the authenticated API client.
    $image = WP_DesignChecker_API_Client::get_screenshot(
        $mode,
        $viewport
    );

    // Handle unavailable or invalid screenshot responses.
    if (is_wp_error($image)) {
        status_header(404);
        header('Content-Type: text/plain; charset=utf-8');

        echo esc_html($image->get_error_message());
        exit;
    }

    // Return the PNG without allowing browser caching.
    wp_designchecker_send_png($image);
}


/**
 * Serve an archived screenshot for a specific scan.
 *
 * Unlike the latest screenshot endpoint, this function
 * retrieves an image belonging to an individual historical
 * scan using its unique scan identifier.
 *
 * Supported screenshot types:
 * - baselines
 * - current
 * - diffs
 *
 * Supported viewports:
 * - desktop
 * - tablet
 * - mobile
 *
 * @return void
 */
function wp_designchecker_handle_historical_image()
{
    // Restrict historical screenshots to authorized administrators.
    if (!current_user_can('manage_options')) {
        status_header(403);
        exit;
    }

    // Validate the historical screenshot request nonce.
    check_ajax_referer(
        'wp_designchecker_historical_image',
        'nonce'
    );

    // Retrieve and sanitize the historical scan identifier.
    $scan_id = isset($_GET['scan_id'])
        ? sanitize_text_field(wp_unslash($_GET['scan_id']))
        : '';

    // Retrieve the screenshot type.
    $mode = isset($_GET['mode'])
        ? sanitize_key(wp_unslash($_GET['mode']))
        : '';

    // Retrieve the device viewport.
    $viewport = isset($_GET['viewport'])
        ? sanitize_key(wp_unslash($_GET['viewport']))
        : '';

    // Request the archived image from the Python service.
    $image = WP_DesignChecker_API_Client::get_historical_screenshot(
        $scan_id,
        $mode,
        $viewport
    );

    // Return an error if the archived screenshot is unavailable.
    if (is_wp_error($image)) {
        status_header(404);
        header('Content-Type: text/plain; charset=utf-8');

        echo esc_html($image->get_error_message());
        exit;
    }

    // Send the archived screenshot to the browser.
    wp_designchecker_send_png($image);
}


/**
 * Send validated PNG binary data to the browser.
 *
 * Centralizes screenshot response headers for both latest
 * and historical screenshot endpoints.
 *
 * Important:
 * No whitespace, HTML, or debugging output must be sent
 * before the PNG binary data.
 *
 * @param string $image Validated PNG binary data.
 * @return void
 */
function wp_designchecker_send_png($image)
{
    // Disable browser and intermediary caching.
    nocache_headers();

    // Identify the response as a PNG image.
    header('Content-Type: image/png');

    // Prevent browsers from interpreting the response as HTML.
    header('X-Content-Type-Options: nosniff');

    // Specify the exact image response length.
    header('Content-Length: ' . strlen($image));

    // Output the binary image without escaping or modification.
    echo $image;

    // Prevent WordPress from appending additional output.
    exit;
}


/**
 * Load dashboard CSS, JavaScript, and endpoint configuration.
 *
 * Assets are loaded only on the WP DesignChecker dashboard
 * to avoid affecting unrelated WordPress administration pages.
 *
 * @param string $hook Current WordPress admin page hook.
 * @return void
 */
function wp_designchecker_enqueue_assets($hook)
{
    // Only enqueue assets on the DesignChecker dashboard.
    if ($hook !== 'toplevel_page_wp-designchecker') {
        return;
    }

    // Locate the dashboard stylesheet.
    $style_path = plugin_dir_path(__FILE__) .
        'assets/css/dashboard.css';

    // Load the dashboard stylesheet.
    wp_enqueue_style(
        'wp-designchecker-dashboard',
        plugin_dir_url(__FILE__) . 'assets/css/dashboard.css',
        array(),
        file_exists($style_path)
            ? filemtime($style_path)
            : '0.1.0'
    );

    // Locate the dashboard JavaScript file.
    $script_path = plugin_dir_path(__FILE__) .
        'assets/js/dashboard.js';

    // Load dashboard interactions.
    wp_enqueue_script(
        'wp-designchecker-dashboard',
        plugin_dir_url(__FILE__) . 'assets/js/dashboard.js',
        array(),
        file_exists($script_path)
            ? filemtime($script_path)
            : '0.1.0',
        true
    );

    /**
     * Provide authenticated screenshot endpoints to JavaScript.
     *
     * The browser communicates with WordPress AJAX rather than
     * directly accessing the Python API.
     *
     * The Python API key remains on the WordPress server.
     */

    // Existing endpoint for latest screenshot review.
    $image_endpoint = add_query_arg(
        array(
            'action' => 'wp_designchecker_image',
            'nonce' => wp_create_nonce(
                'wp_designchecker_image'
            ),
        ),
        admin_url('admin-ajax.php')
    );

    // New endpoint for archived historical screenshots.
    $historical_image_endpoint = add_query_arg(
        array(
            'action' => 'wp_designchecker_historical_image',
            'nonce' => wp_create_nonce(
                'wp_designchecker_historical_image'
            ),
        ),
        admin_url('admin-ajax.php')
    );

    // Make both endpoint URLs available to dashboard.js.
    wp_add_inline_script(
        'wp-designchecker-dashboard',
        'window.wpDesignChecker = ' . wp_json_encode(
            array(
                'imageEndpoint' => $image_endpoint,
                'historicalImageEndpoint' => $historical_image_endpoint,
            )
        ) . ';',
        'before'
    );
}
