
/**
 * WP DesignChecker — Dashboard Interactions
 *
 * Features:
 * - Automatic scan-status monitoring
 * - Dashboard refresh after scan completion
 * - Desktop, tablet, and mobile screenshot previews
 * - Screenshot loading and error handling
 * - Prevention of lazy-loading image deadlocks
 */

document.addEventListener('DOMContentLoaded', function () {

    /**
     * Monitor active scan jobs.
     *
     * Polls the WordPress dashboard every three seconds.
     * Reloads the page when a scan completes or fails.
     */
    function initializeScanRefresh() {
        const dashboard = document.querySelector('.dc-dashboard');

        // Exit when the DesignChecker dashboard is unavailable.
        if (!dashboard) {
            return;
        }

        // Only monitor pending or running scans.
        if (dashboard.dataset.scanActive !== 'true') {
            return;
        }

        let checking = false;

        const interval = window.setInterval(async function () {

            // Prevent overlapping status requests.
            if (checking) {
                return;
            }

            checking = true;

            try {
                // Request fresh dashboard content from WordPress.
                const response = await fetch(window.location.href, {
                    credentials: 'same-origin',
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Unable to retrieve scan status.');
                }

                // Parse the updated dashboard without displaying it.
                const html = await response.text();
                const parser = new DOMParser();

                const updatedDocument = parser.parseFromString(
                    html,
                    'text/html'
                );

                const updatedDashboard =
                    updatedDocument.querySelector('.dc-dashboard');

                if (!updatedDashboard) {
                    throw new Error('Dashboard content not found.');
                }

                // Check whether the scan is still active.
                const stillActive =
                    updatedDashboard.dataset.scanActive === 'true';

                // Reload once to display completed results and history.
                if (!stillActive) {
                    window.clearInterval(interval);
                    window.location.reload();
                }

            } catch (error) {
                console.error(
                    'WP DesignChecker scan monitoring:',
                    error
                );

            } finally {
                checking = false;
            }

        }, 3000);
    }


    /**
     * Initialize screenshot review.
     *
     * Allows administrators to switch between viewports
     * and screenshot types without reloading the dashboard.
     *
     * Prevents lazy-loading deadlocks by keeping the image
     * eligible for loading while temporarily hiding it visually.
     */
    function initializeScreenshotReview() {
        const viewport = document.getElementById('dc-review-viewport');
        const mode = document.getElementById('dc-review-mode');
        const image = document.getElementById('dc-review-image');
        const error = document.getElementById('dc-review-error');

        // Exit when the screenshot review section is unavailable.
        if (!viewport || !mode || !image || !error) {
            return;
        }

        // Retrieve the authenticated image endpoint from WordPress.
        const endpoint = window.wpDesignChecker?.imageEndpoint;

        if (!endpoint) {
            console.error(
                'WP DesignChecker: Image endpoint is missing.'
            );

            image.hidden = true;
            error.textContent = 'Screenshot service is not configured.';
            error.hidden = false;
            return;
        }

        // Disable lazy loading before requesting screenshots.
        image.loading = 'eager';

        // Allow the image to load even when it is not yet visible.
        image.hidden = false;
        image.style.visibility = 'hidden';

        /**
         * Load the selected screenshot.
         *
         * Builds the authenticated screenshot URL using
         * the selected viewport and screenshot type.
         *
         * Keeps the image in the document layout while loading
         * so the browser does not defer the image request.
         */
        function updatePreview() {
            const url = new URL(
                endpoint,
                window.location.origin
            );

            // Add the selected viewport and screenshot type.
            url.searchParams.set('viewport', viewport.value);
            url.searchParams.set('mode', mode.value);

            // Prevent caching of previously generated screenshots.
            url.searchParams.set('t', Date.now().toString());

            // Reset the previous error state.
            error.hidden = true;

            // Keep the image eligible for browser loading.
            image.hidden = false;
            image.loading = 'eager';
            image.style.visibility = 'hidden';

            /**
             * Display the screenshot after successful loading.
             */
            image.onload = function () {
                image.hidden = false;
                image.style.visibility = 'visible';

                error.hidden = true;
            };

            /**
             * Display a message when the screenshot fails to load.
             */
            image.onerror = function () {
                image.hidden = true;
                image.style.visibility = 'hidden';

                error.textContent =
                    'Screenshot could not be loaded.';

                error.hidden = false;

                console.error(
                    'WP DesignChecker: Screenshot loading failed.',
                    url.toString()
                );
            };

            // Request the selected screenshot.
            image.src = url.toString();
        }

        // Reload the preview when the viewport changes.
        viewport.addEventListener('change', updatePreview);

        // Reload the preview when the screenshot type changes.
        mode.addEventListener('change', updatePreview);

        // Display the default screenshot on dashboard initialization.
        updatePreview();
    }


    /**
     * Initialize all DesignChecker dashboard functionality.
     *
     * Each feature checks whether its required elements exist
     * before registering events or performing requests.
     */
    initializeScanRefresh();
    initializeScreenshotReview();

});
