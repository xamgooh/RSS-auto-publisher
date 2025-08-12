<?php
/**
 * Admin Interface for RSS Auto Publisher
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Admin {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_rsp_test_feed', [$this, 'ajax_test_feed']);
        add_action('wp_ajax_rsp_delete_feed', [$this, 'ajax_delete_feed']);
        add_action('wp_ajax_rsp_toggle_feed', [$this, 'ajax_toggle_feed']);
        add_action('wp_ajax_rsp_process_feed', [$this, 'ajax_process_feed']);
        add_action('wp_ajax_rsp_process_queue_now', [$this, 'ajax_process_queue_now']);
        add_action('wp_ajax_rsp_get_feed', [$this, 'ajax_get_feed']);
        
        // Form handlers
        add_action('admin_post_rsp_add_feed', [$this, 'handle_add_feed']);
        add_action('admin_post_rsp_update_feed', [$this, 'handle_update_feed']);
        add_action('admin_post_rsp_save_settings', [$this, 'handle_save_settings']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('RSS Auto Publisher', 'rss-auto-publisher'),
            __('RSS Publisher', 'rss-auto-publisher'),
            'manage_options',
            'rss-auto-publisher',
            [$this, 'render_feeds_page'],
            'dashicons-rss',
            30
        );
        
        add_submenu_page(
            'rss-auto-publisher',
            __('Manage Feeds', 'rss-auto-publisher'),
            __('Manage Feeds', 'rss-auto-publisher'),
            'manage_options',
            'rss-auto-publisher',
            [$this, 'render_feeds_page']
        );
        
        add_submenu_page(
            'rss-auto-publisher',
            __('Settings', 'rss-auto-publisher'),
            __('Settings', 'rss-auto-publisher'),
            'manage_options',
            'rsp-settings',
            [$this, 'render_settings_page']
        );
        
        add_submenu_page(
            'rss-auto-publisher',
            __('Queue Status', 'rss-auto-publisher'),
            __('Queue', 'rss-auto-publisher'),
            'manage_options',
            'rsp-queue',
            [$this, 'render_queue_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'rss-auto-publisher') === false && strpos($hook, 'rsp-') === false) {
            return;
        }
        
        wp_enqueue_style('rsp-admin', RSP_PLUGIN_URL . 'assets/admin.css', [], RSP_VERSION);
        wp_enqueue_script('rsp-admin', RSP_PLUGIN_URL . 'assets/admin.js', ['jquery'], RSP_VERSION, true);
        
        wp_localize_script('rsp-admin', 'rspAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rsp-ajax'),
        ]);
    }
    
    /**
     * Render feeds page
     */
    public function render_feeds_page() {
        $feeds = RSP_Database::get_feeds();
        $categories = get_categories(['hide_empty' => false]);
        $authors = get_users(['capability' => 'edit_posts']);
        $openai = new RSP_OpenAI();
        $languages = $openai->get_supported_languages();
        
        ?>
        <div class="wrap rsp-admin">
            <h1><?php _e('RSS Auto Publisher - Manage Feeds', 'rss-auto-publisher'); ?></h1>
            
            <?php if (!$openai->is_configured()): ?>
            <div class="notice notice-warning">
                <p><?php _e('OpenAI API key not configured. Please configure it in Settings.', 'rss-auto-publisher'); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($openai->is_rate_limited()): ?>
            <div class="notice notice-error">
                <p><?php _e('GPT-5 API is currently rate limited. The system will retry automatically.', 'rss-auto-publisher'); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="rsp-card">
                <h2 id="feed-form-title"><?php _e('Add New Feed', 'rss-auto-publisher'); ?></h2>
                
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="rsp-feed-form">
                    <input type="hidden" name="action" value="rsp_add_feed" id="form-action">
                    <input type="hidden" name="feed_id" id="feed_id" value="">
                    <?php wp_nonce_field('rsp_feed_action', 'rsp_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="feed_url"><?php _e('Feed URL', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="url" name="feed_url" id="feed_url" class="regular-text" required>
                                <button type="button" class="button" id="test-feed"><?php _e('Test Feed', 'rss-auto-publisher'); ?></button>
                                <div id="test-results"></div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="feed_name"><?php _e('Feed Name', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="text" name="feed_name" id="feed_name" class="regular-text">
                                <p class="description"><?php _e('Optional name to identify this feed', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="category_id"><?php _e('Category', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="category_id" id="category_id" required>
                                    <option value=""><?php _e('Select Category', 'rss-auto-publisher'); ?></option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="author_id"><?php _e('Author', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="author_id" id="author_id">
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?php echo $author->ID; ?>"><?php echo esc_html($author->display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="post_status"><?php _e('Post Status', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="post_status" id="post_status">
                                    <option value="draft"><?php _e('Draft', 'rss-auto-publisher'); ?></option>
                                    <option value="pending"><?php _e('Pending Review', 'rss-auto-publisher'); ?></option>
                                    <option value="publish"><?php _e('Published', 'rss-auto-publisher'); ?></option>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><?php _e('AI Enhancement', 'rss-auto-publisher'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_enhancement" id="enable_enhancement" value="1" checked>
                                    <?php _e('Enable GPT-5 content enhancement', 'rss-auto-publisher'); ?>
                                </label>
                                <p class="description"><?php _e('Uses OpenAI GPT-5 to rewrite and improve content', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><?php _e('Translation', 'rss-auto-publisher'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_translation" value="1" id="enable-translation">
                                    <?php _e('Translate content to other languages', 'rss-auto-publisher'); ?>
                                </label>
                                <div id="language-options" style="display:none; margin-top:10px;">
                                    <?php foreach ($languages as $code => $name): ?>
                                        <label style="display:block; margin:5px 0;">
                                            <input type="checkbox" name="target_languages[]" value="<?php echo $code; ?>" class="language-checkbox">
                                            <?php echo esc_html($name); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_word_count"><?php _e('Min Word Count', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="min_word_count" id="min_word_count" value="300" min="50" max="5000">
                                <p class="description"><?php _e('Skip items with fewer words', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="items_per_import"><?php _e('Items Per Import', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="items_per_import" id="items_per_import">
                                    <option value="3">3</option>
                                    <option value="5" selected>5</option>
                                    <option value="10">10</option>
                                </select>
                                <p class="description"><?php _e('Number of items to process each time', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="update_frequency"><?php _e('Update Frequency', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="update_frequency" id="update_frequency">
                                    <option value="hourly"><?php _e('Hourly', 'rss-auto-publisher'); ?></option>
                                    <option value="twicedaily"><?php _e('Twice Daily', 'rss-auto-publisher'); ?></option>
                                    <option value="daily"><?php _e('Daily', 'rss-auto-publisher'); ?></option>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="enhancement_prompt"><?php _e('Custom Instructions', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <textarea name="enhancement_prompt" id="enhancement_prompt" rows="3" class="large-text" placeholder="<?php _e('Optional: Add custom instructions for GPT-5 content processing...', 'rss-auto-publisher'); ?>"></textarea>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="submit-btn"><?php _e('Add Feed', 'rss-auto-publisher'); ?></button>
                        <button type="button" class="button" id="cancel-edit" style="display:none;"><?php _e('Cancel', 'rss-auto-publisher'); ?></button>
                    </p>
                </form>
            </div>
            
            <?php if (!empty($feeds)): ?>
            <div class="rsp-card">
                <h2><?php _e('Existing Feeds', 'rss-auto-publisher'); ?></h2>
                
                <div class="rsp-feeds-grid">
                    <?php foreach ($feeds as $feed): 
                        $category = get_term($feed->category_id);
                        $languages_array = json_decode($feed->target_languages, true) ?: [];
                    ?>
                    <div class="rsp-feed-card">
                        <div class="feed-card-header">
                            <h3 class="feed-card-title"><?php echo esc_html($feed->feed_name ?: 'Feed #' . $feed->id); ?></h3>
                            <span class="status-badge status-<?php echo $feed->is_active ? 'active' : 'paused'; ?>">
                                <?php echo $feed->is_active ? __('Active', 'rss-auto-publisher') : __('Paused', 'rss-auto-publisher'); ?>
                            </span>
                        </div>
                        
                        <div class="feed-card-url">
                            <small><?php echo esc_html($feed->feed_url); ?></small>
                        </div>
                        
                        <div class="feed-card-meta">
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Category:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value"><?php echo $category ? esc_html($category->name) : '-'; ?></span>
                            </div>
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Enhancement:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value"><?php echo $feed->enable_enhancement ? '✓' : '✗'; ?></span>
                            </div>
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Translation:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value">
                                    <?php 
                                    if ($feed->enable_translation && !empty($languages_array)) {
                                        echo count($languages_array) . ' ' . __('langs', 'rss-auto-publisher');
                                    } else {
                                        echo '✗';
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Last Check:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value">
                                    <?php 
                                    echo $feed->last_checked ? 
                                        human_time_diff(strtotime($feed->last_checked)) . ' ' . __('ago', 'rss-auto-publisher') : 
                                        __('Never', 'rss-auto-publisher');
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="feed-card-actions">
                            <button type="button" class="button button-small edit-feed" data-feed-id="<?php echo $feed->id; ?>">
                                <?php _e('Edit', 'rss-auto-publisher'); ?>
                            </button>
                            <button type="button" class="button button-small process-feed" data-feed-id="<?php echo $feed->id; ?>">
                                <?php _e('Process', 'rss-auto-publisher'); ?>
                            </button>
                            <button type="button" class="button button-small toggle-feed" data-feed-id="<?php echo $feed->id; ?>" data-active="<?php echo $feed->is_active; ?>">
                                <?php echo $feed->is_active ? __('Pause', 'rss-auto-publisher') : __('Resume', 'rss-auto-publisher'); ?>
                            </button>
                            <button type="button" class="button button-small delete-feed" data-feed-id="<?php echo $feed->id; ?>">
                                <?php _e('Delete', 'rss-auto-publisher'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $api_key = get_option('rsp_openai_api_key');
        $openai = new RSP_OpenAI();
        $usage_stats = $openai->is_configured() ? $openai->get_usage_stats() : null;
        $model_info = $openai->get_model_info();
        
        ?>
        <div class="wrap rsp-admin">
            <h1><?php _e('RSS Auto Publisher - Settings', 'rss-auto-publisher'); ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="rsp_save_settings">
                <?php wp_nonce_field('rsp_save_settings', 'rsp_nonce'); ?>
                
                <div class="rsp-card">
                    <h2><?php _e('OpenAI GPT-5 Configuration', 'rss-auto-publisher'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="openai_api_key"><?php _e('OpenAI API Key', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="text" name="openai_api_key" id="openai_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                                <p class="description">
                                    <?php _e('Get your API key from', 'rss-auto-publisher'); ?> 
                                    <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><?php _e('Model', 'rss-auto-publisher'); ?></th>
                            <td>
                                <strong>GPT-5</strong> (<?php echo $model_info['version']; ?>)
                                <p class="description"><?php _e('Flagship model for coding and reasoning', 'rss-auto-publisher'); ?></p>
                                <ul class="description">
                                    <li><?php printf(__('Context Window: %s tokens', 'rss-auto-publisher'), number_format($model_info['context_window'])); ?></li>
                                    <li><?php printf(__('Max Output: %s tokens', 'rss-auto-publisher'), number_format($model_info['max_output'])); ?></li>
                                    <li><?php printf(__('Knowledge Cutoff: %s', 'rss-auto-publisher'), $model_info['knowledge_cutoff']); ?></li>
                                </ul>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><?php _e('Pricing', 'rss-auto-publisher'); ?></th>
                            <td>
                                <ul class="description">
                                    <li><?php printf(__('Input: $%.2f per 1M tokens', 'rss-auto-publisher'), $model_info['pricing']['input']); ?></li>
                                    <li><?php printf(__('Cached Input: $%.3f per 1M tokens', 'rss-auto-publisher'), $model_info['pricing']['cached_input']); ?></li>
                                    <li><?php printf(__('Output: $%.2f per 1M tokens', 'rss-auto-publisher'), $model_info['pricing']['output']); ?></li>
                                </ul>
                            </td>
                        </tr>
                        
                        <?php if ($usage_stats): ?>
                        <tr>
                            <th><?php _e('API Usage (30 days)', 'rss-auto-publisher'); ?></th>
                            <td>
                                <ul>
                                    <li><?php printf(__('Total Requests: %d', 'rss-auto-publisher'), $usage_stats->total_requests); ?></li>
                                    <li><?php printf(__('Total Tokens: %s', 'rss-auto-publisher'), number_format($usage_stats->total_tokens)); ?></li>
                                    <li><?php printf(__('Estimated Cost: $%.2f', 'rss-auto-publisher'), $usage_stats->total_cost); ?></li>
                                </ul>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <div class="rsp-card">
                    <h2><?php _e('Default Settings', 'rss-auto-publisher'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="default_post_status"><?php _e('Default Post Status', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="default_post_status" id="default_post_status">
                                    <option value="draft" <?php selected(get_option('rsp_default_post_status'), 'draft'); ?>><?php _e('Draft', 'rss-auto-publisher'); ?></option>
                                    <option value="pending" <?php selected(get_option('rsp_default_post_status'), 'pending'); ?>><?php _e('Pending Review', 'rss-auto-publisher'); ?></option>
                                    <option value="publish" <?php selected(get_option('rsp_default_post_status'), 'publish'); ?>><?php _e('Published', 'rss-auto-publisher'); ?></option>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_word_count"><?php _e('Default Min Word Count', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="min_word_count" id="min_word_count" value="<?php echo get_option('rsp_min_word_count', 300); ?>" min="50" max="5000">
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="queue_batch_size"><?php _e('Queue Batch Size', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="queue_batch_size" id="queue_batch_size" value="<?php echo get_option('rsp_queue_batch_size', 5); ?>" min="1" max="20">
                                <p class="description"><?php _e('Number of items to process per batch', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(__('Save Settings', 'rss-auto-publisher')); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render queue page
     */
    public function render_queue_page() {
        $stats = RSP_Queue::get_stats();
        
        ?>
        <div class="wrap rsp-admin">
            <h1><?php _e('RSS Auto Publisher - Queue Status', 'rss-auto-publisher'); ?></h1>
            
            <div class="rsp-card">
                <h2><?php _e('Queue Statistics', 'rss-auto-publisher'); ?></h2>
                
                <div class="queue-stats">
                    <div class="stat-box">
                        <span class="stat-value"><?php echo $stats->pending; ?></span>
                        <span class="stat-label"><?php _e('Pending', 'rss-auto-publisher'); ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo $stats->processing; ?></span>
                        <span class="stat-label"><?php _e('Processing', 'rss-auto-publisher'); ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo $stats->failed; ?></span>
                        <span class="stat-label"><?php _e('Failed', 'rss-auto-publisher'); ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo $stats->total; ?></span>
                        <span class="stat-label"><?php _e('Total', 'rss-auto-publisher'); ?></span>
                    </div>
                </div>
                
                <p>
                    <button class="button button-primary" id="process-queue-now">
                        <?php _e('Process Queue Now', 'rss-auto-publisher'); ?>
                    </button>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handlers
     */
    public function ajax_test_feed() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        $feed_url = esc_url_raw($_POST['feed_url']);
        
        if (empty($feed_url)) {
            wp_send_json_error(__('Invalid feed URL', 'rss-auto-publisher'));
        }
        
        require_once(ABSPATH . WPINC . '/feed.php');
        $feed = fetch_feed($feed_url);
        
        if (is_wp_error($feed)) {
            wp_send_json_error($feed->get_error_message());
        }
        
        $items = $feed->get_items(0, 3);
        $result = [];
        
        foreach ($items as $item) {
            $result[] = [
                'title' => $item->get_title(),
                'date' => $item->get_date('Y-m-d H:i:s')
            ];
        }
        
        wp_send_json_success([
            'message' => sprintf(__('Feed is valid! Found %d items', 'rss-auto-publisher'), count($items)),
            'items' => $result
        ]);
    }
    
    public function ajax_get_feed() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        $feed_id = intval($_POST['feed_id']);
        $feed = RSP_Database::get_feed($feed_id);
        
        if ($feed) {
            $feed->target_languages = json_decode($feed->target_languages, true) ?: [];
            wp_send_json_success($feed);
        }
        
        wp_send_json_error(__('Feed not found', 'rss-auto-publisher'));
    }
    
    public function ajax_process_feed() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        $feed_id = intval($_POST['feed_id']);
        $queued = RSP_Feed_Processor::process_feed($feed_id);
        
        if ($queued > 0) {
            wp_send_json_success([
                'message' => sprintf(__('%d items queued for processing', 'rss-auto-publisher'), $queued)
            ]);
        } else {
            wp_send_json_error(__('No new items to process', 'rss-auto-publisher'));
        }
    }
    
    public function ajax_toggle_feed() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        $feed_id = intval($_POST['feed_id']);
        $feed = RSP_Database::get_feed($feed_id);
        
        if ($feed) {
            RSP_Database::update_feed($feed_id, [
                'is_active' => $feed->is_active ? 0 : 1
            ]);
            wp_send_json_success();
        }
        
        wp_send_json_error();
    }
    
    public function ajax_delete_feed() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        $feed_id = intval($_POST['feed_id']);
        
        if (RSP_Database::delete_feed($feed_id)) {
            wp_send_json_success();
        }
        
        wp_send_json_error();
    }
    
    public function ajax_process_queue_now() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        RSP_Queue::process_queue();
        wp_send_json_success();
    }
    
    /**
     * Handle add feed
     */
    public function handle_add_feed() {
        check_admin_referer('rsp_feed_action', 'rsp_nonce');
        
        $data = [
            'feed_url' => esc_url_raw($_POST['feed_url']),
            'feed_name' => sanitize_text_field($_POST['feed_name']),
            'category_id' => intval($_POST['category_id']),
            'author_id' => intval($_POST['author_id']),
            'post_status' => sanitize_text_field($_POST['post_status']),
            'min_word_count' => intval($_POST['min_word_count']),
            'enable_enhancement' => isset($_POST['enable_enhancement']) ? 1 : 0,
            'enable_translation' => isset($_POST['enable_translation']) ? 1 : 0,
            'target_languages' => isset($_POST['target_languages']) ? $_POST['target_languages'] : [],
            'enhancement_prompt' => sanitize_textarea_field($_POST['enhancement_prompt']),
            'update_frequency' => sanitize_text_field($_POST['update_frequency']),
            'items_per_import' => intval($_POST['items_per_import'])
        ];
        
        $feed_id = RSP_Database::add_feed($data);
        
        if ($feed_id) {
            // Schedule cron for this feed
            RSP_Cron::schedule_feed($feed_id, $data['update_frequency']);
            
            wp_redirect(admin_url('admin.php?page=rss-auto-publisher&added=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=rss-auto-publisher&error=1'));
        }
        exit;
    }
    
    /**
     * Handle update feed
     */
    public function handle_update_feed() {
        check_admin_referer('rsp_feed_action', 'rsp_nonce');
        
        $feed_id = intval($_POST['feed_id']);
        
        if (!$feed_id) {
            wp_redirect(admin_url('admin.php?page=rss-auto-publisher&error=1'));
            exit;
        }
        
        $data = [
            'feed_url' => esc_url_raw($_POST['feed_url']),
            'feed_name' => sanitize_text_field($_POST['feed_name']),
            'category_id' => intval($_POST['category_id']),
            'author_id' => intval($_POST['author_id']),
            'post_status' => sanitize_text_field($_POST['post_status']),
            'min_word_count' => intval($_POST['min_word_count']),
            'enable_enhancement' => isset($_POST['enable_enhancement']) ? 1 : 0,
            'enable_translation' => isset($_POST['enable_translation']) ? 1 : 0,
            'target_languages' => isset($_POST['target_languages']) ? $_POST['target_languages'] : [],
            'enhancement_prompt' => sanitize_textarea_field($_POST['enhancement_prompt']),
            'update_frequency' => sanitize_text_field($_POST['update_frequency']),
            'items_per_import' => intval($_POST['items_per_import'])
        ];
        
        if (RSP_Database::update_feed($feed_id, $data)) {
            // Update cron schedule
            RSP_Cron::schedule_feed($feed_id, $data['update_frequency']);
            
            wp_redirect(admin_url('admin.php?page=rss-auto-publisher&updated=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=rss-auto-publisher&error=1'));
        }
        exit;
    }
    
    /**
     * Handle save settings
     */
    public function handle_save_settings() {
        check_admin_referer('rsp_save_settings', 'rsp_nonce');
        
        update_option('rsp_openai_api_key', sanitize_text_field($_POST['openai_api_key']));
        update_option('rsp_default_post_status', sanitize_text_field($_POST['default_post_status']));
        update_option('rsp_min_word_count', intval($_POST['min_word_count']));
        update_option('rsp_queue_batch_size', intval($_POST['queue_batch_size']));
        
        wp_redirect(admin_url('admin.php?page=rsp-settings&saved=1'));
        exit;
    }
}
