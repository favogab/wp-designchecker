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

<?php endif; ?>

<div
    class="wrap dc-dashboard"
    data-scan-active="<?php
                        echo esc_attr(
                            in_array($state, array('pending', 'running'), true)
                                ? 'true'
                                : 'false'
                        );
                        ?>">

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

    <?php endif; ?>

    <p class="dc-footer">
        WP DesignChecker · Visual Regression Testing for WordPress
    </p>

</div>