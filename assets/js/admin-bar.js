jQuery(document).ready(function ($) {
    // Handle clear all cache button click
    $(document).on('click', '#wpadminbar #wp-admin-bar-wppo_clear_all_cache a', function (e) {
        e.preventDefault();

        var $button = $(this);
        var originalText = $button.text();

        // Show loading state
        $button.text('Clearing...').addClass('disabled');

        // Make AJAX request
        $.ajax({
            url: wppoAdminBar.ajaxurl,
            type: 'POST',
            data: {
                action: 'wppo_clear_all_cache',
                _wpnonce: wppoAdminBar.nonce
            },
            success: function (response) {
                if (response.success) {
                    $button.text('Cache Cleared!');
                    setTimeout(function () {
                        window.location.reload();
                    }, 500);
                } else {
                    $button.text(originalText).removeClass('disabled');
                    alert(response.data || 'Failed to clear cache');
                }
            },
            error: function (xhr, status, error) {
                // Restore button text
                $button.text(originalText).removeClass('disabled');

                // Show error message
                alert('Failed to clear cache. Please try again.');
                console.error('Cache clear error:', error);
            }
        });
    });

    // Handle clear this page cache button click
    $(document).on('click', '#wpadminbar #wp-admin-bar-wppo_clear_this_page_cache a', function (e) {
        e.preventDefault();

        var $button = $(this);
        var originalText = $button.text();

        // Show loading state
        $button.text('Clearing...').addClass('disabled');

        // Make AJAX request
        $.ajax({
            url: wppoAdminBar.ajaxurl,
            type: 'POST',
            data: {
                action: 'wppo_clear_page_cache',
                page_url: window.location.href,
                _wpnonce: wppoAdminBar.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Show success message
                    $button.text('Cache Cleared!');
                    setTimeout(function () {
                        $button.text(originalText).removeClass('disabled');
                    }, 2000);
                } else {
                    $button.text(originalText).removeClass('disabled');
                    alert(response.data || 'Failed to clear page cache');
                }
            },
            error: function (xhr, status, error) {
                // Restore button text
                $button.text(originalText).removeClass('disabled');

                // Show error message
                alert('Failed to clear page cache. Please try again.');
                console.error('Page cache clear error:', error);
            }
        });
    });

    // Refresh cache size periodically
    function refreshCacheSize() {
        $.get(wppoAdminBar.ajaxurl, {
            action: 'wppo_get_cache_stats',
            _wpnonce: wppoAdminBar.nonce
        }, function (response) {
            if (response.success && response.data && response.data.total_size_formatted) {
                $('.wppo-cache-size').text('(' + response.data.total_size_formatted + ')');
            }
        }).fail(function (error) {
            console.error('Failed to refresh cache size:', error);
        });
    }

    // Refresh cache size every 30 seconds
    setInterval(refreshCacheSize, 30000);
});
