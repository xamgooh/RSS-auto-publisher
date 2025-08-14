<?php
/**
 * Feed Processor for RSS Auto Publisher
 * Version 2.0.0 - Enforces 1 post per day per feed
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Feed_Processor {
    /**
     * Process a feed (limit to 1 post per day)
     */
    public static function process_feed($feed_id) {
        $feed = RSP_Database::get_feed($feed_id);
        
        if (!$feed || !$feed->is_active) {
            error_log('RSS Auto Publisher: Feed inactive or not found: ' . $feed_id);
            return false;
        }
        
        // Check if feed has already posted today
        if (RSP_Database::has_posted_today($feed_id)) {
            error_log('RSS Auto Publisher: Feed ' . $feed_id . ' has already posted today');
            return 0;
        }
        
        // Fetch RSS items
        $items = self::fetch_feed_items($feed->feed_url);
        
        if (empty($items)) {
            error_log('RSS Auto Publisher: No items found in feed: ' . $feed->feed_url);
            return false;
        }
        
        $queued = 0;
        
        // Process only ONE unprocessed item
        foreach ($items as $item) {
            // Check if already processed
            if (RSP_Database::is_item_processed($feed_id, $item['guid'])) {
                continue;
            }
            
            // Queue this single item
            if (RSP_Queue::add_item($feed_id, 'process_item', $item)) {
                $queued = 1;
                error_log('RSS Auto Publisher: Queued 1 item from feed ' . $feed_id);
                break; // Stop after queuing one item
            }
        }
        
        // Update last checked time
        RSP_Database::update_feed($feed_id, [
            'last_checked' => current_time('mysql')
        ]);
        
        return $queued;
    }
    
    /**
     * Process all active feeds that haven't posted today
     */
    public static function process_all_feeds() {
        $feeds = RSP_Database::get_feeds_available_for_posting();
        $total_queued = 0;
        
        foreach ($feeds as $feed) {
            // Process feed if it's time according to its schedule
            if (self::should_process_feed($feed)) {
                $queued = self::process_feed($feed->id);
                if ($queued > 0) {
                    $total_queued += $queued;
                }
            }
        }
        
        error_log('RSS Auto Publisher: Total items queued: ' . $total_queued);
        return $total_queued;
    }
    
    /**
     * Check if feed should be processed based on schedule
     */
    private static function should_process_feed($feed) {
        if (!$feed->last_checked) {
            return true;
        }
        
        $last_checked = strtotime($feed->last_checked);
        $now = time();
        
        switch ($feed->update_frequency) {
            case 'hourly':
                // Check hourly but still limited to 1 post per day
                return ($now - $last_checked) >= 3600;
            case 'twicedaily':
                // Check twice daily but still limited to 1 post per day
                return ($now - $last_checked) >= 43200;
            case 'daily':
                // Check once daily
                return ($now - $last_checked) >= 86400;
            default:
                return false;
        }
    }
    
    /**
     * Fetch feed items
     */
    private static function fetch_feed_items($feed_url) {
        // Include SimplePie
        require_once(ABSPATH . WPINC . '/feed.php');
        
        $feed = fetch_feed($feed_url);
        
        if (is_wp_error($feed)) {
            error_log('RSS Auto Publisher: Feed error for ' . $feed_url . ' - ' . $feed->get_error_message());
            return [];
        }
        
        // Get up to 10 items to have options, but we'll only process 1
        $max_items = $feed->get_item_quantity(10);
        $feed_items = $feed->get_items(0, $max_items);
        
        $items = [];
        
        foreach ($feed_items as $item) {
            $items[] = [
                'guid' => $item->get_id(),
                'title' => $item->get_title(),
                'content' => $item->get_content(),
                'excerpt' => $item->get_description(),
                'link' => $item->get_permalink(),
                'date' => $item->get_date('Y-m-d H:i:s'),
                'author' => $item->get_author() ? $item->get_author()->get_name() : ''
            ];
        }
        
        return $items;
    }
    
    /**
     * Force process a specific feed (admin action)
     */
    public static function force_process_feed($feed_id) {
        $feed = RSP_Database::get_feed($feed_id);
        
        if (!$feed) {
            return ['success' => false, 'message' => 'Feed not found'];
        }
        
        // Check if already posted today
        if (RSP_Database::has_posted_today($feed_id)) {
            return [
                'success' => false, 
                'message' => 'This feed has already posted today. Each feed is limited to 1 post per day.'
            ];
        }
        
        // Fetch RSS items
        $items = self::fetch_feed_items($feed->feed_url);
        
        if (empty($items)) {
            return ['success' => false, 'message' => 'No items found in feed'];
        }
        
        // Find first unprocessed item
        $item_to_process = null;
        foreach ($items as $item) {
            if (!RSP_Database::is_item_processed($feed_id, $item['guid'])) {
                $item_to_process = $item;
                break;
            }
        }
        
        if (!$item_to_process) {
            return ['success' => false, 'message' => 'No new items to process'];
        }
        
        // Queue with high priority for immediate processing
        if (RSP_Queue::add_item($feed_id, 'process_item', $item_to_process, 1)) {
            // Update last checked time
            RSP_Database::update_feed($feed_id, [
                'last_checked' => current_time('mysql')
            ]);
            
            return [
                'success' => true, 
                'message' => '1 item queued for immediate processing'
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to queue item'];
    }
    
    /**
     * Get feed processing status
     */
    public static function get_feed_status($feed_id) {
        global $wpdb;
        
        $feed = RSP_Database::get_feed($feed_id);
        
        if (!$feed) {
            return null;
        }
        
        $today = current_time('Y-m-d');
        $daily_posts_table = $wpdb->prefix . 'rsp_daily_posts';
        $queue_table = $wpdb->prefix . 'rsp_queue';
        
        // Get today's post if exists
        $todays_post = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $daily_posts_table WHERE feed_id = %d AND post_date = %s",
            $feed_id,
            $today
        ));
        
        // Get pending items in queue
        $pending_items = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $queue_table WHERE feed_id = %d AND status = 'pending'",
            $feed_id
        ));
        
        return [
            'feed_id' => $feed_id,
            'feed_name' => $feed->feed_name,
            'is_active' => $feed->is_active,
            'last_checked' => $feed->last_checked,
            'last_post_date' => $feed->last_post_date,
            'posted_today' => !empty($todays_post),
            'todays_post_id' => $todays_post ? $todays_post->post_id : null,
            'pending_in_queue' => $pending_items,
            'can_post_today' => !RSP_Database::has_posted_today($feed_id),
            'next_check' => self::get_next_check_time($feed)
        ];
    }
    
    /**
     * Calculate next check time based on frequency
     */
    private static function get_next_check_time($feed) {
        if (!$feed->last_checked) {
            return 'Now';
        }
        
        $last_checked = strtotime($feed->last_checked);
        
        switch ($feed->update_frequency) {
            case 'hourly':
                $next = $last_checked + 3600;
                break;
            case 'twicedaily':
                $next = $last_checked + 43200;
                break;
            case 'daily':
            default:
                $next = $last_checked + 86400;
                break;
        }
        
        if ($next <= time()) {
            return 'Now';
        }
        
        return human_time_diff(time(), $next) . ' from now';
    }
}
