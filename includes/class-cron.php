<?php
/**
 * Cron Handler for RSS Auto Publisher
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Cron {
    /**
     * Constructor
     */
    public function __construct() {
        // Register cron hooks
        add_action('rsp_process_queue', [__CLASS__, 'process_queue']);
        add_action('rsp_check_feeds', [__CLASS__, 'check_feeds']);
        
        // Dynamic feed check hooks
        add_action('rsp_check_feed', [__CLASS__, 'check_single_feed']);
    }
    
    /**
     * Schedule events on activation
     */
    public static function schedule_events() {
        // Queue processor - runs every 5 minutes
        if (!wp_next_scheduled('rsp_process_queue')) {
            wp_schedule_event(time(), 'rsp_5min', 'rsp_process_queue');
        }
        
        // Feed checker - runs hourly
        if (!wp_next_scheduled('rsp_check_feeds')) {
            wp_schedule_event(time(), 'hourly', 'rsp_check_feeds');
        }
    }
    
    /**
     * Clear events on deactivation
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('rsp_process_queue');
        wp_clear_scheduled_hook('rsp_check_feeds');
        
        // Clear individual feed schedules
        $feeds = RSP_Database::get_feeds();
        foreach ($feeds as $feed) {
            wp_clear_scheduled_hook('rsp_check_feed', [$feed->id]);
        }
    }
    
    /**
     * Process queue
     */
    public static function process_queue() {
        RSP_Queue::process_queue();
    }
    
    /**
     * Check all active feeds
     */
    public static function check_feeds() {
        $feeds = RSP_Database::get_feeds(['is_active' => 1]);
        
        foreach ($feeds as $feed) {
            // Check if it's time to process this feed
            if (self::should_process_feed($feed)) {
                RSP_Feed_Processor::process_feed($feed->id);
            }
        }
    }
    
    /**
     * Check single feed
     */
    public static function check_single_feed($feed_id) {
        RSP_Feed_Processor::process_feed($feed_id);
    }
    
    /**
     * Schedule a specific feed
     */
    public static function schedule_feed($feed_id, $frequency = 'hourly') {
        $hook = 'rsp_check_feed';
        $args = [$feed_id];
        
        // Clear existing schedule
        wp_clear_scheduled_hook($hook, $args);
        
        // Schedule new
        wp_schedule_event(time(), $frequency, $hook, $args);
    }
    
    /**
     * Check if feed should be processed
     */
    private static function should_process_feed($feed) {
        if (!$feed->last_checked) {
            return true;
        }
        
        $last_checked = strtotime($feed->last_checked);
        $now = time();
        
        switch ($feed->update_frequency) {
            case 'hourly':
                return ($now - $last_checked) >= 3600;
            case 'twicedaily':
                return ($now - $last_checked) >= 43200;
            case 'daily':
                return ($now - $last_checked) >= 86400;
            default:
                return false;
        }
    }
}

// Register custom cron schedules
add_filter('cron_schedules', function($schedules) {
    $schedules['rsp_5min'] = [
        'interval' => 300,
        'display' => __('Every 5 minutes', 'rss-auto-publisher')
    ];
    return $schedules;
});
