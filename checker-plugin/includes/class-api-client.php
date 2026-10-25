
<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_DesignChecker_API_Client
{
    private const API_URL = 'http://127.0.0.1:8000';

    private static function request($method, $endpoint)
    {
        if (!defined('WP_DESIGNCHECKER_API_KEY') || !WP_DESIGNCHECKER_API_KEY) {
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
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || $status < 200 || $status >= 300) {
            $message = is_array($body) && isset($body['detail']) && is_string($body['detail'])
                ? $body['detail']
                : 'The scanning service returned an error.';

            return new WP_Error('api_request_failed', $message);
        }

        return $body;
    }

    // Fetch the detailed report from the local Python service.
    public static function get_latest_report()
    {
        return self::request('GET', '/api/reports/latest');
    }

    public static function run_scan()
    {
        return self::request('POST', '/api/scans');
    }

    public static function get_scan($scan_id)
    {
        if (!is_string($scan_id) || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $scan_id
        )) {
            return new WP_Error('invalid_scan_id', 'Invalid scan identifier.');
        }

        return self::request('GET', '/api/scans/' . $scan_id);
    }
}
