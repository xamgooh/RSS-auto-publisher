<?php
/**
 * OpenAI GPT-5 Integration for RSS Auto Publisher
 * Uses the /v1/chat/completions endpoint with GPT-5
 * Version 1.3.0 - Production ready with all fixes applied
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_OpenAI {
    /**
     * API configuration
     */
    private $api_key;
    private $model = 'gpt-5';
    private $api_url = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Model pricing per 1M tokens
     */
    private $pricing = [
        'input' => 1.25,
        'cached_input' => 0.125,
        'output' => 10.00
    ];
    
    /**
     * Supported languages
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
        
        $response = $this->call_api($messages);
        if (!$response) {
            return false;
        }
        
        return $this->parse_json_response($response);
    }
    
    private function build_smart_prompt($title, $analysis, $settings) {
        $domain = $analysis['domain'];
        $angle = $analysis['suggested_angle'];
        $keywords = implode(', ', $analysis['seo_keywords']);
        $audience = $settings['target_audience'] ?? '';
        $length = $settings['content_length'] ?? '900-1500';
        $custom_prompt = $settings['universal_prompt'] ?? '';
        
        $domain_instructions = $this->get_domain_instructions($domain, $analysis['gambling_category']);
        $angle_instructions = $this->get_angle_instructions($angle);
        
        $prompt = "
        Create a comprehensive article based on this title: '{$title}'
        
        Target Length: {$length} words
        Writing Style: {$angle_instructions}
        Keywords: {$keywords}
        
        {$domain_instructions}
        
        Structure:
        1. Engaging introduction
        2. 3-4 main content sections with subheadings
        3. Practical tips section
        4. Brief FAQ (2-3 questions)
        5. Conclusion
        
        Return as JSON: {\"title\": \"SEO-optimized title\", \"content\": \"Full HTML article with <h2>, <h3>, <p>, <ul>, <li> tags\"}
        ";
        
        if ($custom_prompt) {
            $prompt .= "\nAdditional instructions: {$custom_prompt}";
        }
        
        return $prompt;
    }
    
    private function get_domain_instructions($domain, $gambling_category = null) {
        $instructions = [
            'gambling' => 'GAMBLING FOCUS: Include responsible gambling disclaimers. Focus on strategy and education. Mention odds and bankroll management.',
            'sports' => 'SPORTS FOCUS: Include player stats, team analysis, and strategic insights. Use current season data and fantasy-relevant information.',
            'technology' => 'TECH FOCUS: Explain technical concepts clearly, include practical applications and latest trends.',
            'business' => 'BUSINESS FOCUS: Include actionable advice, market insights, and practical implementation tips.',
            'health' => 'HEALTH FOCUS: Provide evidence-based information and safety considerations.',
            'lifestyle' => 'LIFESTYLE FOCUS: Provide practical advice and actionable tips.',
            'news' => 'NEWS FOCUS: Provide balanced analysis and multiple perspectives.'
        ];
        
        $base_instruction = $instructions[$domain] ?? 'Focus on providing valuable, accurate information with practical applications.';
        
        if ($domain === 'gambling' && $gambling_category) {
            $gambling_specifics = [
                'sports_betting' => ' Focus on betting strategies and odds analysis.',
                'casino' => ' Focus on game strategies and RTP analysis.',
                'poker' => ' Focus on poker strategy and tournament tips.',
                'horse_racing' => ' Focus on handicapping and track analysis.',
                'esports_betting' => ' Focus on esports knowledge and team analysis.'
            ];
            
            $base_instruction .= $gambling_specifics[$gambling_category] ?? '';
        }
        
        return $base_instruction;
    }
    
    private function get_angle_instructions($angle) {
        $instructions = [
            'beginner_guide' => 'Write for newcomers. Explain basics clearly and provide step-by-step guidance.',
            'expert_analysis' => 'Write for experienced readers. Include advanced strategies and detailed analysis.',
            'practical_tips' => 'Focus on actionable advice and specific tips.',
            'comparison' => 'Compare options objectively with pros/cons.',
            'prediction' => 'Analyze trends and provide data-supported forecasts.',
            'contrarian_view' => 'Challenge conventional wisdom with alternative perspectives.'
        ];
        
        return $instructions[$angle] ?? 'Provide valuable insights and practical information.';
    }
    
    /**
     * Enhance content using GPT-5
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
        $messages = $this->build_enhancement_input($title, $content, $options);
        
        $response = $this->call_api($messages);
        if (!$response) {
            return false;
        }
        
        return $this->parse_json_response($response);
    }
    
    /**
     * Translate content using GPT-5
     */
    public function translate_content($title, $content, $target_language, $source_language = 'auto') {
        if (!$this->is_configured()) {
            return false;
        }
        
        $target_name = $this->supported_languages[$target_language] ?? $target_language;
        
        $messages = [
            [
                'role' => 'system',
                'content' => "You are a professional translator. Translate content to {$target_name} while preserving HTML formatting."
            ],
            [
                'role' => 'user',
                'content' => "Translate to {$target_name}. Return as JSON with 'title' and 'content' fields:\n\nTitle: {$title}\n\nContent:\n{$content}"
            ]
        ];
        
        $response = $this->call_api($messages);
        if (!$response) {
            return false;
        }
        
        return $this->parse_json_response($response);
    }
    
    /**
     * Build enhancement input for GPT-5
     */
    private function build_enhancement_input($title, $content, $options) {
        $system_prompt = "You are an expert content writer and editor specializing in ";
        
        switch ($options['style']) {
            case 'professional':
                $system_prompt .= "professional, authoritative business content.";
                break;
            case 'casual':
                $system_prompt .= "conversational, engaging content.";
                break;
            case 'technical':
                $system_prompt .= "technical writing with precision.";
                break;
            case 'creative':
                $system_prompt .= "creative, captivating storytelling.";
                break;
        }
        
        $requirements = [
            "Improve clarity and engagement",
            "Fix grammar and spelling",
            "Enhance structure and flow",
            "Preserve factual information",
            "Maintain HTML formatting"
        ];
        
        if ($options['length'] === 'shorter') {
            $requirements[] = "Make content 20-30% more concise";
        } elseif ($options['length'] === 'longer') {
            $requirements[] = "Expand content by 30-50% with relevant details";
        }
        
        if ($options['seo_focus'] && !empty($options['keywords'])) {
            $keywords = implode(', ', $options['keywords']);
            $requirements[] = "Optimize for SEO keywords: {$keywords}";
        }
        
        if (!empty($options['custom_prompt'])) {
            $requirements[] = $options['custom_prompt'];
        }
        
        $requirements_text = implode("\n- ", $requirements);
        
        return [
            [
                'role' => 'system',
                'content' => $system_prompt
            ],
            [
                'role' => 'user',
                'content' => "Enhance this content:\n- {$requirements_text}\n\nReturn as JSON with 'title' and 'content' fields.\n\nTitle: {$title}\n\nContent:\n{$content}"
            ]
        ];
    }
    
    /**
     * Call OpenAI GPT-5 API
     */
    private function call_api($messages) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => 2500,
            'response_format' => [
                'type' => 'json_object'
            ]
        ];
        
        $response = wp_remote_post($this->api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 180,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if (empty($response_body)) {
            error_log('RSS Auto Publisher: Empty response from API');
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        if (!$data) {
            error_log('RSS Auto Publisher: Failed to decode API response');
            return false;
        }
        
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher API Error: ' . json_encode($data['error']));
            
            if ($response_code === 429) {
                $this->handle_rate_limit($data['error']);
            }
            
            return false;
        }
        
        // Extract content from response - handles multiple formats
        $content = null;
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
        } elseif (isset($data['output_text'])) {
            $content = $data['output_text'];
        } elseif (isset($data['output'])) {
            $text_parts = [];
            if (is_array($data['output'])) {
                foreach ($data['output'] as $segment) {
                    if (isset($segment['content'])) {
                        foreach ($segment['content'] as $content_item) {
                            if (isset($content_item['text'])) {
                                $text_parts[] = $content_item['text'];
                            }
                        }
                    }
                    if (isset($segment['text'])) {
                        $text_parts[] = $segment['text'];
                    }
                }
            }
            $content = implode('', $text_parts);
        } elseif (isset($data['content'])) {
            $content = $data['content'];
        } elseif (isset($data['result'])) {
            $content = $data['result'];
        }
        
        if (isset($data['usage'])) {
            $this->record_usage($data['usage']);
        }
        
        return $content;
    }
    
    /**
     * Parse JSON response from GPT-5
     */
    private function parse_json_response($text) {
        if (empty($text)) {
            return false;
        }
        
        $decoded = json_decode($text, true);
        
        if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
            return $decoded;
        }
        
        // Try extracting JSON from markdown code blocks
        if (preg_match('/```json\s*(.+?)\s*```/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
        }
        
        // Try finding JSON pattern in text
        if (preg_match('/\{[^}]*"title"[^}]*"content"[^}]*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
        }
        
        return $this->parse_enhancement_response($text);
    }
    
    /**
     * Parse enhancement response (fallback)
     */
    private function parse_enhancement_response($text) {
        $result = [
            'title' => '',
            'content' => ''
        ];
        
        if (preg_match('/(?:TITLE|Title):\s*(.+?)(?:\n|$)/i', $text, $title_match)) {
            $result['title'] = trim($title_match[1]);
        }
        
        if (preg_match('/(?:CONTENT|Content):\s*(.+)/is', $text, $content_match)) {
            $result['content'] = trim($content_match[1]);
        }
        
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
        $retry_after = isset($error['retry_after']) ? intval($error['retry_after']) : 60;
        set_transient('rsp_rate_limited', true, $retry_after);
        
        error_log("RSS Auto Publisher: Rate limited - retry after {$retry_after} seconds");
        
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
        
        $cost = 0;
        $cost += ($input_tokens / 1000000) * $this->pricing['input'];
        $cost += ($output_tokens / 1000000) * $this->pricing['output'];
        
        RSP_Database::record_api_usage('openai-gpt5', 'chat/completions', $total_tokens, $cost, true);
        
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
        
        $daily_usage = get_option('rsp_daily_token_usage', []);
        $stats->daily_tokens = $daily_usage['tokens'] ?? 0;
        $stats->daily_requests = $daily_usage['requests'] ?? 0;
        $stats->daily_cost = $daily_usage['cost'] ?? 0;
        
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
