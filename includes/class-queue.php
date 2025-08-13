<?php
/**
 * Queue System for RSS Auto Publisher
 * Version 1.3.0 - Production ready with feed fixes
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
        
        // Validate feed_id
        if (empty($feed_id)) {
            error_log('RSS Auto Publisher: Cannot add queue item without feed_id');
            return false;
        }
        
        $table = $wpdb->prefix . 'rsp_queue';
        
        $result = $wpdb->insert($table, [
            'feed_id' => $feed_id,
            'type' => $type,
            'status' => 'pending',
            'data' => json_encode($data),
            'priority' => $priority,
            'created_at' => current_time('mysql')
        ]);
        
        return $result;
    }
    
    /**
     * Process queue
     */
    public static function process_queue() {
        global $wpdb;
        
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
        
        foreach ($items as $item) {
            self::process_queue_item($item);
        }
    }
    
    /**
     * Process single queue item
     */
    private static function process_queue_item($item) {
        global $wpdb;
        
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
            
            // Check if feed exists
            if (!$feed) {
                throw new Exception('Feed not found for ID: ' . $item->feed_id);
            }
            
            switch ($item->type) {
                case 'process_item':
                    $success = self::process_feed_item($feed, $data);
                    break;
                    
                case 'create_smart_content':
                    $success = self::create_smart_content($feed, $data);
                    break;
                    
                case 'enhance_content':
                    $success = self::enhance_content($feed, $data);
                    break;
                    
                case 'translate_content':
                    $success = self::translate_content($feed, $data, $item);
                    break;
                    
                case 'create_post':
                    $success = self::create_post($feed, $data);
                    break;
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            error_log('RSS Auto Publisher Queue Error: ' . $error_message);
        }
        
        // Update queue item status
        if ($success) {
            $wpdb->delete($table, ['id' => $item->id]);
        } else {
            $status = ($item->attempts >= $item->max_attempts) ? 'failed' : 'pending';
            
            $wpdb->update($table, [
                'status' => $status,
                'error_message' => $error_message
            ], ['id' => $item->id]);
        }
    }
    
    /**
     * Process feed item
     */
    private static function process_feed_item($feed, $data) {
        // Load feed settings
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
        
        $data['feed_settings'] = $feed_settings;
        
        if ($feed->enable_enhancement) {
            // Use smart content creation
            self::add_item($feed->id, 'create_smart_content', $data, 9);
        } elseif ($feed->enable_translation) {
            // Queue for translation
            $languages = json_decode($feed->target_languages, true) ?: [];
            foreach ($languages as $lang) {
                $data['target_language'] = $lang;
                self::add_item($feed->id, 'translate_content', $data, 8);
            }
        } else {
            // Queue for direct post creation
            self::add_item($feed->id, 'create_post', $data, 7);
        }
        
        return true;
    }
    
    /**
     * Create smart content using title-based analysis
     */
    private static function create_smart_content($feed, $data) {
        $openai = new RSP_OpenAI();
        
        // Check if rate limited
        if ($openai->is_rate_limited()) {
            return false;
        }
        
        if (!$openai->is_configured()) {
            // Skip enhancement, create post directly
            self::add_item($feed->id, 'create_post', $data, 7);
            return true;
        }
        
        // Use smart content creation from title
        $enhanced = $openai->create_content_from_title(
            $data['title'], 
            $data['excerpt'] ?? '', 
            $data['feed_settings']
        );
        
        if ($enhanced) {
            $data['title'] = $enhanced['title'];
            $data['content'] = $enhanced['content'];
            $data['enhanced'] = true;
            $data['smart_generated'] = true;
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
     * Enhance content (legacy method)
     */
    private static function enhance_content($feed, $data) {
        $openai = new RSP_OpenAI();
        
        if ($openai->is_rate_limited()) {
            return false;
        }
        
        if (!$openai->is_configured()) {
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
            self::add_item($feed->id, 'create_post', $data, 7);
        }
        
        return true;
    }
    
    /**
     * Translate content
     */
    private static function translate_content($feed, $data, $item) {
        $openai = new RSP_OpenAI();
        
        if ($openai->is_rate_limited()) {
            return false;
        }
        
        if (!$openai->is_configured() || empty($data['target_language'])) {
            return false;
        }
        
        // Make sure we have content to translate
        if (empty($data['content'])) {
            error_log('RSS Auto Publisher: No content to translate for item');
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
        
        // Use the feed_id from the queue item to ensure it's not null
        $feed_id = $item->feed_id ?? $feed->id ?? 0;
        
        if ($feed_id) {
            self::add_item($feed_id, 'create_post', $data, 7);
        } else {
            error_log('RSS Auto Publisher: Cannot queue post creation - no feed_id');
            return false;
        }
        
        return true;
    }
    
    /**
     * Create WordPress post
     */
    private static function create_post($feed, $data) {
        // Prepare post data
        $post_data = [
            'post_title' => wp_strip_all_tags($data['title']),
            'post_content' => $data['content'],
            'post_status' => $feed->post_status,
            'post_author' => $feed->author_id,
            'post_category' => [$feed->category_id],
            'post_date' => $data['date'] ?: current_time('mysql'),
        ];
        
        // Insert post
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            throw new Exception($post_id->get_error_message());
        }
        
        // Add meta data
        update_post_meta($post_id, '_rsp_feed_id', $feed->id);
        update_post_meta($post_id, '_rsp_source_url', $data['link']);
        update_post_meta($post_id, '_rsp_enhanced', isset($data['enhanced']) ? 'yes' : 'no');
        update_post_meta($post_id, '_rsp_smart_generated', isset($data['smart_generated']) ? 'yes' : 'no');
        
        if (isset($data['feed_settings'])) {
            update_post_meta($post_id, '_rsp_feed_settings', $data['feed_settings']);
        }
        
        if (isset($data['language'])) {
            update_post_meta($post_id, '_rsp_language', $data['language']);
        }
        
        // Extract and set featured image
        self::set_featured_image($post_id, $data['content']);
        
        // Mark as processed
        RSP_Database::mark_item_processed($feed->id, $data['guid'], $post_id);
        
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
        $image_data = @file_get_contents($image_url);
        
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
    
    /**
     * Clear queue
     */
    public static function clear_queue($status = 'all') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_queue';
        
        if ($status === 'all') {
            return $wpdb->query("TRUNCATE TABLE $table");
        } else {
            return $wpdb->delete($table, ['status' => $status]);
        }
    }
}
