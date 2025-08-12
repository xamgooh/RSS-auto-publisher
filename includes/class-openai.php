<?php
/**
 * OpenAI GPT-5 Integration for RSS Auto Publisher
 * Uses the /v1/responses endpoint with GPT-5
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
    private $api_url = 'https://api.openai.com/v1/responses';
    
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
        
        // Build the enhancement input with role-based messages
        $input = $this->build_enhancement_input($title, $content, $options);
        
        // Make API call
        $response = $this->call_api($input);
        
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
        $input = [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => "You are a professional translator. Translate content to {$target_name} while preserving HTML formatting, tone, and ensuring natural fluent language."]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => "Translate this to {$target_name}. Return as JSON with 'title' and 'content' fields:\n\nTitle: {$title}\n\nContent:\n{$content}"]
                ]
            ]
        ];
        
        // Make API call
        $response = $this->call_api($input, 0.3); // Lower temperature for translation
        
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
        
        // Build input array for GPT-5 /v1/responses format
        $input = [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $system_prompt]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text', 
                        'text' => "Enhance this content following these requirements:\n- {$requirements_text}\n\nReturn as JSON with 'title' and 'content' fields.\n\nOriginal Title: {$title}\n\nOriginal Content:\n{$content}"
                    ]
                ]
            ]
        ];
        
        return $input;
    }
    
    /**
     * Call OpenAI GPT-5 API using /v1/responses endpoint
     */
    private function call_api($input, $temperature = 0.7) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        // Build request body for GPT-5
        $body = [
            'model' => $this->model,
            'input' => $input,
            'temperature' => $temperature,
            'max_output_tokens' => 4000, // Reasonable limit for articles
            'text' => [
                'format' => [
                    'type' => 'json' // Request JSON format
                ]
            ]
        ];
        
        $response = wp_remote_post($this->api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 90, // Increased timeout for GPT-5
        ]);
        
        if (is_wp_error($response)) {
            error_log('GPT-5 API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            error_log('GPT-5 API Invalid Response: ' . $body);
            return false;
        }
        
        // Check for errors
        if (isset($data['error'])) {
            error_log('GPT-5 API Error: ' . json_encode($data['error']));
            
            // Check for rate limit errors
            if ($response_code === 429) {
                $this->handle_rate_limit($data['error']);
            }
            
            return false;
        }
        
        // Check response status
        if ($data['status'] !== 'completed') {
            error_log('GPT-5 API: Response not completed - Status: ' . $data['status']);
            return false;
        }
        
        // Extract the text from the GPT-5 response format
        if (!isset($data['output'][0]['content'][0]['text'])) {
            error_log('GPT-5 API: Unexpected response format');
            return false;
        }
        
        // Record usage for cost tracking
        if (isset($data['usage'])) {
            $this->record_usage($data['usage']);
        }
        
        return $data['output'][0]['content'][0]['text'];
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
        
        error_log("GPT-5 Rate Limited - Retry after {$retry_after} seconds");
        
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
        $message .= "Your current tier limits (Tier 2):\n";
        $message .= "- 5,000 requests per minute\n";
        $message .= "- 450,000 tokens per minute\n\n";
        $message .= "Consider upgrading your OpenAI tier for higher limits.";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Record API usage for cost tracking
     */
    private function record_usage($usage_data) {
        $input_tokens = $usage_data['input_tokens'] ?? 0;
        $output_tokens = $usage_data['output_tokens'] ?? 0;
        $total_tokens = $usage_data['total_tokens'] ?? 0;
        
        // Check for cached tokens (90% discount)
        $cached_tokens = 0;
        if (isset($usage_data['input_tokens_details']['cached_tokens'])) {
            $cached_tokens = $usage_data['input_tokens_details']['cached_tokens'];
        }
        
        $non_cached_input = $input_tokens - $cached_tokens;
        
        // Calculate cost based on GPT-5 pricing
        $cost = 0;
        $cost += ($non_cached_input / 1000000) * $this->pricing['input'];
        $cost += ($cached_tokens / 1000000) * $this->pricing['cached_input'];
        $cost += ($output_tokens / 1000000) * $this->pricing['output'];
        
        // Store detailed usage
        RSP_Database::record_api_usage('openai-gpt5', 'response', $total_tokens, $cost, true);
        
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
        
        // Calculate remaining capacity (Tier 2 limits)
        $stats->rpm_limit = 5000;
        $stats->tpm_limit = 450000;
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
            'tier' => 2,
            'tier_limits' => [
                'rpm' => 5000,
                'tpm' => 450000,
                'batch_queue' => 1350000
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
