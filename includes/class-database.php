<?php
/**
 * Database handler for RSS Auto Publisher
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Database {
    /**
     * Database version
     */
    const DB_VERSION = '1.1.0';
    
    /**
     * Initialize database
     */
    public static function init() {
        // Check if tables need updating
        if (get_option('rsp_db_version') !== self::DB_VERSION) {
            self::create_tables();
        }
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Feeds table with new columns
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        $sql_feeds = "CREATE TABLE $feeds_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_url text NOT NULL,
            feed_name varchar(255) DEFAULT '',
            category_id bigint(20) NOT NULL,
            author_id bigint(20) DEFAULT 1,
            post_status varchar(20) DEFAULT 'draft',
            min_word_count int DEFAULT 300,
            enable_enhancement tinyint(1) DEFAULT 1,
            enable_translation tinyint(1) DEFAULT 0,
            target_languages text,
            enhancement_prompt text,
            update_frequency varchar(20) DEFAULT 'hourly',
            items_per_import int DEFAULT 5,
            is_active tinyint(1) DEFAULT 1,
            last_checked datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            content_domain varchar(50) DEFAULT 'auto',
            content_angle varchar(50) DEFAULT 'auto',
            seo_focus varchar(50) DEFAULT 'informational',
            target_keywords text,
            content_length varchar(20) DEFAULT '900-1500',
            target_audience varchar(255) DEFAULT '',
            universal_prompt text,
            PRIMARY KEY (id),
            KEY is_active (is_active),
            KEY update_frequency (update_frequency)
        ) $charset_collate;";
        
        // Queue table
        $queue_table = $wpdb->prefix . 'rsp_queue';
        $sql_queue = "CREATE TABLE $queue_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) NOT NULL,
            type varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            data longtext NOT NULL,
            priority int DEFAULT 10,
            attempts int DEFAULT 0,
            max_attempts int DEFAULT 3,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status_priority (status, priority),
            KEY feed_id (feed_id)
        ) $charset_collate;";
        
        // Processed items table
        $processed_table = $wpdb->prefix . 'rsp_processed';
        $sql_processed = "CREATE TABLE $processed_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) NOT NULL,
            item_guid varchar(255) NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            processed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY feed_item (feed_id, item_guid),
            KEY post_id (post_id)
        ) $charset_collate;";
        
        // API usage tracking
        $api_usage_table = $wpdb->prefix . 'rsp_api_usage';
        $sql_api_usage = "CREATE TABLE $api_usage_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_service varchar(50) NOT NULL,
            endpoint varchar(100),
            tokens_used int DEFAULT 0,
            cost_estimate decimal(10,6) DEFAULT 0,
            success tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY api_service (api_service),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_feeds);
        dbDelta($sql_queue);
        dbDelta($sql_processed);
        dbDelta($sql_api_usage);
        
        // Add new columns to existing table if upgrading
        $existing_columns = $wpdb->get_col("DESC $feeds_table", 0);
        $new_columns = [
            'content_domain' => "ALTER TABLE $feeds_table ADD COLUMN content_domain varchar(50) DEFAULT 'auto'",
            'content_angle' => "ALTER TABLE $feeds_table ADD COLUMN content_angle varchar(50) DEFAULT 'auto'",
            'seo_focus' => "ALTER TABLE $feeds_table ADD COLUMN seo_focus varchar(50) DEFAULT 'informational'",
            'target_keywords' => "ALTER TABLE $feeds_table ADD COLUMN target_keywords text",
            'content_length' => "ALTER TABLE $feeds_table ADD COLUMN content_length varchar(20) DEFAULT '900-1500'",
            'target_audience' => "ALTER TABLE $feeds_table ADD COLUMN target_audience varchar(255) DEFAULT ''",
            'universal_prompt' => "ALTER TABLE $feeds_table ADD COLUMN universal_prompt text"
        ];
        
        foreach ($new_columns as $column => $sql) {
            if (!in_array($column, $existing_columns)) {
                $wpdb->query($sql);
            }
        }
        
        update_option('rsp_db_version', self::DB_VERSION);
    }
    
    /**
     * Get all feeds
     */
    public static function get_feeds($args = []) {
        global $wpdb;
        
        $defaults = [
            'is_active' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $table = $wpdb->prefix . 'rsp_feeds';
        $query = "SELECT * FROM $table WHERE 1=1";
        
        if ($args['is_active'] !== null) {
            $query .= $wpdb->prepare(" AND is_active = %d", $args['is_active']);
        }
        
        $query .= " ORDER BY {$args['orderby']} {$args['order']}";
        
        if ($args['limit'] > 0) {
            $query .= $wpdb->prepare(" LIMIT %d", $args['limit']);
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get single feed
     */
    public static function get_feed($feed_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $feed_id
        ));
    }
    
    /**
     * Add feed
     */
    public static function add_feed($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        
        // Prepare data
        $insert_data = [
            'feed_url' => $data['feed_url'],
            'feed_name' => $data['feed_name'] ?? '',
            'category_id' => $data['category_id'],
            'author_id' => $data['author_id'] ?? get_current_user_id(),
            'post_status' => $data['post_status'] ?? 'draft',
            'min_word_count' => $data['min_word_count'] ?? 300,
            'enable_enhancement' => $data['enable_enhancement'] ?? 1,
            'enable_translation' => $data['enable_translation'] ?? 0,
            'target_languages' => is_array($data['target_languages']) ? json_encode($data['target_languages']) : '[]',
            'enhancement_prompt' => $data['enhancement_prompt'] ?? '',
            'update_frequency' => $data['update_frequency'] ?? 'hourly',
            'items_per_import' => $data['items_per_import'] ?? 5,
            'is_active' => 1,
            'created_at' => current_time('mysql'),
            'content_domain' => $data['content_domain'] ?? 'auto',
            'content_angle' => $data['content_angle'] ?? 'auto',
            'seo_focus' => $data['seo_focus'] ?? 'informational',
            'target_keywords' => $data['target_keywords'] ?? '',
            'content_length' => $data['content_length'] ?? '900-1500',
            'target_audience' => $data['target_audience'] ?? '',
            'universal_prompt' => $data['universal_prompt'] ?? ''
        ];
        
        $result = $wpdb->insert($table, $insert_data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Update feed
     */
    public static function update_feed($feed_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        
        // Handle target_languages
        if (isset($data['target_languages']) && is_array($data['target_languages'])) {
            $data['target_languages'] = json_encode($data['target_languages']);
        }
        
        return $wpdb->update($table, $data, ['id' => $feed_id]);
    }
    
    /**
     * Delete feed
     */
    public static function delete_feed($feed_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        
        // Also clean up related data
        $wpdb->delete($wpdb->prefix . 'rsp_queue', ['feed_id' => $feed_id]);
        $wpdb->delete($wpdb->prefix . 'rsp_processed', ['feed_id' => $feed_id]);
        
        return $wpdb->delete($table, ['id' => $feed_id]);
    }
    
    /**
     * Check if item is processed
     */
    public static function is_item_processed($feed_id, $guid) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_processed';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE feed_id = %d AND item_guid = %s",
            $feed_id,
            $guid
        ));
        
        return $count > 0;
    }
    
    /**
     * Mark item as processed
     */
    public static function mark_item_processed($feed_id, $guid, $post_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_processed';
        return $wpdb->replace($table, [
            'feed_id' => $feed_id,
            'item_guid' => $guid,
            'post_id' => $post_id,
            'processed_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Record API usage
     */
    public static function record_api_usage($service, $endpoint, $tokens, $cost = 0, $success = true) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_api_usage';
        return $wpdb->insert($table, [
            'api_service' => $service,
            'endpoint' => $endpoint,
            'tokens_used' => $tokens,
            'cost_estimate' => $cost,
            'success' => $success ? 1 : 0,
            'created_at' => current_time('mysql')
        ]);
    }
}
