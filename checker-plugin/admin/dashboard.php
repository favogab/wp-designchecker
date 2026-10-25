<?php

/**
 * WP DesignChecker Administration Dashboard.
 *
 * Displays:
 * - Visual regression scanner
 * - Latest scan status
 * - Viewport comparison results
 * - Screenshot review
 * - Persistent scan history
 *
 * Scan data is retrieved from the Python FastAPI service.
 */

if (!defined('ABSPATH') || !current_user_can('manage_options')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Current User and Notifications
|--------------------------------------------------------------------------
*/

// Identify the administrator currently viewing the dashboard.
$user_id = get_current_user_id();

// Retrieve and clear temporary dashboard notifications.
$notice_key = 'wp_designchecker_notice_' . $user_id;

$notice = get_transient($notice_key);

delete_transient($notice_key);


/*
|--------------------------------------------------------------------------
| Latest Scan
|--------------------------------------------------------------------------
*/

// Retrieve the scan identifier saved for this administrator.
$scan_id = get_user_meta(
    $user_id,
    'wp_designchecker_scan_id',
    true
);

// Retrieve the scan from the Python API when an ID exists.
$scan_result = $scan_id
    ? WP_DesignChecker_API_Client::get_scan($scan_id)
    : null;

// Initialize dashboard state.
$state = 'idle';
$visual_result = null;
$error_message = '';

// Interpret the API response without overwriting the complete scan record.
if (is_wp_error($scan_result)) {
    $state = 'error';
    $error_message = $scan_result->get_error_message();
} elseif (is_array($scan_result)) {
    $state = isset($scan_result['state']) && is_string($scan_result['state'])
        ? $scan_result['state']
        : 'unknown';

    // New scans contain a report array; older scans contain a status string.
    $result_data = $scan_result['result'] ?? null;

    if (is_array($result_data)) {
        $visual_result = isset($result_data['overall_status']) &&
            is_string($result_data['overall_status'])
            ? $result_data['overall_status']
            : 'Pending';
    } elseif (is_string($result_data)) {
        $visual_result = $result_data;
    } else {
        $visual_result = 'Pending';
    }

    // Read errors from the original scan record, not from its result field.
    $error_message = isset($scan_result['error']) &&
        is_string($scan_result['error'])
        ? $scan_result['error']
        : '';
}


/*
|--------------------------------------------------------------------------
| Latest Visual Comparison Report
|--------------------------------------------------------------------------
*/

// Comparison results are only requested after scan completion.
$report = null;
$report_error = '';

if ($state === 'completed') {

    $report_response =
        WP_DesignChecker_API_Client::get_latest_report();

    if (is_wp_error($report_response)) {

        $report_error = $report_response->get_error_message();
    } elseif (
        isset($report_response['results']) &&
        is_array($report_response['results'])
    ) {

        $report = $report_response;
    } else {

        $report_error =
            'The report does not contain viewport results.';
    }
}


/*
|--------------------------------------------------------------------------
| Persistent Scan History
|--------------------------------------------------------------------------
*/

// Retrieve the 20 most recent scan records from SQLite.
$history_response =
    WP_DesignChecker_API_Client::get_scan_history(20);

// Initialize history data.
$scan_history = array();
$history_error = '';

// Validate the response before using it in the dashboard.
if (is_wp_error($history_response)) {

    $history_error =
        $history_response->get_error_message();
} elseif (
    is_array($history_response) &&
    isset($history_response['scans']) &&
    is_array($history_response['scans'])
) {

    $scan_history = $history_response['scans'];
} else {

    $history_error =
        'Scan history could not be retrieved.';
}


/*
|--------------------------------------------------------------------------
| Selected Historical Scan
|--------------------------------------------------------------------------
*/

// Only accept a scan ID that exists in the history returned by the API.
// This prevents arbitrary IDs from being inserted into screenshot URLs.
$selected_scan_id = isset($_GET['dc_scan_id'])
    ? sanitize_text_field(wp_unslash($_GET['dc_scan_id']))
    : '';

$selected_viewport = isset($_GET['dc_viewport'])
    ? sanitize_key(wp_unslash($_GET['dc_viewport']))
    : 'desktop';

if (!in_array($selected_viewport, array('desktop', 'tablet', 'mobile'), true)) {
    $selected_viewport = 'desktop';
}

$selected_scan = null;
foreach ($scan_history as $history_item) {
    if (
        is_array($history_item) &&
        isset($history_item['scan_id']) &&
        is_string($history_item['scan_id']) &&
        hash_equals($history_item['scan_id'], $selected_scan_id)
    ) {
        $selected_scan = $history_item;
        break;
    }
}

// Load the full scan record for the selected historical entry.
// A historical record must not be replaced by the latest report.
$selected_scan_details = null;
$selected_scan_error = '';

if ($selected_scan !== null) {
    $selected_scan_details = WP_DesignChecker_API_Client::get_scan($selected_scan_id);
    if (is_wp_error($selected_scan_details)) {
        $selected_scan_error = $selected_scan_details->get_error_message();
        $selected_scan_details = null;
    } elseif (!is_array($selected_scan_details)) {
        $selected_scan_error = 'The selected scan details are unavailable.';
        $selected_scan_details = null;
    }
}

// Historical image URLs are proxied through WordPress; the Python API key
// never appears in browser requests.
$historical_image_base = add_query_arg(
    array(
        'action' => 'wp_designchecker_historical_image',
        'nonce' => wp_create_nonce('wp_designchecker_historical_image'),
        'scan_id' => $selected_scan_id,
        'viewport' => $selected_viewport,
    ),
    admin_url('admin-ajax.php')
);

/*
|--------------------------------------------------------------------------
| Status Labels and Appearance
|--------------------------------------------------------------------------
*/

// Translate internal states into administrator-friendly labels.
$state_labels = array(
    'idle'      => 'Ready',
    'pending'   => 'Queued',
    'running'   => 'Scanning',
    'completed' => 'Completed',
    'failed'    => 'Failed',
    'error'     => 'Unavailable',
);

$state_label = $state_labels[$state] ?? 'Unknown';

// Determine the visual appearance of the current scan status.
$state_class = $state === 'completed'
    ? 'dc-success'
    : (
        in_array($state, array('failed', 'error'), true)
        ? 'dc-error'
        : 'dc-neutral'
    );

// Determine whether automatic status refresh is required.
$scan_active = in_array(
    $state,
    array('pending', 'running'),
    true
);

?>

<div
    class="wrap dc-dashboard"
    data-scan-active="<?php echo esc_attr($scan_active ? 'true' : 'false'); ?>">

    <!-- =========================================================
         Dashboard Header
    ========================================================== -->

    <header class="dc-header">

        <div>

            <p class="dc-eyebrow">
                Visual Quality Assurance
            </p>

            <h1 class="dc-title">
                WP DesignChecker
            </h1>

            <p class="dc-subtitle">
                Monitor visual changes across your WordPress website.
            </p>

        </div>

        <span class="dc-version">
            Version 0.1.0
        </span>

    </header>


    <!-- =========================================================
         Dashboard Notifications
    ========================================================== -->

    <?php if ($notice) : ?>

        <div class="notice notice-info is-dismissible">

            <p>
                <?php echo esc_html($notice); ?>
            </p>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         Visual Regression Scanner
    ========================================================== -->

    <section
        class="dc-card"
        aria-labelledby="dc-scan-title">

        <div class="dc-card-header">

            <h2
                id="dc-scan-title"
                class="dc-card-title">
                Visual Regression Scanner
            </h2>

            <span
                class="dc-status <?php echo esc_attr($state_class); ?>">
                <?php echo esc_html($state_label); ?>
            </span>

        </div>


        <div class="dc-scan-panel">

            <div class="dc-scan-copy">

                <p>
                    Capture and compare desktop, tablet, and mobile
                    screenshots against approved design baselines.
                    Detect unexpected visual changes before they
                    affect your website's appearance.
                </p>

            </div>


            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                <input
                    type="hidden"
                    name="action"
                    value="wp_designchecker_scan">

                <?php wp_nonce_field('wp_designchecker_scan'); ?>

                <button
                    type="submit"
                    class="dc-primary-button"
                    <?php disabled($scan_active); ?>>

                    <span
                        class="dashicons dashicons-visibility"
                        aria-hidden="true"
                        style="margin-right: 8px;"></span>

                    Run Check

                </button>

            </form>

        </div>

    </section>


    <!-- =========================================================
         Latest Scan Results
    ========================================================== -->

    <section
        class="dc-card"
        aria-labelledby="dc-results-title">

        <div class="dc-card-header">

            <h2
                id="dc-results-title"
                class="dc-card-title">
                Latest Scan Results
            </h2>

            <?php if ($scan_id) : ?>

                <a
                    class="dc-secondary-button"
                    href="<?php
                            echo esc_url(
                                admin_url('admin.php?page=wp-designchecker')
                            );
                            ?>">
                    Refresh Status
                </a>

            <?php endif; ?>

        </div>


        <?php if (!$scan_id) : ?>

            <div class="dc-empty">

                <div class="dc-empty-icon">

                    <span
                        class="dashicons dashicons-camera"
                        aria-hidden="true"></span>

                </div>

                <h3>No scans yet</h3>

                <p>
                    Run your first design check to see
                    visual comparison results here.
                </p>

            </div>

        <?php else : ?>

            <!-- Summary Metrics -->

            <div class="dc-result-grid">

                <div class="dc-metric">

                    <span class="dc-metric-label">
                        Job Status
                    </span>

                    <div class="dc-metric-value">
                        <?php echo esc_html($state_label); ?>
                    </div>

                </div>


                <div class="dc-metric">

                    <span class="dc-metric-label">
                        Visual Result
                    </span>

                    <?php

                    // Assign a color based on the comparison outcome.
                    $visual_class = '';

                    if ($visual_result === 'FAIL') {
                        $visual_class = 'dc-fail-text';
                    } elseif ($visual_result === 'PASS') {
                        $visual_class = 'dc-pass-text';
                    }

                    ?>

                    <div
                        class="dc-metric-value <?php echo esc_attr($visual_class); ?>">
                        <?php
                        echo esc_html($visual_result ?? 'Pending');
                        ?>
                    </div>

                </div>


                <div class="dc-metric">

                    <span class="dc-metric-label">
                        Viewports
                    </span>

                    <div class="dc-metric-value">
                        3
                    </div>

                </div>

            </div>


            <!-- Viewport Comparison -->

            <?php if ($report && !empty($report['results'])) : ?>

                <h3 style="margin-top: 28px;">
                    Viewport Comparison
                </h3>

                <p class="dc-card-description">
                    Percentage of pixels that differ from the approved baseline.
                </p>


                <div class="dc-viewport-grid">

                    <?php foreach ($report['results'] as $item) : ?>

                        <?php

                        // Ignore malformed viewport records.
                        if (!is_array($item)) {
                            continue;
                        }

                        // Retrieve the viewport name.
                        $device = isset($item['viewport']) &&
                            is_string($item['viewport'])
                            ? $item['viewport']
                            : 'Unknown';

                        // Retrieve the comparison status.
                        $status = isset($item['status']) &&
                            is_string($item['status'])
                            ? $item['status']
                            : 'ERROR';

                        // Retrieve the percentage difference.
                        $difference = isset($item['difference']) &&
                            is_numeric($item['difference'])
                            ? (float) $item['difference']
                            : null;

                        // Keep the progress bar within 0–100%.
                        $width = $difference === null
                            ? 0
                            : max(0, min(100, $difference));

                        $viewport_status_class = $status === 'PASS'
                            ? 'dc-success'
                            : 'dc-error';

                        $viewport_text_class = $status === 'PASS'
                            ? 'dc-pass-text'
                            : 'dc-fail-text';

                        ?>

                        <article class="dc-viewport-card">

                            <h3>
                                <?php echo esc_html(ucfirst($device)); ?>
                            </h3>


                            <span
                                class="dc-status <?php
                                                    echo esc_attr($viewport_status_class);
                                                    ?>">
                                <?php echo esc_html($status); ?>
                            </span>


                            <div
                                class="dc-viewport-percent <?php
                                                            echo esc_attr($viewport_text_class);
                                                            ?>">

                                <?php

                                echo $difference === null
                                    ? 'N/A'
                                    : esc_html(
                                        number_format($difference, 2) . '%'
                                    );

                                ?>

                            </div>


                            <div
                                class="dc-progress-track"
                                role="img"
                                aria-label="<?php
                                            echo esc_attr(
                                                $device . ' visual difference: ' .
                                                    (
                                                        $difference === null
                                                        ? 'unavailable'
                                                        : number_format($difference, 2) . '%'
                                                    )
                                            );
                                            ?>">

                                <div
                                    class="dc-progress-fill <?php
                                                            echo esc_attr(
                                                                $status === 'PASS'
                                                                    ? 'is-pass'
                                                                    : ''
                                                            );
                                                            ?>"
                                    style="width: <?php echo esc_attr($width); ?>%;"></div>

                            </div>


                            <p class="dc-viewport-note">

                                <?php

                                echo esc_html(
                                    isset($item['message']) &&
                                        is_string($item['message'])
                                        ? $item['message']
                                        : ''
                                );

                                ?>

                            </p>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php elseif ($report_error) : ?>

                <div class="dc-error-message" role="alert">
                    <?php echo esc_html($report_error); ?>
                </div>

            <?php endif; ?>


            <?php if ($error_message) : ?>

                <div class="dc-error-message" role="alert">
                    <?php echo esc_html($error_message); ?>
                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>


    <!-- =========================================================
         Screenshot Review
    ========================================================== -->

    <?php if ($state === 'completed' && $report) : ?>

        <section
            class="dc-card"
            aria-labelledby="dc-review-title">

            <div class="dc-card-header">

                <div>

                    <h2
                        id="dc-review-title"
                        class="dc-card-title">
                        Screenshot Review
                    </h2>

                    <p class="dc-card-description">
                        Inspect approved baselines, current screenshots,
                        and highlighted visual differences.
                    </p>

                </div>

            </div>


            <div class="dc-review-controls">

                <div class="dc-review-group">

                    <label for="dc-review-viewport">
                        Viewport
                    </label>

                    <select id="dc-review-viewport">

                        <option value="desktop">
                            Desktop
                        </option>

                        <option value="tablet">
                            Tablet
                        </option>

                        <option value="mobile">
                            Mobile
                        </option>

                    </select>

                </div>


                <div class="dc-review-group">

                    <label for="dc-review-mode">
                        Screenshot
                    </label>

                    <select id="dc-review-mode">

                        <option value="baselines">
                            Approved Baseline
                        </option>

                        <option value="current">
                            Current Design
                        </option>

                        <option value="diffs">
                            Visual Difference
                        </option>

                    </select>

                </div>

            </div>


            <div class="dc-preview-frame">

                <img
                    id="dc-review-image"
                    alt="WordPress design screenshot"
                    loading="lazy"
                    hidden>

                <p
                    id="dc-review-error"
                    hidden
                    role="alert">
                    Screenshot could not be loaded.
                </p>

            </div>

        </section>

    <?php endif; ?>


    <!-- =========================================================
         Scan History
    ========================================================== -->

    <section
        class="dc-card dc-history-section"
        id="dc-scan-history"
        aria-labelledby="dc-history-title">

        <div class="dc-card-header">

            <div>

                <h2
                    id="dc-history-title"
                    class="dc-card-title">
                    Scan History
                </h2>

                <p class="dc-card-description">
                    Review previous visual regression checks
                    and track your website's design quality.
                </p>

            </div>

            <span class="dc-history-count">
                <?php if ($history_error !== '') : ?>

                    <?php
                    // The scanning service could not return history.
                    // Do not report zero records when the count is unknown.
                    echo esc_html('Unavailable');
                    ?>

                <?php else : ?>

                    <?php
                    // Only display the record count after a successful request.
                    echo esc_html(
                        count($scan_history) . ' Records'
                    );
                    ?>

                <?php endif; ?>
            </span>

        </div>


        <?php if ($history_error) : ?>

            <!-- Display an error if the Python API is unavailable. -->

            <div class="dc-error-message" role="alert">

                <?php echo esc_html($history_error); ?>

            </div>


        <?php elseif (empty($scan_history)) : ?>

            <!-- Empty State -->

            <div class="dc-empty">

                <div class="dc-empty-icon">

                    <span
                        class="dashicons dashicons-backup"
                        aria-hidden="true"></span>

                </div>

                <h3>No scan history yet</h3>

                <p>
                    Completed design checks will appear here
                    once scan records have been saved.
                </p>

            </div>


        <?php else : ?>

            <!-- Responsive History Table -->

            <div class="dc-history-table-wrapper">

                <table class="dc-history-table">

                    <thead>

                        <tr>

                            <th scope="col">
                                Date
                            </th>

                            <th scope="col">
                                Scan ID
                            </th>

                            <th scope="col">
                                Job Status
                            </th>

                            <th scope="col">
                                Visual Result
                            </th>

                            <th scope="col">
                                Duration
                            </th>

                            <th scope="col">
                                Details
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($scan_history as $scan) : ?>

                            <?php

                            // Skip invalid history records.
                            if (!is_array($scan)) {
                                continue;
                            }

                            // Extract the stored scan information.
                            $history_id = $scan['scan_id'] ?? '';

                            $history_state = $scan['state'] ?? 'unknown';

                            // Normalize legacy strings and newer structured reports.
                            $history_data = $scan['result'] ?? null;

                            if (is_array($history_data)) {
                                $history_result = isset($history_data['overall_status']) &&
                                    is_string($history_data['overall_status'])
                                    ? $history_data['overall_status']
                                    : 'Pending';
                            } elseif (is_string($history_data)) {
                                $history_result = $history_data;
                            } else {
                                $history_result = 'Pending';
                            }

                            $created_at = $scan['created_at'] ?? '';

                            $finished_at = $scan['finished_at'] ?? '';

                            // Translate internal state into a readable label.
                            $history_state_label =
                                $state_labels[$history_state] ?? 'Unknown';

                            // Assign a visual style to the job status.
                            $history_state_class =
                                $history_state === 'completed'
                                ? 'dc-success'
                                : (
                                    $history_state === 'failed'
                                    ? 'dc-error'
                                    : 'dc-neutral'
                                );

                            // Assign a visual style to the comparison result.
                            $history_result_class =
                                $history_result === 'PASS'
                                ? 'dc-success'
                                : (
                                    $history_result === 'FAIL'
                                    ? 'dc-error'
                                    : 'dc-neutral'
                                );

                            // Parse timestamps safely.
                            $created_timestamp = is_string($created_at)
                                ? strtotime($created_at)
                                : false;

                            $finished_timestamp = is_string($finished_at)
                                ? strtotime($finished_at)
                                : false;

                            /// Display scan timestamps in (WAT, UTC+01:00).
                            $africa_timezone = new DateTimeZone('Africa/Lagos');

                            $formatted_date = $created_timestamp !== false
                                ? wp_date(
                                    'M j, Y',
                                    $created_timestamp,
                                    $africa_timezone
                                )
                                : 'Unknown date';

                            $formatted_time = $created_timestamp !== false
                                ? wp_date(
                                    'g:i A',
                                    $created_timestamp,
                                    $africa_timezone
                                ) . ' WAT (UTC+01:00)'
                                : '';

                            // Calculate elapsed scan time.
                            $duration = '—';

                            if (
                                $created_timestamp !== false &&
                                $finished_timestamp !== false
                            ) {

                                $elapsed = max(
                                    0,
                                    $finished_timestamp - $created_timestamp
                                );

                                if ($elapsed >= 60) {

                                    $minutes = floor($elapsed / 60);

                                    $seconds = $elapsed % 60;

                                    $duration = sprintf(
                                        '%dm %ds',
                                        $minutes,
                                        $seconds
                                    );
                                } else {

                                    $duration = $elapsed . 's';
                                }
                            }

                            // Display a shortened identifier for readability.
                            $short_id = is_string($history_id)
                                ? substr($history_id, 0, 8)
                                : '';

                            ?>

                            <tr>

                                <!-- Scan Date -->

                                <td>

                                    <div class="dc-history-date">

                                        <strong>
                                            <?php
                                            echo esc_html($formatted_date);
                                            ?>
                                        </strong>

                                        <span>
                                            <?php
                                            echo esc_html($formatted_time);
                                            ?>
                                        </span>

                                    </div>

                                </td>


                                <!-- Scan Identifier -->

                                <td>

                                    <code
                                        class="dc-history-id"
                                        title="<?php
                                                echo esc_attr($history_id);
                                                ?>">
                                        <?php
                                        echo esc_html($short_id);
                                        ?>
                                    </code>

                                </td>


                                <!-- Job Status -->

                                <td>

                                    <span
                                        class="dc-status <?php
                                                            echo esc_attr($history_state_class);
                                                            ?>">
                                        <?php
                                        echo esc_html($history_state_label);
                                        ?>
                                    </span>

                                </td>


                                <!-- Visual Result -->

                                <td>

                                    <span
                                        class="dc-status <?php
                                                            echo esc_attr($history_result_class);
                                                            ?>">
                                        <?php
                                        echo esc_html(
                                            is_string($history_result)
                                                ? $history_result
                                                : 'Pending'
                                        );
                                        ?>
                                    </span>

                                </td>


                                <!-- Scan Duration -->

                                <td>

                                    <span class="dc-history-duration">
                                        <?php echo esc_html($duration); ?>
                                    </span>

                                </td>

                                <!-- Open the archived scan without changing the latest scan. -->
                                <td>
                                    <?php if (is_string($history_id) && $history_id !== '') : ?>
                                        <a class="dc-secondary-button"
                                            href="<?php echo esc_url(add_query_arg(
                                                        array(
                                                            'page' => 'wp-designchecker',
                                                            'dc_scan_id' => $history_id,
                                                        ),
                                                        admin_url('admin.php')
                                                    ) . '#dc-historical-details'); ?>">
                                            View Details
                                        </a>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


    <!-- =========================================================
         Historical Scan Details
    ========================================================== -->

    <?php if ($selected_scan_id !== '') : ?>
        <section class="dc-card" id="dc-historical-details"
            aria-labelledby="dc-historical-title">
            <div class="dc-card-header">
                <div>
                    <h2 id="dc-historical-title" class="dc-card-title">
                        Historical Scan Details
                    </h2>
                    <p class="dc-card-description">
                        Review the stored results and archived images for one scan.
                    </p>
                </div>
                <a class="dc-secondary-button"
                    href="<?php echo esc_url(admin_url('admin.php?page=wp-designchecker#dc-scan-history')); ?>">
                    Close Details
                </a>
            </div>

            <?php if ($selected_scan === null) : ?>

                <p class="dc-error-message" role="alert">

                    <?php if ($history_error !== '') : ?>

                        <?php
                        // History could not be loaded, so we cannot determine
                        // whether the selected scan exists.
                        echo esc_html(
                            'Historical scan details are temporarily unavailable. ' .
                                'Please check the scanning service and try again.'
                        );
                        ?>

                    <?php else : ?>

                        <?php
                        // History loaded successfully, but the selected scan
                        // was not found among the records returned.
                        echo esc_html(
                            'This scan was not found in the loaded history.'
                        );
                        ?>

                    <?php endif; ?>

                </p>

            <?php else : ?>
                <p>
                    <strong>Scan ID:</strong>
                    <code><?php echo esc_html($selected_scan_id); ?></code>
                </p>

                <?php if ($selected_scan_error !== '') : ?>
                    <p class="dc-error-message" role="alert">
                        <?php echo esc_html($selected_scan_error); ?>
                    </p>
                <?php endif; ?>

                <?php
                // Prefer the complete historical scan returned by get_scan().
                $historical_record = is_array($selected_scan_details)
                    ? $selected_scan_details
                    : $selected_scan;
                $historical_state = $historical_record['state'] ?? 'unknown';
                $historical_result = $historical_record['result'] ?? null;
                $historical_status = is_array($historical_result)
                    ? ($historical_result['overall_status'] ?? 'Pending')
                    : (is_string($historical_result) ? $historical_result : 'Pending');
                ?>

                <div class="dc-result-grid">
                    <div class="dc-metric">
                        <span class="dc-metric-label">Job Status</span>
                        <div class="dc-metric-value">
                            <?php echo esc_html($state_labels[$historical_state] ?? 'Unknown'); ?>
                        </div>
                    </div>
                    <div class="dc-metric">
                        <span class="dc-metric-label">Visual Result</span>
                        <div class="dc-metric-value">
                            <?php echo esc_html(is_string($historical_status) ? $historical_status : 'Pending'); ?>
                        </div>
                    </div>
                </div>

                <?php
                // Show viewport details only when they belong to this scan.
                $historical_viewports = is_array($historical_result)
                    && isset($historical_result['results'])
                    && is_array($historical_result['results'])
                    ? $historical_result['results']
                    : array();
                ?>

                <?php if ($historical_viewports) : ?>
                    <h3>Saved Viewport Results</h3>
                    <div class="dc-viewport-grid">
                        <?php foreach ($historical_viewports as $historical_item) : ?>
                            <?php if (!is_array($historical_item)) {
                                continue;
                            } ?>
                            <article class="dc-viewport-card">
                                <h3><?php echo esc_html(ucfirst((string) ($historical_item['viewport'] ?? 'Unknown'))); ?></h3>
                                <p><strong>Status:</strong>
                                    <?php echo esc_html((string) ($historical_item['status'] ?? 'Unknown')); ?>
                                </p>
                                <p><strong>Difference:</strong>
                                    <?php echo isset($historical_item['difference']) && is_numeric($historical_item['difference'])
                                        ? esc_html(number_format((float) $historical_item['difference'], 2) . '%')
                                        : 'N/A'; ?>
                                </p>
                                <p><?php echo esc_html((string) ($historical_item['message'] ?? '')); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($historical_state === 'completed') : ?>
                    <h3>Archived Screenshot Review</h3>
                    <p class="dc-card-description">
                        These images belong to the selected scan, not the latest scan.
                        Archived baselines are not available in this version.
                    </p>

                    <form
                        id="dc-historical-viewport-form"
                        method="get"
                        action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="wp-designchecker">
                        <input type="hidden" name="dc_scan_id"
                            value="<?php echo esc_attr($selected_scan_id); ?>">
                        <label for="dc-historical-viewport">Viewport</label>
                        <select name="dc_viewport" id="dc-historical-viewport">
                            <?php foreach (array('desktop', 'tablet', 'mobile') as $viewport_option) : ?>
                                <option value="<?php echo esc_attr($viewport_option); ?>"
                                    <?php selected($selected_viewport, $viewport_option); ?>>
                                    <?php echo esc_html(ucfirst($viewport_option)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="dc-secondary-button">Show Screenshots</button>
                    </form>


                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const form = document.getElementById(
                                'dc-historical-viewport-form'
                            );

                            if (!form) {
                                return;
                            }

                            const button = form.querySelector('button');
                            const viewportSelect = document.getElementById(
                                'dc-historical-viewport'
                            );

                            button.addEventListener('click', function() {
                                const viewport = viewportSelect.value;

                                // Find both archived screenshot panels.
                                const panels = document.querySelectorAll(
                                    '#dc-historical-details .dc-archive-comparison-panel'
                                );

                                panels.forEach(function(panel) {
                                    const image = panel.querySelector('img');
                                    const heading = panel.querySelector('h3');

                                    if (!image) {
                                        return;
                                    }

                                    // Keep the scan ID, nonce, and screenshot mode.
                                    // Change only the requested viewport.
                                    const imageUrl = new URL(image.src);
                                    imageUrl.searchParams.set('viewport', viewport);

                                    // Load the new image without reloading WordPress.
                                    image.loading = 'eager';
                                    image.src = imageUrl.toString();

                                    // Update the heading to match the selected viewport.
                                    if (heading) {
                                        const label = heading.textContent.split(' — ')[0];

                                        heading.textContent =
                                            label + ' — ' +
                                            viewport.charAt(0).toUpperCase() +
                                            viewport.slice(1);
                                    }
                                });
                            });
                        });
                    </script>


                    <?php if (!method_exists('WP_DesignChecker_API_Client', 'get_historical_screenshot')) : ?>
                        <p class="dc-error-message" role="alert">
                            The API client still needs its get_historical_screenshot() method.
                        </p>
                    <?php else : ?>
                        <!--
                            Responsive historical comparison:
                            two equal columns on wider screens, one on mobile.
                            Both images retain their scan-specific authenticated URLs.
                        -->
                        <style>
                            #dc-historical-details .dc-archive-comparison {
                                display: grid;
                                grid-template-columns: repeat(2, minmax(0, 1fr));
                                gap: 20px;
                                align-items: start;
                                margin-top: 20px;
                            }

                            #dc-historical-details .dc-archive-comparison-panel {
                                min-width: 0;
                            }

                            #dc-historical-details .dc-archive-comparison-panel h3 {
                                margin: 0 0 12px;
                            }

                            #dc-historical-details .dc-archive-comparison-panel .dc-preview-frame {
                                box-sizing: border-box;
                                width: 100%;
                                min-width: 0;
                                overflow: auto;
                            }

                            #dc-historical-details .dc-archive-comparison-panel img {
                                display: block;
                                width: 100%;
                                height: auto;
                            }

                            @media (max-width: 900px) {
                                #dc-historical-details .dc-archive-comparison {
                                    grid-template-columns: minmax(0, 1fr);
                                }
                            }
                        </style>

                        <div class="dc-archive-comparison">
                            <?php foreach (array('current' => 'Captured Design', 'diffs' => 'Visual Difference') as $image_mode => $image_label) : ?>
                                <div class="dc-archive-comparison-panel">
                                    <h3><?php echo esc_html($image_label); ?> — <?php echo esc_html(ucfirst($selected_viewport)); ?></h3>
                                    <div class="dc-preview-frame">
                                        <img loading="eager"
                                            src="<?php echo esc_url(add_query_arg('mode', $image_mode, $historical_image_base)); ?>"
                                            alt="<?php echo esc_attr($image_label . ' for ' . $selected_viewport . ' in scan ' . $selected_scan_id); ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <p>Archived screenshots are shown for completed scans.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- =========================================================
         Dashboard Footer
    ========================================================== -->

    <p class="dc-footer">
        WP DesignChecker · Visual Regression Testing for WordPress
    </p>

</div>