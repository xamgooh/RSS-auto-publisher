<?php
/**
 * Plugin Name: RSS Auto Publisher
 * Plugin URI: https://yoursite.com/rss-auto-publisher
 * Description: Import RSS feeds and enhance content with OpenAI GPT-5
 * Version: 1.0.0
 * Author: Max 
 * License: GPL v2 or later
 * Text Domain: rss-auto-publisher
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RSP_VERSION', '1.0.0');
define('RSP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RSP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RSP_PLUGIN_FILE', __FILE__);

/**
 * Main plugin class
 */
class RSS_Auto_Publisher {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once RSP_PLUGIN_DIR . 'includes/class-database.php';
        require_once RSP_PLUGIN_DIR . 'includes/class-openai.php';
        require_once RSP_PLUGIN_DIR . 'includes/class-feed-processor.php';
        require_once RSP_PLUGIN_DIR . 'includes/class-queue.php';
        require_once RSP_PLUGIN_DIR . 'includes/class-admin.php';
        require_once RSP_PLUGIN_DIR . 'includes/class-cron.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(RSP_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(RSP_PLUGIN_FILE, [$this, 'deactivate']);
        
        // Initialize components
        add_action('init', [$this, 'init']);
        
        // Admin
        if (is_admin()) {
            new RSP_Admin();
        }
        
        // Cron
        new RSP_Cron();
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('rss-auto-publisher', false, dirname(plugin_basename(RSP_PLUGIN_FILE)) . '/languages');
        
        // Initialize database
        RSP_Database::init();
        
        // Initialize queue processor
        RSP_Queue::init();
    }
    
    /**
     * Activation
     */
    public function activate() {
        // Create database tables
        RSP_Database::create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Schedule cron jobs
        RSP_Cron::schedule_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        RSP_Cron::clear_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = [
            'rsp_openai_api_key' => '',
            'rsp_default_post_status' => 'draft',
            'rsp_default_author' => 1,
            'rsp_min_word_count' => 300,
            'rsp_queue_batch_size' => 5,
            'rsp_enable_enhancement' => 1,
            'rsp_enable_translation' => 0,
            'rsp_target_languages' => [],
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
}

// Initialize plugin
RSS_Auto_Publisher::get_instance();
