<?php
/**
 * OpenAI GPT-5 Integration for RSS Auto Publisher
 * Uses the /v1/chat/completions endpoint with GPT-5
 * Version 1.1.2 - Fixed max_completion_tokens and temperature parameters
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_OpenAI {
    /**
     * API configuration
     */
    private $api_key;
    private $model = 'gpt-5'; // Fixed to GPT-5 (latest flagship model)
    private $api_url = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Model pricing per 1M tokens
     */
    private $pricing = [
        'input' => 1.25,  // $1.25 per 1M input tokens
        'cached_input' => 0.125, // $0.125 per 1M cached input tokens
        'output' => 10.00  // $10.00 per 1M output tokens
    ];
    
    /**
     * Supported languages (from original plugin)
     */
    private $supported_languages = [
        'es' => 'Spanish',
        'it' => 'Italian', 
        'pt' => 'Portuguese',
        'no' => 'Norwegian',
        'sv' => 'Swedish',
        'fi' => 'Finnish',
        'et' => 'Estonian'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key = get_option('rsp_openai_api_key');
    }
    
    /**
     * Check if API is configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Get supported languages
     */
    public function get_supported_languages() {
        return $this->supported_languages;
    }
    
    /**
     * Enhanced content creation using title analysis
     */
    public function create_content_from_title($title, $description, $feed_settings) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Analyze the content
        $analyzer = new RSP_Content_Analyzer();
        $analysis = $analyzer->analyze_content($title, $description, $feed_settings);
        
        // Build enhanced prompt
        $prompt = $this->build_smart_prompt($title, $analysis, $feed_settings);
        
        // Generate content
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert content writer specializing in creating engaging, SEO-optimized articles from news titles and topics.'
            ],
            [
                'role' => 'user', 
                'content' => $prompt
            ]
        ];
        
        // GPT-5 requires temperature = 1 (default)
        $response = $this->call_api($messages, 1);
        if (!$response) {
            return false;
        }
        
        $parsed = $this->parse_json_response($response);
        return $parsed;
    }
    
    private function build_smart_prompt($title, $analysis, $settings) {
        $domain = $analysis['domain'];
        $angle = $analysis['suggested_angle'];
        $keywords = implode(', ', $analysis['seo_keywords']);
        $audience = $settings['target_audience'] ?? '';
        $length = $settings['content_length'] ?? '900-1500';
        $custom_prompt = $settings['universal_prompt'] ?? '';
        
        // Domain-specific instructions
        $domain_instructions = $this->get_domain_instructions($domain, $analysis['gambling_category']);
        $angle_instructions = $this->get_angle_instructions($angle);
        
        $prompt = "
        Create an original, comprehensive article based on this title: '{$title}'
        
        CONTENT SPECIFICATIONS:
        - Content Type: {$domain}" . ($analysis['gambling_category'] ? " ({$analysis['gambling_category']})" : "") . "
        - Writing Style: {$angle_instructions}
        - Target Length: {$length} words
        - Target Audience: " . ($audience ?: 'General readers interested in ' . $domain) . "
        
        SEO REQUIREMENTS:
        - Focus Keywords: {$keywords} (use naturally throughout)
        - Include engaging subheadings (H2, H3 tags)
        - Write for featured snippets (include FAQ section)
        - Use bullet points and numbered lists where appropriate
        - Include specific numbers, statistics, and data points
        - Create scannable content with clear structure
        
        {$domain_instructions}
        
        CONTENT STRUCTURE:
        1. Engaging introduction (hook + preview of what readers will learn)
        2. Main content sections with clear, benefit-focused subheadings
        3. Practical tips or actionable advice section
        4. FAQ section (3-5 common questions with concise answers)
        5. Conclusion with key takeaways and call-to-action
        
        " . ($custom_prompt ? "ADDITIONAL INSTRUCTIONS: {$custom_prompt}" : "") . "
        
        Return as JSON with 'title' and 'content' fields.
        Create a new, SEO-friendly title that's more engaging and specific than the original.
        Make the content genuinely helpful and valuable to readers.
        ";
        
        return $prompt;
    }
    
    private function get_domain_instructions($domain, $gambling_category = null) {
        $instructions = [
            'gambling' => 'GAMBLING FOCUS: Include responsible gambling disclaimers. Focus on strategy, education, and informed decision-making. Mention odds, bankroll management, and legal considerations where relevant. Emphasize risk management and never guarantee wins.',
            'sports' => 'SPORTS FOCUS: Include player stats, team analysis, and strategic insights. Use current season data and provide fantasy-relevant information. Include injury updates and lineup considerations.',
            'technology' => 'TECH FOCUS: Explain technical concepts clearly, include practical applications, and mention latest trends or updates. Provide step-by-step guides where appropriate.',
            'business' => 'BUSINESS FOCUS: Include actionable business advice, market insights, and practical implementation tips. Use real-world examples and case studies.',
            'health' => 'HEALTH FOCUS: Provide evidence-based information, include safety considerations, and mention when to consult professionals. Avoid medical diagnosis or treatment claims.',
            'lifestyle' => 'LIFESTYLE FOCUS: Provide practical lifestyle advice, include personal experiences and relatable examples. Focus on actionable tips readers can implement.',
            'news' => 'NEWS FOCUS: Provide balanced analysis, include multiple perspectives, and explain the broader implications. Use factual, unbiased language.'
        ];
        
        $base_instruction = $instructions[$domain] ?? 'Focus on providing valuable, accurate information with practical applications.';
        
        // Add gambling subcategory specific instructions
        if ($domain === 'gambling' && $gambling_category) {
            $gambling_specifics = [
                'sports_betting' => ' Focus on betting strategies, odds analysis, and bankroll management for sports wagering.',
                'casino' => ' Focus on game strategies, RTP analysis, and casino bonus optimization.',
                'poker' => ' Focus on poker strategy, tournament tips, and skill development.',
                'horse_racing' => ' Focus on handicapping, track analysis, and betting systems.',
                'esports_betting' => ' Focus on esports knowledge, team analysis, and tournament betting strategies.'
            ];
            
            $base_instruction .= $gambling_specifics[$gambling_category] ?? '';
        }
        
        return $base_instruction;
    }
    
    private function get_angle_instructions($angle) {
        $instructions = [
            'beginner_guide' => 'Write for newcomers. Explain basics clearly, define terminology, and provide step-by-step guidance. Assume no prior knowledge.',
            'expert_analysis' => 'Write for experienced readers. Include advanced strategies, detailed analysis, and insider insights. Use industry terminology naturally.',
            'practical_tips' => 'Focus on actionable advice. Provide specific tips, techniques, and real-world applications. Include implementation steps.',
            'comparison' => 'Compare options objectively. Include pros/cons, feature comparisons, and clear recommendations based on different use cases.',
            'prediction' => 'Analyze trends and provide forecasts. Use data to support predictions and explain reasoning behind projections.',
            'contrarian_view' => 'Challenge conventional wisdom. Present alternative perspectives and explain why popular opinions might be wrong.'
        ];
        
        return $instructions[$angle] ?? 'Provide valuable insights and practical information that helps readers make informed decisions.';
    }
    
    /**
     * Enhance content using GPT-5 (legacy method for backwards compatibility)
     */
    public function enhance_content($title, $content, $options = []) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $defaults = [
            'style' => 'professional',
            'length' => 'similar',
            'seo_focus' => false,
            'keywords' => [],
            'custom_prompt' => ''
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        // Build the enhancement input with role-based messages
        $messages = $this->build_enhancement_input($title, $content, $options);
        
        // Make API call - GPT-5 requires temperature = 1
        $response = $this->call_api($messages, 1);
        
        if (!$response) {
            return false;
        }
        
        // Parse the response
        $enhanced = $this->parse_json_response($response);
        
        return $enhanced;
    }
    
    /**
     * Translate content using GPT-5
     */
    public function translate_content($title, $content, $target_language, $source_language = 'auto') {
        if (!$this->is_configured()) {
            return false;
        }
        
        $target_name = $this->supported_languages[$target_language] ?? $target_language;
        
        // Build translation input using role-based format
        $messages = [
            [
                'role' => 'system',
                'content' => "You are a professional translator. Translate content to {$target_name} while preserving HTML formatting, tone, and ensuring natural fluent language."
            ],
            [
                'role' => 'user',
                'content' => "Translate this to {$target_name}. Return as JSON with 'title' and 'content' fields:\n\nTitle: {$title}\n\nContent:\n{$content}"
            ]
        ];
        
        // Make API call - GPT-5 requires temperature = 1 (not 0.3)
        $response = $this->call_api($messages, 1);
        
        if (!$response) {
            return false;
        }
        
        // Parse the response
        $translated = $this->parse_json_response($response);
        
        return $translated;
    }
    
    /**
     * Build enhancement input for GPT-5
     */
    private function build_enhancement_input($title, $content, $options) {
        $system_prompt = "You are an expert content writer and editor specializing in ";
        
        // Style-specific instructions
        switch ($options['style']) {
            case 'professional':
                $system_prompt .= "professional, authoritative business content.";
                break;
            case 'casual':
                $system_prompt .= "conversational, engaging content that's easy to read.";
                break;
            case 'technical':
                $system_prompt .= "technical writing with precision and accuracy.";
                break;
            case 'creative':
                $system_prompt .= "creative, captivating storytelling.";
                break;
        }
        
        $requirements = [
            "Improve clarity, readability, and engagement",
            "Fix grammar and spelling errors",
            "Enhance structure and flow",
            "Preserve all factual information",
            "Maintain HTML formatting"
        ];
        
        // Length preference
        if ($options['length'] === 'shorter') {
            $requirements[] = "Make content 20-30% more concise";
        } elseif ($options['length'] === 'longer') {
            $requirements[] = "Expand content by 30-50% with relevant details";
        } else {
            $requirements[] = "Keep content length similar";
        }
        
        // SEO focus
        if ($options['seo_focus'] && !empty($options['keywords'])) {
            $keywords = implode(', ', $options['keywords']);
            $requirements[] = "Optimize for SEO keywords: {$keywords}";
        }
        
        // Custom prompt
        if (!empty($options['custom_prompt'])) {
            $requirements[] = $options['custom_prompt'];
        }
        
        $requirements_text = implode("\n- ", $requirements);
        
        // Build messages array for GPT-5 chat/completions format
        $messages = [
            [
                'role' => 'system',
                'content' => $system_prompt
            ],
            [
                'role' => 'user',
                'content' => "Enhance this content following these requirements:\n- {$requirements_text}\n\nReturn as JSON with 'title' and 'content' fields.\n\nOriginal Title: {$title}\n\nOriginal Content:\n{$content}"
            ]
        ];
        
        return $messages;
    }
    
    /**
     * Call OpenAI GPT-5 API using /v1/chat/completions endpoint
     * FIXED: Using max_completion_tokens instead of max_tokens
     * FIXED: Temperature must be 1 for GPT-5
     */
    private function call_api($messages, $temperature = 1) {
        // GPT-5 only supports temperature = 1
        $temperature = 1;
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        // Build request body for GPT-5 with FIXED parameters
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 1, // FIXED: GPT-5 only accepts temperature = 1
            'max_completion_tokens' => 4000, // FIXED: Changed from max_tokens
            'response_format' => [
                'type' => 'json_object'
            ]
        ];
        
        $response = wp_remote_post($this->api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 90, // Increased timeout for GPT-5
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $data = json_decode($response_body, true);
        
        if (!$data) {
            error_log('RSS Auto Publisher: Failed to decode API response');
            return false;
        }
        
        // Check for errors
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher API Error: ' . json_encode($data['error']));
            
            // Check for rate limit errors
            if ($response_code === 429) {
                $this->handle_rate_limit($data['error']);
            }
            
            // Special handling for parameter errors (in case API changes again)
            if (isset($data['error']['param'])) {
                error_log('RSS Auto Publisher: Parameter issue with ' . $data['error']['param']);
            }
            
            return false;
        }
        
        // Extract the text from the GPT-5 response format
        if (!isset($data['choices'][0]['message']['content'])) {
            error_log('RSS Auto Publisher: Unexpected response format from API');
            return false;
        }
        
        // Record usage for cost tracking
        if (isset($data['usage'])) {
            $this->record_usage($data['usage']);
        }
        
        return $data['choices'][0]['message']['content'];
    }
    
    /**
     * Parse JSON response from GPT-5
     */
    private function parse_json_response($text) {
        // Try to parse as JSON first
        $decoded = json_decode($text, true);
        
        if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
            return $decoded;
        }
        
        // Fallback: extract JSON from text if wrapped in markdown or other formatting
        if (preg_match('/```json\s*(.+?)\s*```/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
        }
        
        // Final fallback: try to find JSON anywhere in the text
        if (preg_match('/\{[^}]*"title"[^}]*"content"[^}]*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
        }
        
        // Use fallback parser if JSON parsing fails
        return $this->parse_enhancement_response($text);
    }
    
    /**
     * Parse enhancement response (fallback for non-JSON)
     */
    private function parse_enhancement_response($text) {
        $result = [
            'title' => '',
            'content' => ''
        ];
        
        // Look for TITLE: and CONTENT: markers
        if (preg_match('/(?:TITLE|Title):\s*(.+?)(?:\n|$)/i', $text, $title_match)) {
            $result['title'] = trim($title_match[1]);
        }
        
        if (preg_match('/(?:CONTENT|Content):\s*(.+)/is', $text, $content_match)) {
            $result['content'] = trim($content_match[1]);
        }
        
        // If still empty, treat first line as title, rest as content
        if (empty($result['title']) && empty($result['content'])) {
            $lines = explode("\n", $text, 2);
            $result['title'] = trim($lines[0]);
            $result['content'] = isset($lines[1]) ? trim($lines[1]) : '';
        }
        
        return $result;
    }
    
    /**
     * Handle rate limit errors
     */
    private function handle_rate_limit($error) {
        // Store rate limit info in transient
        $retry_after = isset($error['retry_after']) ? intval($error['retry_after']) : 60;
        set_transient('rsp_rate_limited', true, $retry_after);
        
        error_log("RSS Auto Publisher: GPT-5 Rate Limited - Retry after {$retry_after} seconds");
        
        // Send admin notification
        $this->send_rate_limit_notification($retry_after);
    }
    
    /**
     * Check if we're rate limited
     */
    public function is_rate_limited() {
        return get_transient('rsp_rate_limited') !== false;
    }
    
    /**
     * Send rate limit notification to admin
     */
    private function send_rate_limit_notification($retry_after) {
        $admin_email = get_option('admin_email');
        $subject = '[RSS Auto Publisher] GPT-5 Rate Limit Reached';
        $message = "Your RSS Auto Publisher has hit the GPT-5 rate limit.\n\n";
        $message .= "The system will automatically retry after {$retry_after} seconds.\n\n";
        $message .= "Your current tier limits (Tier 3):\n";
        $message .= "- 5,000 requests per minute\n";
        $message .= "- 800,000 tokens per minute\n\n";
        $message .= "Consider upgrading your OpenAI tier for higher limits.";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Record API usage for cost tracking
     */
    private function record_usage($usage_data) {
        $input_tokens = $usage_data['prompt_tokens'] ?? 0;
        $output_tokens = $usage_data['completion_tokens'] ?? 0;
        $total_tokens = $usage_data['total_tokens'] ?? 0;
        
        // Calculate cost based on GPT-5 pricing
        $cost = 0;
        $cost += ($input_tokens / 1000000) * $this->pricing['input'];
        $cost += ($output_tokens / 1000000) * $this->pricing['output'];
        
        // Store detailed usage
        RSP_Database::record_api_usage('openai-gpt5', 'chat/completions', $total_tokens, $cost, true);
        
        // Update daily usage counter for rate limiting awareness
        $daily_usage = get_option('rsp_daily_token_usage', [
            'date' => date('Y-m-d'),
            'tokens' => 0,
            'requests' => 0,
            'cost' => 0
        ]);
        
        if ($daily_usage['date'] !== date('Y-m-d')) {
            $daily_usage = [
                'date' => date('Y-m-d'),
                'tokens' => 0,
                'requests' => 0,
                'cost' => 0
            ];
        }
        
        $daily_usage['tokens'] += $total_tokens;
        $daily_usage['requests'] += 1;
        $daily_usage['cost'] += $cost;
        
        update_option('rsp_daily_token_usage', $daily_usage);
    }
    
    /**
     * Get API usage stats
     */
    public function get_usage_stats($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_api_usage';
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_requests,
                SUM(tokens_used) as total_tokens,
                SUM(cost_estimate) as total_cost,
                AVG(tokens_used) as avg_tokens,
                MAX(created_at) as last_used
             FROM $table 
             WHERE api_service = 'openai-gpt5' 
             AND created_at > %s",
            $since
        ));
        
        // Add daily usage
        $daily_usage = get_option('rsp_daily_token_usage', []);
        $stats->daily_tokens = $daily_usage['tokens'] ?? 0;
        $stats->daily_requests = $daily_usage['requests'] ?? 0;
        $stats->daily_cost = $daily_usage['cost'] ?? 0;
        
        // Calculate remaining capacity (Tier 3 limits)
        $stats->rpm_limit = 5000;
        $stats->tpm_limit = 800000;
        $stats->rpm_used = $stats->daily_requests;
        $stats->tpm_used = $stats->daily_tokens;
        $stats->rpm_remaining = max(0, $stats->rpm_limit - $stats->rpm_used);
        $stats->tpm_remaining = max(0, $stats->tpm_limit - $stats->tpm_used);
        
        return $stats;
    }
    
    /**
     * Get model information
     */
    public function get_model_info() {
        return [
            'model' => 'GPT-5',
            'version' => 'gpt-5-2025-08-07',
            'context_window' => 400000,
            'max_output' => 128000,
            'knowledge_cutoff' => 'September 30, 2024',
            'tier' => 3,
            'tier_limits' => [
                'rpm' => 5000,
                'tpm' => 800000,
                'batch_queue' => 100000000
            ],
            'pricing' => $this->pricing,
            'capabilities' => [
                'reasoning' => 'Higher',
                'speed' => 'Medium',
                'modalities' => ['text', 'image'],
                'tools' => ['web_search', 'file_search', 'image_generation', 'code_interpreter', 'mcp']
            ]
        ];
    }
}
