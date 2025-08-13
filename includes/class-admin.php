<?php
/**
 * Admin Interface for RSS Auto Publisher
 * Version 2.0.0 - GPT-5 Optimized with all features
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
        add_action('wp_ajax_rsp_clear_queue', [$this, 'ajax_clear_queue']);
        add_action('wp_ajax_rsp_test_gpt5', [$this, 'ajax_test_gpt5']);
        
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
        
        // GPT-5 settings
        $default_verbosity = get_option('rsp_gpt5_verbosity', 'high');
        $default_reasoning = get_option('rsp_gpt5_reasoning_effort', 'high');
        $default_content_length = get_option('rsp_default_content_length', '1200-1800');
        
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
                            <th colspan="2">
                                <h3><?php _e('GPT-5 Content Generation', 'rss-auto-publisher'); ?></h3>
                            </th>
                        </tr>
                        
                        <tr>
                            <th><?php _e('AI Enhancement', 'rss-auto-publisher'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_enhancement" id="enable_enhancement" value="1" checked>
                                    <?php _e('Enable GPT-5 content generation', 'rss-auto-publisher'); ?>
                                </label>
                                <p class="description"><?php _e('Creates full articles from RSS titles using GPT-5', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="gpt5_verbosity"><?php _e('GPT-5 Verbosity', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="gpt5_verbosity" id="gpt5_verbosity">
                                    <option value="default"><?php _e('Use Default', 'rss-auto-publisher'); ?></option>
                                    <option value="low"><?php _e('Low (Concise)', 'rss-auto-publisher'); ?></option>
                                    <option value="medium"><?php _e('Medium (Balanced)', 'rss-auto-publisher'); ?></option>
                                    <option value="high" selected><?php _e('High (Detailed)', 'rss-auto-publisher'); ?></option>
                                </select>
                                <p class="description"><?php _e('Controls the length and detail of generated content', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="gpt5_reasoning"><?php _e('Reasoning Effort', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="gpt5_reasoning" id="gpt5_reasoning">
                                    <option value="default"><?php _e('Use Default', 'rss-auto-publisher'); ?></option>
                                    <option value="minimal"><?php _e('Minimal (Fast)', 'rss-auto-publisher'); ?></option>
                                    <option value="medium"><?php _e('Medium (Balanced)', 'rss-auto-publisher'); ?></option>
                                    <option value="high" selected><?php _e('High (Best Quality)', 'rss-auto-publisher'); ?></option>
                                </select>
                                <p class="description"><?php _e('Higher reasoning produces better quality but takes more time', 'rss-auto-publisher'); ?></p>
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
                            <th colspan="2">
                                <h3 style="margin: 20px 0 10px 0; cursor: pointer;" onclick="toggleAdvancedOptions()">
                                    ⚙️ <?php _e('Advanced Content Options', 'rss-auto-publisher'); ?>
                                    <small style="font-weight: normal; color: #666;"><?php _e('(Click to expand)', 'rss-auto-publisher'); ?></small>
                                </h3>
                            </th>
                        </tr>
                        
                        <tbody id="advanced-options" style="display: none;">
                            <tr>
                                <th><label for="content_domain"><?php _e('Content Type', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <select name="content_domain" id="content_domain">
                                        <option value="auto"><?php _e('Auto-detect', 'rss-auto-publisher'); ?></option>
                                        <option value="sports"><?php _e('Sports & Fantasy', 'rss-auto-publisher'); ?></option>
                                        <option value="gambling"><?php _e('Betting & Casino', 'rss-auto-publisher'); ?></option>
                                        <option value="technology"><?php _e('Technology', 'rss-auto-publisher'); ?></option>
                                        <option value="business"><?php _e('Business & Finance', 'rss-auto-publisher'); ?></option>
                                        <option value="health"><?php _e('Health & Wellness', 'rss-auto-publisher'); ?></option>
                                        <option value="lifestyle"><?php _e('Lifestyle', 'rss-auto-publisher'); ?></option>
                                        <option value="news"><?php _e('News & Current Events', 'rss-auto-publisher'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Choose content type for better AI optimization', 'rss-auto-publisher'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="content_angle"><?php _e('Content Style', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <select name="content_angle" id="content_angle">
                                        <option value="auto"><?php _e('Smart selection', 'rss-auto-publisher'); ?></option>
                                        <option value="beginner_guide"><?php _e('Beginner-friendly guides', 'rss-auto-publisher'); ?></option>
                                        <option value="expert_analysis"><?php _e('Expert analysis', 'rss-auto-publisher'); ?></option>
                                        <option value="practical_tips"><?php _e('Practical tips & how-to', 'rss-auto-publisher'); ?></option>
                                        <option value="comparison"><?php _e('Comparisons & reviews', 'rss-auto-publisher'); ?></option>
                                        <option value="prediction"><?php _e('Predictions & forecasts', 'rss-auto-publisher'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="seo_focus"><?php _e('SEO Goal', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <select name="seo_focus" id="seo_focus">
                                        <option value="informational"><?php _e('Educational content (How-to, Guides)', 'rss-auto-publisher'); ?></option>
                                        <option value="commercial"><?php _e('Review content (Best of, Comparisons)', 'rss-auto-publisher'); ?></option>
                                        <option value="transactional"><?php _e('Action-focused (Sign-up, Buy)', 'rss-auto-publisher'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="target_keywords"><?php _e('Focus Keywords', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <input type="text" name="target_keywords" id="target_keywords" 
                                           placeholder="e.g., sports betting, casino games, fantasy football"
                                           class="regular-text">
                                    <p class="description"><?php _e('Keywords to target for SEO (comma-separated)', 'rss-auto-publisher'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="content_length"><?php _e('Article Length', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <select name="content_length" id="content_length">
                                        <option value="800-1200"><?php _e('Short (800-1200 words)', 'rss-auto-publisher'); ?></option>
                                        <option value="1200-1800" selected><?php _e('Medium (1200-1800 words)', 'rss-auto-publisher'); ?></option>
                                        <option value="1800-2500"><?php _e('Long (1800-2500 words)', 'rss-auto-publisher'); ?></option>
                                        <option value="2500-3500"><?php _e('Extra Long (2500-3500 words)', 'rss-auto-publisher'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="min_article_words"><?php _e('Minimum Words', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <input type="number" name="min_article_words" id="min_article_words" value="1200" min="500" max="5000">
                                    <p class="description"><?php _e('Regenerate if article is shorter than this', 'rss-auto-publisher'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="target_audience"><?php _e('Target Audience', 'rss-auto-publisher'); ?></label></th>
                                <td>
                                    <input type="text" name="target_audience" id="target_audience" 
                                           placeholder="e.g., beginner bettors, experienced traders, casual fans"
                                           class="regular-text">
                                </td>
                            </tr>
                        </tbody>
                        
                        <tr>
                            <th><label for="min_word_count"><?php _e('Min Word Count', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="min_word_count" id="min_word_count" value="10" min="5" max="5000">
                                <p class="description"><?php _e('Skip items with fewer words (ignored when AI enhancement is enabled)', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="items_per_import"><?php _e('Items Per Import', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="items_per_import" id="items_per_import">
                                    <option value="1">1</option>
                                    <option value="3" selected>3</option>
                                    <option value="5">5</option>
                                    <option value="10">10</option>
                                </select>
                                <p class="description"><?php _e('Reduced for GPT-5 due to longer processing times', 'rss-auto-publisher'); ?></p>
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
            
            <script>
            function toggleAdvancedOptions() {
                const options = document.getElementById('advanced-options');
                const isVisible = options.style.display !== 'none';
                options.style.display = isVisible ? 'none' : 'table-row-group';
            }
            </script>
            
            <?php if (!empty($feeds)): ?>
            <div class="rsp-card">
                <h2><?php _e('Existing Feeds', 'rss-auto-publisher'); ?></h2>
                
                <div class="rsp-feeds-grid">
                    <?php foreach ($feeds as $feed): 
                        $category = get_term($feed->category_id);
                        $languages_array = json_decode($feed->target_languages, true) ?: [];
                        
                        // Get article count and stats for this feed
                        global $wpdb;
                        $processed_table = $wpdb->prefix . 'rsp_processed';
                        $article_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $processed_table WHERE feed_id = %d AND post_id IS NOT NULL",
                            $feed->id
                        ));
                        
                        // Get average word count
                        $avg_words = $wpdb->get_var($wpdb->prepare(
                            "SELECT AVG(meta_value) FROM {$wpdb->postmeta} pm
                            JOIN {$processed_table} p ON pm.post_id = p.post_id
                            WHERE p.feed_id = %d AND pm.meta_key = '_rsp_word_count'",
                            $feed->id
                        ));
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
                                <span class="meta-label"><?php _e('GPT-5 Mode:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value">
                                    <?php 
                                    if ($feed->enable_enhancement) {
                                        $verbosity = $feed->gpt5_verbosity ?? 'high';
                                        echo '✓ ' . ucfirst($verbosity);
                                    } else {
                                        echo '✗';
                                    }
                                    ?>
                                </span>
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
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Target Length:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value"><?php echo $feed->content_length ?? '1200-1800'; ?></span>
                            </div>
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Min Words:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value"><?php echo $feed->min_article_words ?? 1200; ?></span>
                            </div>
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Articles:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value" style="color: #2271b1; font-weight: 600;"><?php echo $article_count; ?></span>
                            </div>
                            
                            <div class="feed-meta-item">
                                <span class="meta-label"><?php _e('Avg Words:', 'rss-auto-publisher'); ?></span>
                                <span class="meta-value"><?php echo $avg_words ? round($avg_words) : '-'; ?></span>
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
     * Render settings page with GPT-5 optimizations
     */
    public function render_settings_page() {
        $api_key = get_option('rsp_openai_api_key');
        $use_responses_api = get_option('rsp_use_gpt5_responses_api', 'yes');
        $default_verbosity = get_option('rsp_gpt5_verbosity', 'high');
        $default_reasoning = get_option('rsp_gpt5_reasoning_effort', 'high');
        $min_article_words = get_option('rsp_min_article_words', 1200);
        $max_retries = get_option('rsp_gpt5_max_retries', 3);
        $default_content_length = get_option('rsp_default_content_length', '1200-1800');
        
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
                                <p class="description"><?php _e('Latest model with enhanced capabilities', 'rss-auto-publisher'); ?></p>
                                <ul class="description">
                                    <li><?php printf(__('Context Window: %s tokens', 'rss-auto-publisher'), number_format($model_info['context_window'])); ?></li>
                                    <li><?php printf(__('Max Output: %s tokens', 'rss-auto-publisher'), number_format($model_info['max_output'])); ?></li>
                                    <li><?php _e('Features: Verbosity Control, Reasoning Effort, Responses API', 'rss-auto-publisher'); ?></li>
                                </ul>
                            </td>
                        </tr>
                        
                        <tr>
                            <th colspan="2">
                                <h3><?php _e('GPT-5 Specific Settings', 'rss-auto-publisher'); ?></h3>
                            </th>
                        </tr>
                        
                        <tr>
                            <th><label for="use_responses_api"><?php _e('Use Responses API', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="use_responses_api" id="use_responses_api">
                                    <option value="yes" <?php selected($use_responses_api, 'yes'); ?>><?php _e('Yes (Recommended)', 'rss-auto-publisher'); ?></option>
                                    <option value="no" <?php selected($use_responses_api, 'no'); ?>><?php _e('No (Use Chat Completions)', 'rss-auto-publisher'); ?></option>
                                </select>
                                <p class="description"><?php _e('GPT-5 Responses API provides better performance for content generation', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="gpt5_verbosity"><?php _e('Default Verbosity', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="gpt5_verbosity" id="gpt5_verbosity">
                                    <option value="low" <?php selected($default_verbosity, 'low'); ?>><?php _e('Low (Concise)', 'rss-auto-publisher'); ?></option>
                                    <option value="medium" <?php selected($default_verbosity, 'medium'); ?>><?php _e('Medium (Balanced)', 'rss-auto-publisher'); ?></option>
                                    <option value="high" <?php selected($default_verbosity, 'high'); ?>><?php _e('High (Detailed - Recommended)', 'rss-auto-publisher'); ?></option>
                                </select>
                                <p class="description"><?php _e('Controls the length and detail of generated content. High verbosity is recommended for articles.', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="gpt5_reasoning_effort"><?php _e('Reasoning Effort', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="gpt5_reasoning_effort" id="gpt5_reasoning_effort">
                                    <option value="minimal" <?php selected($default_reasoning, 'minimal'); ?>><?php _e('Minimal (Fast)', 'rss-auto-publisher'); ?></option>
                                    <option value="medium" <?php selected($default_reasoning, 'medium'); ?>><?php _e('Medium (Balanced)', 'rss-auto-publisher'); ?></option>
                                    <option value="high" <?php selected($default_reasoning, 'high'); ?>><?php _e('High (Best Quality - Recommended)', 'rss-auto-publisher'); ?></option>
                                </select>
                                <p class="description"><?php _e('Higher reasoning effort produces better quality content but takes more time.', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_article_words"><?php _e('Minimum Article Words', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="min_article_words" id="min_article_words" value="<?php echo $min_article_words; ?>" min="500" max="5000">
                                <p class="description"><?php _e('Articles shorter than this will trigger regeneration with higher verbosity', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="gpt5_max_retries"><?php _e('Max Regeneration Attempts', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="gpt5_max_retries" id="gpt5_max_retries" value="<?php echo $max_retries; ?>" min="1" max="5">
                                <p class="description"><?php _e('Maximum attempts to regenerate if content is too short', 'rss-auto-publisher'); ?></p>
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
                        
                        <tr>
                            <th><label for="test_gpt5_connection"><?php _e('Test GPT-5 Connection', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <button type="button" class="button" id="test-gpt5-connection"><?php _e('Test Connection', 'rss-auto-publisher'); ?></button>
                                <div id="gpt5-test-results" style="margin-top: 10px;"></div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="rsp-card">
                    <h2><?php _e('Content Generation Settings', 'rss-auto-publisher'); ?></h2>
                    
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
                            <th><label for="default_content_length"><?php _e('Default Content Length', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <select name="default_content_length" id="default_content_length">
                                    <option value="800-1200" <?php selected($default_content_length, '800-1200'); ?>><?php _e('Short (800-1200 words)', 'rss-auto-publisher'); ?></option>
                                    <option value="1200-1800" <?php selected($default_content_length, '1200-1800'); ?>><?php _e('Medium (1200-1800 words)', 'rss-auto-publisher'); ?></option>
                                    <option value="1800-2500" <?php selected($default_content_length, '1800-2500'); ?>><?php _e('Long (1800-2500 words)', 'rss-auto-publisher'); ?></option>
                                    <option value="2500-3500" <?php selected($default_content_length, '2500-3500'); ?>><?php _e('Extra Long (2500-3500 words)', 'rss-auto-publisher'); ?></option>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="queue_batch_size"><?php _e('Queue Batch Size', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <input type="number" name="queue_batch_size" id="queue_batch_size" value="<?php echo get_option('rsp_queue_batch_size', 3); ?>" min="1" max="10">
                                <p class="description"><?php _e('Reduced to 3 recommended for GPT-5', 'rss-auto-publisher'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="enable_content_validation"><?php _e('Content Validation', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_content_validation" id="enable_content_validation" value="1" <?php checked(get_option('rsp_enable_content_validation', 1), 1); ?>>
                                    <?php _e('Validate and regenerate short content automatically', 'rss-auto-publisher'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="enable_debug_logging"><?php _e('Debug Logging', 'rss-auto-publisher'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_debug_logging" id="enable_debug_logging" value="1" <?php checked(get_option('rsp_enable_debug_logging', 0), 1); ?>>
                                    <?php _e('Enable detailed debug logging', 'rss-auto-publisher'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(__('Save Settings', 'rss-auto-publisher')); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-gpt5-connection').on('click', function() {
                var $btn = $(this);
                var $results = $('#gpt5-test-results');
                
                $btn.prop('disabled', true).text('Testing...');
                $results.html('<p>Testing GPT-5 connection...</p>');
                
                $.post(ajaxurl, {
                    action: 'rsp_test_gpt5',
                    nonce: '<?php echo wp_create_nonce('rsp-ajax'); ?>'
                })
                .done(function(response) {
                    if (response.success) {
                        $results.html('<div class="notice notice-success inline"><p><strong>Success!</strong><br>' + 
                            'API: ' + response.data.api_type + '<br>' +
                            'Model: ' + response.data.model + '<br>' +
                            'Generated ' + response.data.word_count + ' words<br>' +
                            'Time: ' + response.data.time + 's</p></div>');
                    } else {
                        $results.html('<div class="notice notice-error inline"><p><strong>Error:</strong> ' + response.data + '</p></div>');
                    }
                })
                .fail(function() {
                    $results.html('<div class="notice notice-error inline"><p>Connection test failed.</p></div>');
                })
                .always(function() {
                    $btn.prop('disabled', false).text('Test Connection');
                });
            });
        });
        </script>
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
                
                <p style="margin-top: 20px;">
                    <button class="button button-primary" id="process-queue-now">
                        <?php _e('Process Queue Now', 'rss-auto-publisher'); ?>
                    </button>
                    
                    <?php if ($stats->pending > 0 || $stats->failed > 0): ?>
                    <button class="button" id="clear-pending-queue" style="margin-left: 10px;">
                        <?php _e('Clear Pending Items', 'rss-auto-publisher'); ?>
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($stats->total > 0): ?>
                    <button class="button button-link-delete" id="clear-all-queue" style="margin-left: 10px;">
                        <?php _e('Clear All Items', 'rss-auto-publisher'); ?>
                    </button>
                    <?php endif; ?>
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
    
    public function ajax_test_gpt5() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        $start_time = microtime(true);
        
        $openai = new RSP_OpenAI();
        
        if (!$openai->is_configured()) {
            wp_send_json_error('API key not configured');
        }
        
        $test_settings = [
            'content_length' => '200-300',
            'gpt5_verbosity' => 'medium',
            'gpt5_reasoning' => 'minimal'
        ];
        
        $result = $openai->create_content_from_title(
            'Test Article for GPT-5 Connection',
            'This is a test to verify GPT-5 API connectivity',
            $test_settings
        );
        
        $end_time = microtime(true);
        $elapsed = round($end_time - $start_time, 2);
        
        if ($result && isset($result['content'])) {
            $word_count = str_word_count(strip_tags($result['content']));
            
            wp_send_json_success([
                'api_type' => 'GPT-5 Responses API',
                'model' => 'gpt-5',
                'word_count' => $word_count,
                'time' => $elapsed,
                'message' => 'Connection successful!'
            ]);
        } else {
            wp_send_json_error('Failed to generate content. Check error logs.');
        }
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
    
    public function ajax_clear_queue() {
        check_ajax_referer('rsp-ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';
        
        $cleared = RSP_Queue::clear_queue($status);
        
        if ($cleared !== false) {
            $message = $status === 'all' ? 
                'All queue items cleared' : 
                sprintf('%d %s items cleared', $cleared, $status);
            
            wp_send_json_success(['message' => $message]);
        }
        
        wp_send_json_error('Failed to clear queue');
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
            'items_per_import' => intval($_POST['items_per_import']),
            'content_domain' => sanitize_text_field($_POST['content_domain'] ?? 'auto'),
            'content_angle' => sanitize_text_field($_POST['content_angle'] ?? 'auto'),
            'seo_focus' => sanitize_text_field($_POST['seo_focus'] ?? 'informational'),
            'target_keywords' => sanitize_text_field($_POST['target_keywords'] ?? ''),
            'content_length' => sanitize_text_field($_POST['content_length'] ?? '1200-1800'),
            'target_audience' => sanitize_text_field($_POST['target_audience'] ?? ''),
            'universal_prompt' => sanitize_textarea_field($_POST['universal_prompt'] ?? ''),
            'gpt5_verbosity' => sanitize_text_field($_POST['gpt5_verbosity'] ?? 'high'),
            'gpt5_reasoning' => sanitize_text_field($_POST['gpt5_reasoning'] ?? 'high'),
            'min_article_words' => intval($_POST['min_article_words'] ?? 1200)
        ];
        
        $feed_id = RSP_Database::add_feed($data);
        
        if ($feed_id) {
            RSP_Cron::schedule_feed($feed_id, $data['update_frequency']);
            wp_redirect(admin_url('admin.php?page=rsp-settings&saved=1'));
        exit;
    }
}page=rss-auto-publisher&added=1'));
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
            'items_per_import' => intval($_POST['items_per_import']),
            'content_domain' => sanitize_text_field($_POST['content_domain'] ?? 'auto'),
            'content_angle' => sanitize_text_field($_POST['content_angle'] ?? 'auto'),
            'seo_focus' => sanitize_text_field($_POST['seo_focus'] ?? 'informational'),
            'target_keywords' => sanitize_text_field($_POST['target_keywords'] ?? ''),
            'content_length' => sanitize_text_field($_POST['content_length'] ?? '1200-1800'),
            'target_audience' => sanitize_text_field($_POST['target_audience'] ?? ''),
            'universal_prompt' => sanitize_textarea_field($_POST['universal_prompt'] ?? ''),
            'gpt5_verbosity' => sanitize_text_field($_POST['gpt5_verbosity'] ?? 'high'),
            'gpt5_reasoning' => sanitize_text_field($_POST['gpt5_reasoning'] ?? 'high'),
            'min_article_words' => intval($_POST['min_article_words'] ?? 1200)
        ];
        
        if (RSP_Database::update_feed($feed_id, $data)) {
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
        
        // Basic settings
        update_option('rsp_openai_api_key', sanitize_text_field($_POST['openai_api_key']));
        update_option('rsp_default_post_status', sanitize_text_field($_POST['default_post_status']));
        update_option('rsp_queue_batch_size', intval($_POST['queue_batch_size'] ?? 3));
        
        // GPT-5 specific settings
        update_option('rsp_use_gpt5_responses_api', sanitize_text_field($_POST['use_responses_api'] ?? 'yes'));
        update_option('rsp_gpt5_verbosity', sanitize_text_field($_POST['gpt5_verbosity'] ?? 'high'));
        update_option('rsp_gpt5_reasoning_effort', sanitize_text_field($_POST['gpt5_reasoning_effort'] ?? 'high'));
        update_option('rsp_min_article_words', intval($_POST['min_article_words'] ?? 1200));
        update_option('rsp_gpt5_max_retries', intval($_POST['gpt5_max_retries'] ?? 3));
        update_option('rsp_default_content_length', sanitize_text_field($_POST['default_content_length'] ?? '1200-1800'));
        update_option('rsp_enable_content_validation', isset($_POST['enable_content_validation']) ? 1 : 0);
        update_option('rsp_enable_debug_logging', isset($_POST['enable_debug_logging']) ? 1 : 0);
        
        wp_redirect(admin_url('admin.php??page=rsp-settings&saved=1'));
        exit;
    }
}
