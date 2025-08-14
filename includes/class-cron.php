<?php
/**
 * Cron Handler for RSS Auto Publisher
 * Version 2.0.0 - Daily limit enforced
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
        add_action('rsp_reset_daily_counters', [__CLASS__, 'reset_daily_counters']);
        
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
        
        // Daily counter reset - runs at midnight
        if (!wp_next_scheduled('rsp_reset_daily_counters')) {
            // Schedule for midnight local time
            $timestamp = strtotime('tomorrow midnight');
            wp_schedule_event($timestamp, 'daily', 'rsp_reset_daily_counters');
        }
    }
    
    /**
     * Clear events on deactivation
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('rsp_process_queue');
        wp_clear_scheduled_hook('rsp_check_feeds');
        wp_clear_scheduled_hook('rsp_reset_daily_counters');
        
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
     * Check all active feeds that haven't posted today
     */
    public static function check_feeds() {
        // Get feeds that are active and haven't posted today
        $feeds = RSP_Database::get_feeds_available_for_posting();
        
        $processed = 0;
        foreach ($feeds as $feed) {
            // Check if it's time to process this feed based on its schedule
            if (self::should_process_feed($feed)) {
                $queued = RSP_Feed_Processor::process_feed($feed->id);
                if ($queued > 0) {
                    $processed++;
                    error_log('RSS Auto Publisher: Queued item from feed ' . $feed->id);
                }
            }
        }
        
        if ($processed > 0) {
            error_log('RSS Auto Publisher: Processed ' . $processed . ' feeds in scheduled check');
        }
    }
    
    /**
     * Check single feed
     */
    public static function check_single_feed($feed_id) {
        // Check if feed has already posted today
        if (RSP_Database::has_posted_today($feed_id)) {
            error_log('RSS Auto Publisher: Feed ' . $feed_id . ' already posted today, skipping');
            return;
        }
        
        RSP_Feed_Processor::process_feed($feed_id);
    }
    
    /**
     * Reset daily counters at midnight
     */
    public static function reset_daily_counters() {
        RSP_Database::reset_daily_counters();
        error_log('RSS Auto Publisher: Daily counters reset');
        
        // Clean up old data (older than 90 days)
        RSP_Database::cleanup_old_data(90);
    }
    
    /**
     * Schedule a specific feed
     */
    public static function schedule_feed($feed_id, $frequency = 'daily') {
        $hook = 'rsp_check_feed';
        $args = [$feed_id];
        
        // Clear existing schedule
        wp_clear_scheduled_hook($hook, $args);
        
        // Schedule new check
        wp_schedule_event(time(), $frequency, $hook, $args);
    }
    
    /**
     * Check if feed should be processed
     */
    private static function should_process_feed($feed) {
        // First check if feed has posted today
        if (RSP_Database::has_posted_today($feed->id)) {
            return false;
        }
        
        // Then check if enough time has passed since last check
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
                // Check twice daily
                return ($now - $last_checked) >= 43200;
            case 'daily':
                // Check once daily
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
