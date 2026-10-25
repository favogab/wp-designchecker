<?php

if (!defined('ABSPATH') || !current_user_can('manage_options')) {
    exit;
}

$user_id = get_current_user_id();
$notice_key = 'wp_designchecker_notice_' . $user_id;

$notice = get_transient($notice_key);
delete_transient($notice_key);

$scan_id = get_user_meta(
    $user_id,
    'wp_designchecker_scan_id',
    true
);

$scan_result = $scan_id
    ? WP_DesignChecker_API_Client::get_scan($scan_id)
    : null;

$state = 'idle';
$visual_result = null;
$error_message = '';

if (is_wp_error($scan_result)) {
    $state = 'error';
    $error_message = $scan_result->get_error_message();
} elseif (is_array($scan_result)) {
    $state = $scan_result['state'] ?? 'unknown';
    $visual_result = $scan_result['result'] ?? null;
    $error_message = $scan_result['error'] ?? '';
}

// Only retrieve comparison details after a scan has finished.
$report = null;
$report_error = '';

if ($state === 'completed') {
    $report_response = WP_DesignChecker_API_Client::get_latest_report();

    if (is_wp_error($report_response)) {
        $report_error = $report_response->get_error_message();
    } elseif (
        isset($report_response['results']) &&
        is_array($report_response['results'])
    ) {
        $report = $report_response;
    } else {
        $report_error = 'The report does not contain viewport results.';
    }
}

$state_labels = array(
    'idle' => 'Ready',
    'pending' => 'Queued',
    'running' => 'Scanning',
    'completed' => 'Completed',
    'failed' => 'Failed',
    'error' => 'Unavailable',
);

$state_label = $state_labels[$state] ?? 'Unknown';

$state_class = in_array($state, array('completed'), true)
    ? 'dc-success'
    : (in_array($state, array('failed', 'error'), true)
        ? 'dc-error'
        : 'dc-neutral');

?>

<?php if (in_array($state, array('pending', 'running'), true)) : ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            window.setTimeout(function() {
                window.location.reload();
            }, 5000);
        });
    </script>
<?php endif; ?>

<div class="wrap dc-dashboard">

    <style>
        .dc-dashboard {
            max-width: 1180px;
            margin: 28px 24px 50px 4px;
            color: #172033;
        }

        .dc-dashboard * {
            box-sizing: border-box;
        }

        .dc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        .dc-eyebrow {
            margin: 0 0 8px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
        }

        .dc-dashboard .dc-title {
            margin: 0;
            font-size: 30px;
            line-height: 1.2;
            font-weight: 700;
            color: #111827;
        }

        .dc-subtitle {
            margin: 10px 0 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }

        .dc-version {
            padding: 7px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            color: #64748b;
            background: #fff;
            font-size: 12px;
            white-space: nowrap;
        }

        .dc-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 26px;
            margin-bottom: 20px;
            box-shadow: 0 3px 16px rgba(15, 23, 42, .025);
        }

        .dc-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .dc-dashboard .dc-card-title {
            margin: 0;
            color: #172033;
            font-size: 17px;
            font-weight: 650;
        }

        .dc-card-description {
            margin: 0 0 24px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.7;
        }

        .dc-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .dc-status::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .dc-neutral {
            color: #475569;
            background: #f1f5f9;
        }

        .dc-success {
            color: #047857;
            background: #ecfdf5;
        }

        .dc-error {
            color: #b91c1c;
            background: #fef2f2;
        }

        .dc-scan-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
        }

        .dc-scan-copy {
            max-width: 600px;
        }

        .dc-scan-copy p {
            color: #64748b;
            line-height: 1.7;
            margin-bottom: 0;
        }

        .dc-dashboard .dc-primary-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 22px;
            border: 1px solid #334de6;
            border-radius: 8px;
            background: #334de6;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .2s, transform .2s;
        }

        .dc-dashboard .dc-primary-button:hover,
        .dc-dashboard .dc-primary-button:focus {
            background: #243bc5;
            color: #fff;
        }

        .dc-dashboard .dc-primary-button:focus-visible {
            outline: 2px solid #111827;
            outline-offset: 3px;
        }

        .dc-dashboard .dc-primary-button:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .dc-dashboard .dc-secondary-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 16px;
            border: 1px solid #dbe2ea;
            border-radius: 7px;
            background: #fff;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .dc-dashboard .dc-secondary-button:hover {
            background: #f8fafc;
            color: #172033;
        }

        .dc-result-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 22px;
        }

        .dc-metric {
            padding: 20px;
            border: 1px solid #e9edf3;
            border-radius: 9px;
            background: #fbfcfe;
        }

        .dc-metric-label {
            display: block;
            margin-bottom: 12px;
            color: #64748b;
            font-size: 12px;
        }

        .dc-metric-value {
            color: #172033;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .dc-metric-value.dc-fail-text {
            color: #b91c1c;
        }

        .dc-metric-value.dc-pass-text {
            color: #047857;
        }

        .dc-empty {
            padding: 36px 20px;
            text-align: center;
            border: 1px dashed #dbe2ea;
            border-radius: 10px;
            background: #fcfdff;
        }

        .dc-empty-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            margin-bottom: 12px;
            border-radius: 12px;
            background: #eef2ff;
            color: #334de6;
        }

        .dc-empty-icon .dashicons {
            font-size: 23px;
            width: 23px;
            height: 23px;
        }

        .dc-empty h3 {
            margin: 0 0 8px;
            font-size: 15px;
        }

        .dc-empty p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }

        .dc-error-message {
            margin-top: 18px;
            padding: 13px 16px;
            border-left: 3px solid #dc2626;
            background: #fef2f2;
            color: #991b1b;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .dc-review-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .dc-review-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 180px;
        }

        .dc-review-group label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }

        .dc-review-group select {
            width: 100%;
            min-height: 42px;
            border: 1px solid #dbe2ea;
            border-radius: 8px;
            background: #fff;
            color: #172033;
            padding: 8px 12px;
        }

        .dc-preview-frame {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            min-height: 220px;
            padding: 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: auto;
        }

        .dc-preview-frame img {
            display: block;
            max-width: 100%;
            height: auto;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 25px rgba(15, 23, 42, .08);
        }

        .dc-preview-frame img[hidden] {
            display: none;
        }

        #dc-review-error {
            color: #b91c1c;
            padding: 20px;
        }

        /* Detailed viewport comparison */

        .dc-viewport-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .dc-viewport-card {
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fbfcfe;
        }

        .dc-viewport-card h3 {
            margin: 0 0 14px;
            font-size: 15px;
        }

        .dc-viewport-percent {
            font-size: 27px;
            font-weight: 700;
            margin: 8px 0;
        }

        .dc-progress-track {
            height: 7px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .dc-progress-fill {
            height: 100%;
            background: #dc2626;
        }

        .dc-progress-fill.is-pass {
            background: #059669;
        }

        .dc-viewport-note {
            color: #64748b;
            font-size: 12px;
            margin-top: 12px;
        }

        .dc-footer {
            margin-top: 22px;
            color: #94a3b8;
            font-size: 12px;
        }

        @media (max-width: 900px) {
            .dc-viewport-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 782px) {
            .dc-dashboard {
                margin-right: 12px;
            }

            .dc-header,
            .dc-scan-panel {
                align-items: flex-start;
                flex-direction: column;
            }

            .dc-card {
                padding: 20px;
            }

            .dc-result-grid {
                grid-template-columns: 1fr;
            }

            .dc-dashboard .dc-title {
                font-size: 25px;
            }
        }
    </style>

    <header class="dc-header">
        <div>
            <p class="dc-eyebrow">Visual Quality Assurance</p>
            <h1 class="dc-title">WP DesignChecker</h1>
            <p class="dc-subtitle">
                Monitor visual changes across your WordPress website.
            </p>
        </div>

        <span class="dc-version">Version 0.1.0</span>
    </header>

    <?php if ($notice) : ?>
        <div class="notice notice-info is-dismissible">
            <p><?php echo esc_html($notice); ?></p>
        </div>
    <?php endif; ?>

    <section class="dc-card" aria-labelledby="dc-scan-title">
        <div class="dc-card-header">
            <h2 id="dc-scan-title" class="dc-card-title">
                Visual Regression Scanner
            </h2>

            <span class="dc-status <?php echo esc_attr($state_class); ?>">
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
                    <?php disabled(in_array($state, array('pending', 'running'), true)); ?>>
                    <span
                        class="dashicons dashicons-visibility"
                        style="margin-right: 8px;"
                        aria-hidden="true"></span>
                    Run Check
                </button>
            </form>
        </div>
    </section>

    <section class="dc-card" aria-labelledby="dc-results-title">
        <div class="dc-card-header">
            <h2 id="dc-results-title" class="dc-card-title">
                Latest Scan Results
            </h2>

            <?php if ($scan_id) : ?>
                <a
                    class="dc-secondary-button"
                    href="<?php echo esc_url(admin_url('admin.php?page=wp-designchecker')); ?>">
                    Refresh Status
                </a>
            <?php endif; ?>
        </div>

        <?php if (!$scan_id) : ?>

            <div class="dc-empty">
                <div class="dc-empty-icon">
                    <span class="dashicons dashicons-camera"></span>
                </div>

                <h3>No scans yet</h3>
                <p>
                    Run your first design check to see
                    visual comparison results here.
                </p>
            </div>

        <?php else : ?>

            <div class="dc-result-grid">
                <div class="dc-metric">
                    <span class="dc-metric-label">Job Status</span>
                    <div class="dc-metric-value">
                        <?php echo esc_html($state_label); ?>
                    </div>
                </div>

                <div class="dc-metric">
                    <span class="dc-metric-label">Visual Result</span>

                    <div class="dc-metric-value <?php
                                                echo esc_attr(
                                                    $visual_result === 'FAIL'
                                                        ? 'dc-fail-text'
                                                        : ($visual_result === 'PASS'
                                                            ? 'dc-pass-text'
                                                            : '')
                                                );
                                                ?>">
                        <?php echo esc_html($visual_result ?? 'Pending'); ?>
                    </div>
                </div>

                <div class="dc-metric">
                    <span class="dc-metric-label">Viewports</span>
                    <div class="dc-metric-value">3</div>
                </div>
            </div>

            <?php if ($report && !empty($report['results'])) : ?>

                <h3 style="margin-top:28px;">
                    Viewport Comparison
                </h3>

                <p class="dc-card-description">
                    Percentage of pixels that differ from the approved baseline.
                </p>

                <div class="dc-viewport-grid">
                    <?php foreach ($report['results'] as $item) : ?>
                        <?php
                        if (!is_array($item)) {
                            continue;
                        }

                        $device = isset($item['viewport']) &&
                            is_string($item['viewport'])
                            ? $item['viewport']
                            : 'Unknown';

                        $status = isset($item['status']) &&
                            is_string($item['status'])
                            ? $item['status']
                            : 'ERROR';

                        $difference = isset($item['difference']) &&
                            is_numeric($item['difference'])
                            ? (float) $item['difference']
                            : null;

                        $width = $difference === null
                            ? 0
                            : max(0, min(100, $difference));
                        ?>

                        <article class="dc-viewport-card">
                            <h3>
                                <?php echo esc_html(ucfirst($device)); ?>
                            </h3>

                            <span class="dc-status <?php
                                                    echo esc_attr(
                                                        $status === 'PASS'
                                                            ? 'dc-success'
                                                            : 'dc-error'
                                                    );
                                                    ?>">
                                <?php echo esc_html($status); ?>
                            </span>

                            <div class="dc-viewport-percent <?php
                                                            echo esc_attr(
                                                                $status === 'PASS'
                                                                    ? 'dc-pass-text'
                                                                    : 'dc-fail-text'
                                                            );
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

    <?php if ($state === 'completed' && $report) : ?>

        <section class="dc-card" aria-labelledby="dc-review-title">
            <div class="dc-card-header">
                <div>
                    <h2 id="dc-review-title" class="dc-card-title">
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
                    <label for="dc-review-viewport">Viewport</label>
                    <select id="dc-review-viewport">
                        <option value="desktop">Desktop</option>
                        <option value="tablet">Tablet</option>
                        <option value="mobile">Mobile</option>
                    </select>
                </div>

                <div class="dc-review-group">
                    <label for="dc-review-mode">Screenshot</label>
                    <select id="dc-review-mode">
                        <option value="baselines">Approved Baseline</option>
                        <option value="current">Current Design</option>
                        <option value="diffs">Visual Difference</option>
                    </select>
                </div>
            </div>

            <div class="dc-preview-frame">
                <img
                    id="dc-review-image"
                    alt="WordPress design screenshot"
                    loading="lazy">
                <p id="dc-review-error" hidden role="alert">
                    Screenshot could not be loaded.
                </p>
            </div>
        </section>

        <?php
        $image_endpoint = add_query_arg(
            array(
                'action' => 'wp_designchecker_image',
                'nonce' => wp_create_nonce('wp_designchecker_image'),
            ),
            admin_url('admin-ajax.php')
        );
        ?>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const viewport = document.getElementById('dc-review-viewport');
                const mode = document.getElementById('dc-review-mode');
                const image = document.getElementById('dc-review-image');
                const error = document.getElementById('dc-review-error');

                if (!viewport || !mode || !image || !error) {
                    return;
                }

                const endpoint = <?php echo wp_json_encode($image_endpoint); ?>;

                function updatePreview() {
                    const url = new URL(endpoint);

                    url.searchParams.set('viewport', viewport.value);
                    url.searchParams.set('mode', mode.value);

                    image.hidden = true;
                    error.hidden = true;

                    image.onload = function() {
                        image.hidden = false;
                    };

                    image.onerror = function() {
                        image.hidden = true;
                        error.hidden = false;
                    };

                    image.src = url.toString();
                }

                viewport.addEventListener('change', updatePreview);
                mode.addEventListener('change', updatePreview);

                updatePreview();
            });
        </script>

    <?php endif; ?>

    <p class="dc-footer">
        WP DesignChecker · Visual Regression Testing for WordPress
    </p>

</div>