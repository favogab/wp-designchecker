<?php

/**
 * WP DesignChecker — Python API Client
 *
 * Provides authenticated communication between the WordPress
 * plugin and the local FastAPI screenshot comparison service.
 *
 * Supports:
 * - Starting visual regression scans
 * - Retrieving scan progress and results
 * - Retrieving historical scan records
 * - Loading latest screenshots
 * - Loading archived screenshots from individual scans
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_DesignChecker_API_Client
{
    private const API_URL = 'http://127.0.0.1:8000';

    /**
     * Send an authenticated JSON request to the Python API.
     *
     * @param string $method HTTP request method.
     * @param string $endpoint Relative API endpoint.
     * @return array|WP_Error Decoded response or error.
     */
    private static function request($method, $endpoint)
    {
        if (
            !defined('WP_DESIGNCHECKER_API_KEY') ||
            !WP_DESIGNCHECKER_API_KEY
        ) {
            return new WP_Error(
                'missing_api_key',
                'DesignChecker API key is not configured.'
            );
        }

        $response = wp_remote_request(
            self::API_URL . $endpoint,
            array(
                'method' => $method,
                'headers' => array(
                    'X-API-Key' => WP_DESIGNCHECKER_API_KEY,
                    'Accept' => 'application/json',
                ),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);

        $body = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        // Return a meaningful error for unsuccessful responses.
        if (
            !is_array($body) ||
            $status < 200 ||
            $status >= 300
        ) {
            $message = (
                is_array($body) &&
                isset($body['detail']) &&
                is_string($body['detail'])
            )
                ? $body['detail']
                : 'The scanning service returned an error.';

            return new WP_Error(
                'api_request_failed',
                $message
            );
        }

        return $body;
    }

    /**
     * Validate a scan identifier before including it in an API URL.
     *
     * @param mixed $scan_id Scan UUID.
     * @return bool Whether the identifier is valid.
     */
    private static function is_valid_scan_id($scan_id)
    {
        return is_string($scan_id) && (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $scan_id
            ) === 1
        );
    }

    /**
     * Retrieve the latest visual comparison report.
     *
     * @return array|WP_Error Latest report or error.
     */
    public static function get_latest_report()
    {
        return self::request(
            'GET',
            '/api/reports/latest'
        );
    }

    /**
     * Queue a new visual regression scan.
     *
     * @return array|WP_Error Scan creation response or error.
     */
    public static function run_scan()
    {
        return self::request(
            'POST',
            '/api/scans'
        );
    }

    /**
     * Retrieve an individual scan's status and saved results.
     *
     * @param string $scan_id Unique scan identifier.
     * @return array|WP_Error Scan data or error.
     */
    public static function get_scan($scan_id)
    {
        if (!self::is_valid_scan_id($scan_id)) {
            return new WP_Error(
                'invalid_scan_id',
                'Invalid scan identifier.'
            );
        }

        return self::request(
            'GET',
            '/api/scans/' . $scan_id
        );
    }

    /**
     * Retrieve historical scan records from SQLite through FastAPI.
     *
     * @param int $limit Maximum number of records.
     * @return array|WP_Error Scan history or error.
     */
    public static function get_scan_history($limit = 20)
    {
        // Restrict history requests to 1–100 records.
        $limit = max(1, min(absint($limit), 100));

        return self::request(
            'GET',
            '/api/scans?limit=' . $limit
        );
    }

    /**
     * Retrieve and validate a PNG image from the Python API.
     *
     * Shared by latest screenshot retrieval and historical
     * screenshot retrieval.
     *
     * @param string $endpoint Relative screenshot API endpoint.
     * @return string|WP_Error PNG binary data or error.
     */
    private static function request_image($endpoint)
    {
        if (
            !defined('WP_DESIGNCHECKER_API_KEY') ||
            !WP_DESIGNCHECKER_API_KEY
        ) {
            return new WP_Error(
                'missing_api_key',
                'DesignChecker API key is not configured.'
            );
        }

        $response = wp_remote_get(
            self::API_URL . $endpoint,
            array(
                'headers' => array(
                    'X-API-Key' => WP_DESIGNCHECKER_API_KEY,
                    'Accept' => 'image/png',
                ),
                'timeout' => 20,
                'limit_response_size' => 10 * 1024 * 1024 + 1,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Reject unsuccessful screenshot requests.
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error(
                'screenshot_unavailable',
                'Screenshot could not be retrieved.'
            );
        }

        $image = wp_remote_retrieve_body($response);

        // Reject oversized or empty image responses.
        if (
            strlen($image) === 0 ||
            strlen($image) > 10 * 1024 * 1024
        ) {
            return new WP_Error(
                'invalid_image_size',
                'Screenshot is empty or exceeds the size limit.'
            );
        }

        // Verify the PNG file signature.
        if (
            strncmp(
                $image,
                "\x89PNG\r\n\x1a\n",
                8
            ) !== 0
        ) {
            return new WP_Error(
                'invalid_image',
                'The service did not return a valid PNG image.'
            );
        }

        return $image;
    }

    /**
     * Validate screenshot mode and viewport.
     *
     * @param string $mode Screenshot type.
     * @param string $viewport Device viewport.
     * @return bool Whether both selections are supported.
     */
    private static function is_valid_screenshot_selection(
        $mode,
        $viewport
    ) {
        $allowed_modes = array(
            'baselines',
            'current',
            'diffs'
        );

        $allowed_viewports = array(
            'desktop',
            'tablet',
            'mobile'
        );

        return (
            in_array($mode, $allowed_modes, true) &&
            in_array($viewport, $allowed_viewports, true)
        );
    }

    /**
     * Retrieve a screenshot from the latest scan directories.
     *
     * Preserves compatibility with the existing screenshot
     * review interface.
     *
     * @param string $mode Screenshot type.
     * @param string $viewport Device viewport.
     * @return string|WP_Error PNG binary data or error.
     */
    public static function get_screenshot($mode, $viewport)
    {
        if (
            !self::is_valid_screenshot_selection(
                $mode,
                $viewport
            )
        ) {
            return new WP_Error(
                'invalid_screenshot',
                'Invalid screenshot selection.'
            );
        }

        return self::request_image(
            '/api/screenshots/' . $mode . '/' . $viewport
        );
    }

    /**
     * Retrieve an archived screenshot for a specific scan.
     *
     * Uses the scan-specific FastAPI endpoint rather than
     * the shared latest screenshot directories.
     *
     * @param string $scan_id Unique historical scan identifier.
     * @param string $mode Screenshot type.
     * @param string $viewport Device viewport.
     * @return string|WP_Error PNG binary data or error.
     */
    public static function get_historical_screenshot(
        $scan_id,
        $mode,
        $viewport
    ) {
        // Reject malformed scan identifiers.
        if (!self::is_valid_scan_id($scan_id)) {
            return new WP_Error(
                'invalid_scan_id',
                'Invalid scan identifier.'
            );
        }

        // Reject unsupported screenshot selections.
        if (
            !self::is_valid_screenshot_selection(
                $mode,
                $viewport
            )
        ) {
            return new WP_Error(
                'invalid_screenshot',
                'Invalid screenshot selection.'
            );
        }

        // Retrieve the image belonging to the requested scan.
        return self::request_image(
            '/api/scans/' .
            $scan_id .
            '/screenshots/' .
            $mode .
            '/' .
            $viewport
        );
    }
}
