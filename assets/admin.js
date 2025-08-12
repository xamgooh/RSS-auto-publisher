jQuery(document).ready(function($) {
    // Test feed
    $('#test-feed').on('click', function() {
        var $btn = $(this);
        var feedUrl = $('#feed_url').val();
        
        if (!feedUrl) {
            alert('Please enter a feed URL');
            return;
        }
        
        $btn.prop('disabled', true).text('Testing...');
        $('#test-results').removeClass('success error').empty();
        
        $.post(rspAjax.ajaxUrl, {
            action: 'rsp_test_feed',
            feed_url: feedUrl,
            nonce: rspAjax.nonce
        })
        .done(function(response) {
            if (response.success) {
                var html = '<strong>' + response.data.message + '</strong><ul>';
                $.each(response.data.items, function(i, item) {
                    html += '<li>' + item.title + ' (' + item.date + ')</li>';
                });
                html += '</ul>';
                $('#test-results').addClass('success').html(html);
            } else {
                $('#test-results').addClass('error').text(response.data);
            }
        })
        .always(function() {
            $btn.prop('disabled', false).text('Test Feed');
        });
    });
    
    // Toggle language options
    $('#enable-translation').on('change', function() {
        if ($(this).is(':checked')) {
            $('#language-options').slideDown();
        } else {
            $('#language-options').slideUp();
        }
    });
    
    // Process feed
    $('.process-feed').on('click', function() {
        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        
        $btn.prop('disabled', true).text('Processing...');
        
        $.post(rspAjax.ajaxUrl, {
            action: 'rsp_process_feed',
            feed_id: feedId,
            nonce: rspAjax.nonce
        })
        .done(function(response) {
            if (response.success) {
                alert(response.data.message);
            } else {
                alert('Error: ' + response.data);
            }
        })
        .always(function() {
            $btn.prop('disabled', false).text('Process');
        });
    });
    
    // Toggle feed
    $('.toggle-feed').on('click', function() {
        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        var isActive = $btn.data('active');
        
        $.post(rspAjax.ajaxUrl, {
            action: 'rsp_toggle_feed',
            feed_id: feedId,
            nonce: rspAjax.nonce
        })
        .done(function(response) {
            if (response.success) {
                location.reload();
            }
        });
    });
    
    // Delete feed
    $('.delete-feed').on('click', function() {
        if (!confirm('Are you sure you want to delete this feed?')) {
            return;
        }
        
        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        
        $.post(rspAjax.ajaxUrl, {
            action: 'rsp_delete_feed',
            feed_id: feedId,
            nonce: rspAjax.nonce
        })
        .done(function(response) {
            if (response.success) {
                location.reload();
            }
        });
    });
    
    // Process queue now
    $('#process-queue-now').on('click', function() {
        var $btn = $(this);
        
        $btn.prop('disabled', true).text('Processing...');
        
        $.post(rspAjax.ajaxUrl, {
            action: 'rsp_process_queue_now',
            nonce: rspAjax.nonce
        })
        .done(function(response) {
            alert('Queue processed');
            location.reload();
        })
        .always(function() {
            $btn.prop('disabled', false).text('Process Queue Now');
        });
    });
    
    // Show/hide notices
    setTimeout(function() {
        $('.notice-success').fadeOut();
    }, 5000);
});
