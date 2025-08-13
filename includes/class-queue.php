<?php
/**
 * Queue System for RSS Auto Publisher
 * DEBUG VERSION - REMOVE DEBUG CODE AFTER TESTING
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Queue {
    /**
     * Initialize queue system
     */
    public static function init() {
        // Register queue processor
        add_action('rsp_process_queue', [__CLASS__, 'process_queue']);
    }
    
    /**
     * Add item to queue
     */
    public static function add_item($feed_id, $type, $data, $priority = 10) {
        global $wpdb;
        
        error_log('RSP DEBUG: Adding item to queue - Feed: ' . $feed_id . ', Type: ' . $type . ', Priority: ' . $priority);
        
        $table = $wpdb->prefix . 'rsp_queue';
        
        $result = $wpdb->insert($table, [
            'feed_id' => $feed_id,
            'type' => $type,
            'status' => 'pending',
            'data' => json_encode($data),
            'priority' => $priority,
            'created_at' => current_time('mysql')
        ]);
        
        error_log('RSP DEBUG: Queue item added: ' . ($result ? 'SUCCESS' : 'FAILED'));
        return $result;
    }
    
    /**
     * Process queue
     */
    public static function process_queue() {
        global $wpdb;
        
        error_log('RSP DEBUG: Processing queue started');
        
        $table = $wpdb->prefix . 'rsp_queue';
        $batch_size = get_option('rsp_queue_batch_size', 5);
        
        // Get pending items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE status = 'pending'
             AND attempts < max_attempts
             ORDER BY priority DESC, created_at ASC
             LIMIT %d",
            $batch_size
        ));
        
        error_log('RSP DEBUG: Found ' . count($items) . ' pending items to process');
        
        foreach ($items as $item) {
            error_log('RSP DEBUG: Processing queue item ID: ' . $item->id . ', Type: ' . $item->type);
            self::process_queue_item($item);
        }
        
        error_log('RSP DEBUG: Queue processing completed');
    }
    
    /**
     * Process single queue item
     */
    private static function process_queue_item($item) {
        global $wpdb;
        
        error_log('RSP DEBUG: Processing single item - ID: ' . $item->id . ', Type: ' . $item->type . ', Attempts: ' . $item->attempts);
        
        $table = $wpdb->prefix . 'rsp_queue';
        
        // Update status to processing
        $wpdb->update($table, [
            'status' => 'processing',
            'attempts' => $item->attempts + 1,
            'processed_at' => current_time('mysql')
        ], ['id' => $item->id]);
        
        $success = false;
        $error_message = '';
        
        try {
            $data = json_decode($item->data, true);
            $feed = RSP_Database::get_feed($item->feed_id);
            
            error_log('RSP DEBUG: Decoded data keys: ' . implode(', ', array_keys($data)));
            error_log('RSP DEBUG: Feed enhancement enabled: ' . ($feed->enable_enhancement ? 'YES' : 'NO'));
            
            switch ($item->type) {
                case 'process_item':
                    error_log('RSP DEBUG: Processing item type: process_item');
                    $success = self::process_feed_item($feed, $data);
                    break;
                    
                case 'create_smart_content':
                    error_log('RSP DEBUG: Processing item type: create_smart_content');
                    $success = self::create_smart_content($feed, $data);
                    break;
                    
                case 'enhance_content':
                    error_log('RSP DEBUG: Processing item type: enhance_content (legacy)');
                    $success = self::enhance_content($feed, $data);
                    break;
                    
                case 'translate_content':
                    error_log('RSP DEBUG: Processing item type: translate_content');
                    $success = self::translate_content($feed, $data);
                    break;
                    
                case 'create_post':
                    error_log('RSP DEBUG: Processing item type: create_post');
                    $success = self::create_post($feed, $data);
                    break;
            }
            
            error_log('RSP DEBUG: Item processing result: ' . ($success ? 'SUCCESS' : 'FAILED'));
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            error_log('RSP DEBUG: Exception during processing: ' . $error_message);
        }
        
        // Update queue item status
        if ($success) {
            error_log('RSP DEBUG: Removing successful item from queue');
            $wpdb->delete($table, ['id' => $item->id]);
        } else {
            $status = ($item->attempts >= $item->max_attempts) ? 'failed' : 'pending';
            error_log('RSP DEBUG: Setting item status to: ' . $status);
            
            $wpdb->update($table, [
                'status' => $status,
                'error_message' => $error_message
            ], ['id' => $item->id]);
        }
    }
    
    /**
     * Process feed item (updated to use new smart content creation)
     */
    private static function process_feed_item($feed, $data) {
        error_log('RSP DEBUG: process_feed_item called for feed ' . $feed->id);
        
        // Load feed settings (including new options)
        $feed_settings = [
            'content_domain' => $feed->content_domain ?? 'auto',
            'content_angle' => $feed->content_angle ?? 'auto',
            'seo_focus' => $feed->seo_focus ?? 'informational',
            'target_keywords' => $feed->target_keywords ?? '',
            'content_length' => $feed->content_length ?? '900-1500',
            'target_audience' => $feed->target_audience ?? '',
            'enhancement_prompt' => $feed->enhancement_prompt ?? '',
            'universal_prompt' => $feed->universal_prompt ?? ''
        ];
        
        error_log('RSP DEBUG: Feed settings: ' . json_encode($feed_settings));
        error_log('RSP DEBUG: Enhancement enabled: ' . ($feed->enable_enhancement ? 'YES' : 'NO'));
        error_log('RSP DEBUG: Translation enabled: ' . ($feed->enable_translation ? 'YES' : 'NO'));
        
        if ($feed->enable_enhancement) {
            error_log('RSP DEBUG: Queueing for smart content creation');
            // Use new smart content creation
            $data['feed_settings'] = $feed_settings;
            self::add_item($feed->id, 'create_smart_content', $data, 9);
        } elseif ($feed->enable_translation) {
            error_log('RSP DEBUG: Queueing for translation');
            // Queue for translation
            $languages = json_decode($feed->target_languages, true) ?: [];
            foreach ($languages as $lang) {
                $data['target_language'] = $lang;
                self::add_item($feed->id, 'translate_content', $data, 8);
            }
        } else {
            error_log('RSP DEBUG: Queueing for direct post creation');
            // Queue for direct post creation
            self::add_item($feed->id, 'create_post', $data, 7);
        }
        
        return true;
    }
    
    /**
     * Create smart content using new title-based analysis
     */
    private static function create_smart_content($feed, $data) {
        error_log('RSP DEBUG: create_smart_content called for feed ' . $feed->id);
        error_log('RSP DEBUG: Data title: ' . $data['title']);
        error_log('RSP DEBUG: Data excerpt: ' . ($data['excerpt'] ?? 'NO EXCERPT'));
        
        $openai = new RSP_OpenAI();
        
        // Check if rate limited
        if ($openai->is_rate_limited()) {
            error_log('RSP DEBUG: GPT-5 rate limited, will retry later');
            return false;
        }
        
        if (!$openai->is_configured()) {
            error_log('RSP DEBUG: OpenAI not configured, skipping to post creation');
            // Skip enhancement, create post directly
            self::add_item($feed->id, 'create_post', $data, 7);
            return true;
        }
        
        error_log('RSP DEBUG: Calling GPT-5 for content creation');
        
        // Use new smart content creation from title
        $enhanced = $openai->create_content_from_title(
            $data['title'], 
            $data['excerpt'] ?? '', 
            $data['feed_settings']
        );
        
        if ($enhanced) {
            error_log('RSP DEBUG: GPT-5 enhancement successful');
            error_log('RSP DEBUG: Enhanced title: ' . $enhanced['title']);
            error_log('RSP DEBUG: Enhanced content length: ' . strlen($enhanced['content']));
            
            $data['title'] = $enhanced['title'];
            $data['content'] = $enhanced['content'];
            $data['enhanced'] = true;
            $data['smart_generated'] = true; // Flag to indicate this was smart-generated
        } else {
            error_log('RSP DEBUG: GPT-5 enhancement failed, using original content');
        }
        
        // Check if translation is needed
        if ($feed->enable_translation) {
            error_log('RSP DEBUG: Queueing for translation');
            $languages = json_decode($feed->target_languages, true) ?: [];
            foreach ($languages as $lang) {
                $data['target_language'] = $lang;
                self::add_item($feed->id, 'translate_content', $data, 8);
            }
        } else {
            error_log('RSP DEBUG: Queueing for post creation');
            // Queue for post creation
            self::add_item($feed->id, 'create_post', $data, 7);
        }
        
        return true;
    }
    
    /**
     * Enhance content (legacy method for backwards compatibility)
     */
    private static function enhance_content($feed, $data) {
        error_log('RSP DEBUG: enhance_content called (legacy method)');
        
        $openai = new RSP_OpenAI();
        
        // Check if rate limited
        if ($openai->is_rate_limited()) {
            error_log('GPT-5 rate limited, will retry later');
            return false;
        }
        
        if (!$openai->is_configured()) {
            // Skip enhancement, create post directly
            self::add_item($feed->id, 'create_post', $data, 7);
            return true;
        }
        
        $options = [
            'custom_prompt' => $feed->enhancement_prompt
        ];
        
        $enhanced = $openai->enhance_content(
            $data['title'], 
            $data['content'], 
            $options
        );
        
        if ($enhanced) {
            $data['title'] = $enhanced['title'];
            $data['content'] = $enhanced['content'];
            $data['enhanced'] = true;
        }
        
        // Check if translation is needed
        if ($feed->enable_translation) {
            $languages = json_decode($feed->target_languages, true) ?: [];
            foreach ($languages as $lang) {
                $data['target_language'] = $lang;
                self::add_item($feed->id, 'translate_content', $data, 8);
            }
        } else {
            // Queue for post creation
            self::add_item($feed->id, 'create_post', $data, 7);
        }
        
        return true;
    }
    
    /**
     * Translate content
     */
    private static function translate_content($feed, $data) {
        error_log('RSP DEBUG: translate_content called');
        
        $openai = new RSP_OpenAI();
        
        // Check if rate limited
        if ($openai->is_rate_limited()) {
            error_log('GPT-5 rate limited, will retry later');
            return false;
        }
        
        if (!$openai->is_configured() || empty($data['target_language'])) {
            return false;
        }
        
        $translated = $openai->translate_content(
            $data['title'],
            $data['content'],
            $data['target_language']
        );
        
        if ($translated) {
            $data['title'] = $translated['title'];
            $data['content'] = $translated['content'];
            $data['language'] = $data['target_language'];
        }
        
        // Queue for post creation
        self::add_item($feed->id, 'create_post', $data, 7);
        
        return true;
    }
    
    /**
     * Create WordPress post
     */
    private static function create_post($feed, $data) {
        error_log('RSP DEBUG: create_post called');
        error_log('RSP DEBUG: Post title: ' . $data['title']);
        error_log('RSP DEBUG: Post content length: ' . strlen($data['content']));
        error_log('RSP DEBUG: Content preview: ' . substr(strip_tags($data['content']), 0, 200) . '...');
        
        // Prepare post data
        $post_data = [
            'post_title' => wp_strip_all_tags($data['title']),
            'post_content' => $data['content'],
            'post_status' => $feed->post_status,
            'post_author' => $feed->author_id,
            'post_category' => [$feed->category_id],
            'post_date' => $data['date'] ?: current_time('mysql'),
        ];
        
        error_log('RSP DEBUG: WordPress post data prepared');
        
        // Insert post
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            error_log('RSP DEBUG: WordPress post creation failed: ' . $post_id->get_error_message());
            throw new Exception($post_id->get_error_message());
        }
        
        error_log('RSP DEBUG: WordPress post created successfully with ID: ' . $post_id);
        
        // Add meta data
        update_post_meta($post_id, '_rsp_feed_id', $feed->id);
        update_post_meta($post_id, '_rsp_source_url', $data['link']);
        update_post_meta($post_id, '_rsp_enhanced', isset($data['enhanced']) ? 'yes' : 'no');
        update_post_meta($post_id, '_rsp_smart_generated', isset($data['smart_generated']) ? 'yes' : 'no');
        
        // Store feed settings used for this post
        if (isset($data['feed_settings'])) {
            update_post_meta($post_id, '_rsp_feed_settings', $data['feed_settings']);
        }
        
        if (isset($data['language'])) {
            update_post_meta($post_id, '_rsp_language', $data['language']);
        }
        
        // Store content domain and angle
        if (isset($feed->content_domain)) {
            update_post_meta($post_id, '_rsp_content_domain', $feed->content_domain);
        }
        
        if (isset($feed->content_angle)) {
            update_post_meta($post_id, '_rsp_content_angle', $feed->content_angle);
        }
        
        // Extract and set featured image
        self::set_featured_image($post_id, $data['content']);
        
        // Mark as processed
        RSP_Database::mark_item_processed($feed->id, $data['guid'], $post_id);
        
        error_log('RSP DEBUG: Post creation completed successfully');
        return true;
    }
    
    /**
     * Set featured image from content
     */
    private static function set_featured_image($post_id, $content) {
        // Extract first image from content
        preg_match('/<img.+?src=[\'"]([^\'"]+)[\'"].*?>/i', $content, $matches);
        
        if (empty($matches[1])) {
            return;
        }
        
        $image_url = $matches[1];
        
        // Download and attach image
        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        
        if (!$image_data) {
            return;
        }
        
        $filename = basename($image_url);
        
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        
        file_put_contents($file, $image_data);
        
        // Create attachment
        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        
        if (!is_wp_error($attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
            wp_update_attachment_metadata($attach_id, $attach_data);
            set_post_thumbnail($post_id, $attach_id);
        }
    }
    
    /**
     * Get queue stats
     */
    public static function get_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_queue';
        
        return $wpdb->get_row(
            "SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(*) as total
             FROM $table"
        );
    }
}
