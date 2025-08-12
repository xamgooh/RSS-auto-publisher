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
    
    // Edit feed
    $(document).on('click', '.edit-feed', function() {
        var $btn = $(this);
        var feedId = $btn.data('feed-id');
        
        $btn.prop('disabled', true).text('Loading...');
        
        $.post(rspAjax.ajaxUrl, {
            action: 'rsp_get_feed',
            feed_id: feedId,
            nonce: rspAjax.nonce
        })
        .done(function(response) {
            if (response.success) {
                var feed = response.data;
                
                // Scroll to form
                $('html, body').animate({
                    scrollTop: $('#rsp-feed-form').offset().top - 50
                }, 500);
                
                // Update form title
                $('#feed-form-title').text('Edit Feed');
                
                // Update form action
                $('#form-action').val('rsp_update_feed');
                $('#feed_id').val(feed.id);
                
                // Populate form fields
                $('#feed_url').val(feed.feed_url);
                $('#feed_name').val(feed.feed_name);
                $('#category_id').val(feed.category_id);
                $('#author_id').val(feed.author_id);
                $('#post_status').val(feed.post_status);
                $('#min_word_count').val(feed.min_word_count);
                $('#items_per_import').val(feed.items_per_import);
                $('#update_frequency').val(feed.update_frequency);
                $('#enhancement_prompt').val(feed.enhancement_prompt);
                
                // Handle checkboxes
                $('#enable_enhancement').prop('checked', feed.enable_enhancement == 1);
                $('#enable-translation').prop('checked', feed.enable_translation == 1);
                
                // Handle translation languages
                if (feed.enable_translation == 1) {
                    $('#language-options').show();
                    
                    // Uncheck all languages first
                    $('.language-checkbox').prop('checked', false);
                    
                    // Check selected languages
                    if (feed.target_languages && feed.target_languages.length > 0) {
                        $.each(feed.target_languages, function(i, lang) {
                            $('input[name="target_languages[]"][value="' + lang + '"]').prop('checked', true);
                        });
                    }
                } else {
                    $('#language-options').hide();
                }
                
                // Update submit button
                $('#submit-btn').text('Update Feed');
                
                // Show cancel button
                $('#cancel-edit').show();
            } else {
                alert('Error loading feed data: ' + response.data);
            }
        })
        .fail(function() {
            alert('Error loading feed data');
        })
        .always(function() {
            $btn.prop('disabled', false).text('Edit');
        });
    });
    
    // Cancel edit
    $('#cancel-edit').on('click', function() {
        // Reset form
        $('#rsp-feed-form')[0].reset();
        
        // Reset form title
        $('#feed-form-title').text('Add New Feed');
        
        // Reset form action
        $('#form-action').val('rsp_add_feed');
        $('#feed_id').val('');
        
        // Reset submit button
        $('#submit-btn').text('Add Feed');
        
        // Hide cancel button
        $('#cancel-edit').hide();
        
        // Hide language options
        $('#language-options').hide();
        
        // Scroll to top of form
        $('html, body').animate({
            scrollTop: $('#rsp-feed-form').offset().top - 50
        }, 500);
    });
    
    // Process feed
    $(document).on('click', '.process-feed', function() {
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
    $(document).on('click', '.toggle-feed', function() {
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
    $(document).on('click', '.delete-feed', function() {
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
    
    // Handle responsive table on window resize
    $(window).on('resize', function() {
        handleResponsiveTable();
    });
    
    function handleResponsiveTable() {
        var width = $(window).width();
        
        if (width <= 782) {
            // Mobile view - ensure cards are visible
            $('.rsp-feeds-container').show();
            $('.wp-list-table').hide();
        } else {
            // Desktop view - ensure table is visible
            $('.rsp-feeds-container').hide();
            $('.wp-list-table').show();
        }
    }
    
    // Initial check
    handleResponsiveTable();
});
