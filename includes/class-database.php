<?php
/**
 * Database handler for RSS Auto Publisher
 * Version 3.0.0 - Reorganized with daily limit tracking
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Database {
    /**
     * Database version
     */
    const DB_VERSION = '3.0.0';
    
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
     * Create database tables with daily limit tracking
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Enhanced feeds table with per-feed GPT-5 settings
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        $sql_feeds = "CREATE TABLE $feeds_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_url text NOT NULL,
            feed_name varchar(255) DEFAULT '',
            category_id bigint(20) NOT NULL,
            author_id bigint(20) DEFAULT 1,
            post_status varchar(20) DEFAULT 'draft',
            enable_enhancement tinyint(1) DEFAULT 1,
            enable_translation tinyint(1) DEFAULT 0,
            target_languages text,
            enhancement_prompt text,
            update_frequency varchar(20) DEFAULT 'daily',
            is_active tinyint(1) DEFAULT 1,
            last_checked datetime DEFAULT NULL,
            last_post_date date DEFAULT NULL,
            posts_today int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            
            -- Per-feed GPT-5 settings
            gpt5_model varchar(50) DEFAULT 'gpt-5',
            gpt5_verbosity varchar(20) DEFAULT 'high',
            gpt5_reasoning varchar(20) DEFAULT 'medium',
            content_length varchar(20) DEFAULT '1500-2500',
            min_article_words int DEFAULT 1500,
            
            -- Content settings
            content_domain varchar(50) DEFAULT 'auto',
            content_angle varchar(50) DEFAULT 'auto',
            seo_focus varchar(50) DEFAULT 'informational',
            target_keywords text,
            target_audience varchar(255) DEFAULT '',
            universal_prompt text,
            
            -- Statistics
            total_articles_generated int DEFAULT 0,
            total_words_generated bigint DEFAULT 0,
            avg_generation_time float DEFAULT 0,
            last_error text,
            last_error_date datetime DEFAULT NULL,
            
            PRIMARY KEY (id),
            KEY is_active (is_active),
            KEY last_post_date (last_post_date),
            KEY last_checked (last_checked)
        ) $charset_collate;";
        
        // Daily posting tracker
        $daily_posts_table = $wpdb->prefix . 'rsp_daily_posts';
        $sql_daily_posts = "CREATE TABLE $daily_posts_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) NOT NULL,
            post_date date NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            posted_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY feed_date (feed_id, post_date),
            KEY post_date (post_date)
        ) $charset_collate;";
        
        // Queue table remains mostly the same
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
            error_code varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            processing_time float DEFAULT 0,
            retry_after datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status_priority (status, priority),
            KEY feed_id (feed_id),
            KEY created_at (created_at),
            KEY retry_after (retry_after)
        ) $charset_collate;";
        
        // Processed items table
        $processed_table = $wpdb->prefix . 'rsp_processed';
        $sql_processed = "CREATE TABLE $processed_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            feed_id bigint(20) NOT NULL,
            item_guid varchar(255) NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            processed_at datetime DEFAULT CURRENT_TIMESTAMP,
            processing_time float DEFAULT 0,
            word_count int DEFAULT 0,
            gpt5_tokens_used int DEFAULT 0,
            gpt5_cost decimal(10,6) DEFAULT 0,
            gpt5_model varchar(50),
            language varchar(10) DEFAULT 'en',
            enhanced tinyint(1) DEFAULT 0,
            translated tinyint(1) DEFAULT 0,
            verbosity_used varchar(20),
            reasoning_used varchar(20),
            PRIMARY KEY (id),
            UNIQUE KEY feed_item (feed_id, item_guid),
            KEY post_id (post_id),
            KEY processed_at (processed_at)
        ) $charset_collate;";
        
        // API usage tracking
        $api_usage_table = $wpdb->prefix . 'rsp_api_usage';
        $sql_api_usage = "CREATE TABLE $api_usage_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_service varchar(50) NOT NULL,
            endpoint varchar(100),
            model varchar(50),
            tokens_used int DEFAULT 0,
            input_tokens int DEFAULT 0,
            output_tokens int DEFAULT 0,
            reasoning_tokens int DEFAULT 0,
            cost_estimate decimal(10,6) DEFAULT 0,
            success tinyint(1) DEFAULT 1,
            response_time float DEFAULT 0,
            verbosity varchar(20),
            reasoning_effort varchar(20),
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            feed_id bigint(20) DEFAULT NULL,
            queue_id bigint(20) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY api_service (api_service),
            KEY created_at (created_at),
            KEY feed_id (feed_id),
            KEY model (model)
        ) $charset_collate;";
        
        // Content analysis table
        $content_analysis_table = $wpdb->prefix . 'rsp_content_analysis';
        $sql_content_analysis = "CREATE TABLE $content_analysis_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            feed_id bigint(20) NOT NULL,
            domain varchar(50),
            angle varchar(50),
            seo_focus varchar(50),
            keywords_targeted text,
            keywords_density text,
            readability_score float,
            seo_score float,
            engagement_score float,
            word_count int,
            sentence_count int,
            paragraph_count int,
            heading_count int,
            image_count int,
            link_count int,
            analyzed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY feed_id (feed_id),
            KEY domain (domain),
            KEY analyzed_at (analyzed_at)
        ) $charset_collate;";
        
        // Performance metrics table
        $performance_table = $wpdb->prefix . 'rsp_performance_metrics';
        $sql_performance = "CREATE TABLE $performance_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            feed_id bigint(20) DEFAULT NULL,
            total_requests int DEFAULT 0,
            successful_requests int DEFAULT 0,
            failed_requests int DEFAULT 0,
            total_tokens bigint DEFAULT 0,
            total_cost decimal(10,4) DEFAULT 0,
            avg_response_time float DEFAULT 0,
            avg_word_count float DEFAULT 0,
            min_word_count int DEFAULT 0,
            max_word_count int DEFAULT 0,
            regeneration_count int DEFAULT 0,
            rate_limit_hits int DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY date_feed (date, feed_id),
            KEY date (date),
            KEY feed_id (feed_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_feeds);
        dbDelta($sql_daily_posts);
        dbDelta($sql_queue);
        dbDelta($sql_processed);
        dbDelta($sql_api_usage);
        dbDelta($sql_content_analysis);
        dbDelta($sql_performance);
        
        // Add new columns to existing tables if upgrading
        self::upgrade_existing_tables();
        
        // Set database version
        update_option('rsp_db_version', self::DB_VERSION);
        
        // Initialize default options
        self::init_default_options();
    }
    
    /**
     * Upgrade existing tables with new columns
     */
    private static function upgrade_existing_tables() {
        global $wpdb;
        
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        $existing_columns = $wpdb->get_col("DESC $feeds_table", 0);
        
        $new_columns = [
            'last_post_date' => "ALTER TABLE $feeds_table ADD COLUMN last_post_date date DEFAULT NULL",
            'posts_today' => "ALTER TABLE $feeds_table ADD COLUMN posts_today int DEFAULT 0",
            'gpt5_model' => "ALTER TABLE $feeds_table ADD COLUMN gpt5_model varchar(50) DEFAULT 'gpt-5'"
        ];
        
        foreach ($new_columns as $column => $sql) {
            if (!in_array($column, $existing_columns)) {
                $wpdb->query($sql);
            }
        }
        
        // Remove items_per_import column if it exists (no longer needed)
        if (in_array('items_per_import', $existing_columns)) {
            $wpdb->query("ALTER TABLE $feeds_table DROP COLUMN items_per_import");
        }
        
        // Remove use_responses_api column if it exists (moved to global settings)
        if (in_array('use_responses_api', $existing_columns)) {
            $wpdb->query("ALTER TABLE $feeds_table DROP COLUMN use_responses_api");
        }
        
        // Remove max_retries column if it exists (moved to global settings)
        if (in_array('max_retries', $existing_columns)) {
            $wpdb->query("ALTER TABLE $feeds_table DROP COLUMN max_retries");
        }
    }
    
    /**
     * Initialize default options (simplified)
     */
    private static function init_default_options() {
        $defaults = [
            'rsp_openai_api_key' => '',
            'rsp_use_responses_api' => 'yes',
            'rsp_max_retries' => 3,
            'rsp_enable_content_validation' => 1,
            'rsp_enable_debug_logging' => 0,
            'rsp_queue_batch_size' => 2,
            'rsp_default_post_status' => 'draft'
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    /**
     * Check if feed has posted today
     */
    public static function has_posted_today($feed_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_daily_posts';
        $today = current_time('Y-m-d');
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE feed_id = %d AND post_date = %s",
            $feed_id,
            $today
        ));
        
        return $count > 0;
    }
    
    /**
     * Record daily post
     */
    public static function record_daily_post($feed_id, $post_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_daily_posts';
        $today = current_time('Y-m-d');
        
        // Insert or update daily post record
        $wpdb->replace($table, [
            'feed_id' => $feed_id,
            'post_date' => $today,
            'post_id' => $post_id,
            'posted_at' => current_time('mysql')
        ]);
        
        // Update feed's last_post_date
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        $wpdb->update($feeds_table, [
            'last_post_date' => $today,
            'posts_today' => 1
        ], ['id' => $feed_id]);
    }
    
    /**
     * Get all feeds with enhanced filtering
     */
    public static function get_feeds($args = []) {
        global $wpdb;
        
        $defaults = [
            'is_active' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 0,
            'offset' => 0,
            'search' => '',
            'category_id' => null,
            'can_post_today' => null
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $table = $wpdb->prefix . 'rsp_feeds';
        $query = "SELECT * FROM $table WHERE 1=1";
        
        if ($args['is_active'] !== null) {
            $query .= $wpdb->prepare(" AND is_active = %d", $args['is_active']);
        }
        
        if ($args['can_post_today'] === true) {
            $today = current_time('Y-m-d');
            $query .= $wpdb->prepare(" AND (last_post_date IS NULL OR last_post_date < %s)", $today);
        }
        
        if (!empty($args['search'])) {
            $query .= $wpdb->prepare(" AND (feed_name LIKE %s OR feed_url LIKE %s)", 
                '%' . $wpdb->esc_like($args['search']) . '%',
                '%' . $wpdb->esc_like($args['search']) . '%'
            );
        }
        
        if ($args['category_id'] !== null) {
            $query .= $wpdb->prepare(" AND category_id = %d", $args['category_id']);
        }
        
        $query .= " ORDER BY {$args['orderby']} {$args['order']}";
        
        if ($args['limit'] > 0) {
            $query .= $wpdb->prepare(" LIMIT %d", $args['limit']);
            if ($args['offset'] > 0) {
                $query .= $wpdb->prepare(" OFFSET %d", $args['offset']);
            }
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get single feed with statistics
     */
    public static function get_feed($feed_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        $feed = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $feed_id
        ));
        
        if ($feed) {
            // Add statistics
            $processed_table = $wpdb->prefix . 'rsp_processed';
            $feed->stats = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_processed,
                    AVG(word_count) as avg_word_count,
                    SUM(word_count) as total_words,
                    AVG(processing_time) as avg_processing_time,
                    SUM(gpt5_cost) as total_cost
                FROM $processed_table 
                WHERE feed_id = %d",
                $feed_id
            ));
            
            // Check if can post today
            $feed->can_post_today = !self::has_posted_today($feed_id);
        }
        
        return $feed;
    }
    
    /**
     * Add feed with per-feed GPT-5 settings
     */
    public static function add_feed($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        
        // Prepare data with per-feed GPT-5 settings
        $insert_data = [
            'feed_url' => $data['feed_url'],
            'feed_name' => $data['feed_name'] ?? '',
            'category_id' => $data['category_id'],
            'author_id' => $data['author_id'] ?? get_current_user_id(),
            'post_status' => $data['post_status'] ?? 'draft',
            'enable_enhancement' => $data['enable_enhancement'] ?? 1,
            'enable_translation' => $data['enable_translation'] ?? 0,
            'target_languages' => is_array($data['target_languages']) ? json_encode($data['target_languages']) : '[]',
            'enhancement_prompt' => $data['enhancement_prompt'] ?? '',
            'update_frequency' => $data['update_frequency'] ?? 'daily',
            'is_active' => 1,
            'created_at' => current_time('mysql'),
            
            // Per-feed GPT-5 settings
            'gpt5_model' => $data['gpt5_model'] ?? 'gpt-5',
            'gpt5_verbosity' => $data['gpt5_verbosity'] ?? 'high',
            'gpt5_reasoning' => $data['gpt5_reasoning'] ?? 'medium',
            'content_length' => $data['content_length'] ?? '1500-2500',
            'min_article_words' => $data['min_article_words'] ?? 1500,
            
            // Content settings
            'content_domain' => $data['content_domain'] ?? 'auto',
            'content_angle' => $data['content_angle'] ?? 'auto',
            'seo_focus' => $data['seo_focus'] ?? 'informational',
            'target_keywords' => $data['target_keywords'] ?? '',
            'target_audience' => $data['target_audience'] ?? '',
            'universal_prompt' => $data['universal_prompt'] ?? ''
        ];
        
        $result = $wpdb->insert($table, $insert_data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Update feed with enhanced tracking
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
     * Delete feed and all related data
     */
    public static function delete_feed($feed_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_feeds';
        
        // Delete related data
        $wpdb->delete($wpdb->prefix . 'rsp_queue', ['feed_id' => $feed_id]);
        $wpdb->delete($wpdb->prefix . 'rsp_processed', ['feed_id' => $feed_id]);
        $wpdb->delete($wpdb->prefix . 'rsp_api_usage', ['feed_id' => $feed_id]);
        $wpdb->delete($wpdb->prefix . 'rsp_content_analysis', ['feed_id' => $feed_id]);
        $wpdb->delete($wpdb->prefix . 'rsp_performance_metrics', ['feed_id' => $feed_id]);
        $wpdb->delete($wpdb->prefix . 'rsp_daily_posts', ['feed_id' => $feed_id]);
        
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
     * Mark item as processed with enhanced metadata
     */
    public static function mark_item_processed($feed_id, $guid, $post_id = null, $metadata = []) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_processed';
        
        $data = [
            'feed_id' => $feed_id,
            'item_guid' => $guid,
            'post_id' => $post_id,
            'processed_at' => current_time('mysql'),
            'processing_time' => $metadata['processing_time'] ?? 0,
            'word_count' => $metadata['word_count'] ?? 0,
            'gpt5_tokens_used' => $metadata['tokens_used'] ?? 0,
            'gpt5_cost' => $metadata['cost'] ?? 0,
            'gpt5_model' => $metadata['model'] ?? 'gpt-5',
            'language' => $metadata['language'] ?? 'en',
            'enhanced' => $metadata['enhanced'] ?? 0,
            'translated' => $metadata['translated'] ?? 0,
            'verbosity_used' => $metadata['verbosity'] ?? null,
            'reasoning_used' => $metadata['reasoning'] ?? null
        ];
        
        $result = $wpdb->replace($table, $data);
        
        // Update feed statistics
        if ($result && $post_id) {
            self::update_feed_statistics($feed_id);
            
            // Record daily post
            self::record_daily_post($feed_id, $post_id);
        }
        
        return $result;
    }
    
    /**
     * Update feed statistics
     */
    private static function update_feed_statistics($feed_id) {
        global $wpdb;
        
        $processed_table = $wpdb->prefix . 'rsp_processed';
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        
        // Calculate statistics
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_articles,
                SUM(word_count) as total_words,
                AVG(processing_time) as avg_time
            FROM $processed_table 
            WHERE feed_id = %d AND post_id IS NOT NULL",
            $feed_id
        ));
        
        if ($stats) {
            $wpdb->update($feeds_table, [
                'total_articles_generated' => $stats->total_articles,
                'total_words_generated' => $stats->total_words,
                'avg_generation_time' => $stats->avg_time
            ], ['id' => $feed_id]);
        }
    }
    
    /**
     * Record API usage with GPT-5 specific fields
     */
    public static function record_api_usage($service, $endpoint, $tokens, $cost = 0, $success = true, $metadata = []) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_api_usage';
        
        $data = [
            'api_service' => $service,
            'endpoint' => $endpoint,
            'model' => $metadata['model'] ?? 'gpt-5',
            'tokens_used' => $tokens,
            'input_tokens' => $metadata['input_tokens'] ?? 0,
            'output_tokens' => $metadata['output_tokens'] ?? 0,
            'reasoning_tokens' => $metadata['reasoning_tokens'] ?? 0,
            'cost_estimate' => $cost,
            'success' => $success ? 1 : 0,
            'response_time' => $metadata['response_time'] ?? 0,
            'verbosity' => $metadata['verbosity'] ?? null,
            'reasoning_effort' => $metadata['reasoning_effort'] ?? null,
            'error_message' => $metadata['error_message'] ?? null,
            'created_at' => current_time('mysql'),
            'feed_id' => $metadata['feed_id'] ?? null,
            'queue_id' => $metadata['queue_id'] ?? null
        ];
        
        $result = $wpdb->insert($table, $data);
        
        // Update daily performance metrics
        if ($result) {
            self::update_performance_metrics($metadata['feed_id'] ?? null, $success, $tokens, $cost);
        }
        
        return $result;
    }
    
    /**
     * Update performance metrics
     */
    private static function update_performance_metrics($feed_id, $success, $tokens, $cost) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_performance_metrics';
        $date = current_time('Y-m-d');
        
        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE date = %s AND feed_id = %d",
            $date,
            $feed_id ?: 0
        ));
        
        if ($exists) {
            // Update existing record
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET 
                    total_requests = total_requests + 1,
                    successful_requests = successful_requests + %d,
                    failed_requests = failed_requests + %d,
                    total_tokens = total_tokens + %d,
                    total_cost = total_cost + %f
                WHERE date = %s AND feed_id = %d",
                $success ? 1 : 0,
                $success ? 0 : 1,
                $tokens,
                $cost,
                $date,
                $feed_id ?: 0
            ));
        } else {
            // Insert new record
            $wpdb->insert($table, [
                'date' => $date,
                'feed_id' => $feed_id,
                'total_requests' => 1,
                'successful_requests' => $success ? 1 : 0,
                'failed_requests' => $success ? 0 : 1,
                'total_tokens' => $tokens,
                'total_cost' => $cost
            ]);
        }
    }
    
    /**
     * Record content analysis
     */
    public static function record_content_analysis($post_id, $feed_id, $analysis_data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_content_analysis';
        
        return $wpdb->replace($table, array_merge([
            'post_id' => $post_id,
            'feed_id' => $feed_id,
            'analyzed_at' => current_time('mysql')
        ], $analysis_data));
    }
    
    /**
     * Get performance metrics for a date range
     */
    public static function get_performance_metrics($start_date = null, $end_date = null, $feed_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_performance_metrics';
        
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        
        if (!$end_date) {
            $end_date = current_time('Y-m-d');
        }
        
        $query = $wpdb->prepare(
            "SELECT * FROM $table 
            WHERE date BETWEEN %s AND %s",
            $start_date,
            $end_date
        );
        
        if ($feed_id !== null) {
            $query .= $wpdb->prepare(" AND feed_id = %d", $feed_id);
        }
        
        $query .= " ORDER BY date DESC";
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get usage statistics
     */
    public static function get_usage_statistics($days = 30) {
        global $wpdb;
        
        $api_table = $wpdb->prefix . 'rsp_api_usage';
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests,
                SUM(tokens_used) as total_tokens,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(reasoning_tokens) as total_reasoning_tokens,
                SUM(cost_estimate) as total_cost,
                AVG(response_time) as avg_response_time,
                MAX(created_at) as last_used
            FROM $api_table 
            WHERE created_at > %s",
            $since
        ));
    }
    
    /**
     * Get feeds that haven't posted today
     */
    public static function get_feeds_available_for_posting() {
        global $wpdb;
        
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        $today = current_time('Y-m-d');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $feeds_table 
            WHERE is_active = 1 
            AND (last_post_date IS NULL OR last_post_date < %s)
            ORDER BY last_post_date ASC, created_at ASC",
            $today
        ));
    }
    
    /**
     * Clean old data
     */
    public static function cleanup_old_data($days_to_keep = 90) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        // Clean old API usage records
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rsp_api_usage WHERE created_at < %s",
            $cutoff_date
        ));
        
        // Clean old performance metrics
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rsp_performance_metrics WHERE date < %s",
            date('Y-m-d', strtotime("-{$days_to_keep} days"))
        ));
        
        // Clean completed queue items older than 30 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rsp_queue 
            WHERE status IN ('completed', 'failed') AND processed_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Clean old daily post records (keep last 180 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rsp_daily_posts WHERE post_date < %s",
            date('Y-m-d', strtotime('-180 days'))
        ));
    }
    
    /**
     * Reset daily counters (run at midnight)
     */
    public static function reset_daily_counters() {
        global $wpdb;
        
        $feeds_table = $wpdb->prefix . 'rsp_feeds';
        $today = current_time('Y-m-d');
        
        // Reset posts_today for feeds that haven't posted today
        $wpdb->query($wpdb->prepare(
            "UPDATE $feeds_table 
            SET posts_today = 0 
            WHERE last_post_date < %s OR last_post_date IS NULL",
            $today
        ));
    }
}
