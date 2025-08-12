<?php
/**
 * Feed Processor for RSS Auto Publisher
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Feed_Processor {
    /**
     * Process a feed
     */
    public static function process_feed($feed_id) {
        $feed = RSP_Database::get_feed($feed_id);
        
        if (!$feed || !$feed->is_active) {
            return false;
        }
        
        // Fetch RSS items
        $items = self::fetch_feed_items($feed->feed_url);
        
        if (empty($items)) {
            return false;
        }
        
        $queued = 0;
        $limit = $feed->items_per_import;
        
        foreach ($items as $item) {
            if ($queued >= $limit) {
                break;
            }
            
            // Check if already processed
            if (RSP_Database::is_item_processed($feed_id, $item['guid'])) {
                continue;
            }
            
            // Check word count
            $word_count = str_word_count(strip_tags($item['content']));
            if ($word_count < $feed->min_word_count) {
                continue;
            }
            
            // Add to queue
            if (RSP_Queue::add_item($feed_id, 'process_item', $item)) {
                $queued++;
            }
        }
        
        // Update last checked time
        RSP_Database::update_feed($feed_id, [
            'last_checked' => current_time('mysql')
        ]);
        
        return $queued;
    }
    
    /**
     * Fetch feed items
     */
    private static function fetch_feed_items($feed_url) {
        // Include SimplePie
        require_once(ABSPATH . WPINC . '/feed.php');
        
        $feed = fetch_feed($feed_url);
        
        if (is_wp_error($feed)) {
            error_log('Feed error: ' . $feed->get_error_message());
            return [];
        }
        
        $max_items = $feed->get_item_quantity(20);
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
}
