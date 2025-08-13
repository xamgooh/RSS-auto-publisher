<?php
/**
 * OpenAI GPT-5 Integration for RSS Auto Publisher
 * Version 3.0.0 - Properly implemented GPT-5 Responses API
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_OpenAI {
    /**
     * API configuration
     */
    private $api_key;
    private $model = 'gpt-5'; // Valid GPT-5 model
    private $responses_api_url = 'https://api.openai.com/v1/responses'; // GPT-5 Responses API
    private $chat_api_url = 'https://api.openai.com/v1/chat/completions'; // Fallback
    
    /**
     * Model pricing per 1M tokens (GPT-5 actual pricing)
     */
    private $pricing = [
        'input' => 15.00,
        'output' => 60.00
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
     * Create content using GPT-5 Responses API
     */
    public function create_content_from_title($title, $description, $feed_settings) {
        if (!$this->is_configured()) {
            error_log('RSS Auto Publisher: API key not configured');
            return false;
        }
        
        // Analyze the content
        $analyzer = new RSP_Content_Analyzer();
        $analysis = $analyzer->analyze_content($title, $description, $feed_settings);
        
        // Use GPT-5 Responses API first
        $use_responses_api = get_option('rsp_use_gpt5_responses_api', 'yes') === 'yes';
        
        if ($use_responses_api) {
            $response = $this->call_gpt5_responses_api($title, $description, $analysis, $feed_settings);
            
            // If Responses API fails, fallback to Chat Completions
            if (!$response) {
                error_log('RSS Auto Publisher: Responses API failed, falling back to Chat Completions');
                $response = $this->fallback_to_chat_completions($title, $description, $analysis, $feed_settings);
            }
        } else {
            $response = $this->fallback_to_chat_completions($title, $description, $analysis, $feed_settings);
        }
        
        // Validate content length and regenerate if needed
        if ($response && isset($response['content'])) {
            $word_count = str_word_count(strip_tags($response['content']));
            $min_words = $feed_settings['min_article_words'] ?? 1200;
            
            if ($word_count < $min_words) {
                error_log("RSS Auto Publisher: Content too short ({$word_count} words), regenerating with high verbosity...");
                // Retry with maximum verbosity
                $feed_settings['force_high_verbosity'] = true;
                $response = $this->call_gpt5_responses_api($title, $description, $analysis, $feed_settings);
            }
        }
        
        return $response;
    }
    
    /**
     * Call GPT-5 Responses API (Proper Implementation)
     */
    private function call_gpt5_responses_api($title, $description, $analysis, $settings) {
        $prompt = $this->build_gpt5_prompt($title, $description, $analysis, $settings);
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        // Determine verbosity setting
        $verbosity = $settings['force_high_verbosity'] ?? false ? 'high' : 
                    ($settings['gpt5_verbosity'] ?? 'high');
        
        // Determine reasoning effort
        $reasoning_effort = $settings['gpt5_reasoning'] ?? 'medium';
        
        // GPT-5 Responses API request body (correct format from documentation)
        $body = [
            'model' => $this->model,
            'input' => $prompt,
            'text' => [
                'verbosity' => $verbosity // high for long articles
            ],
            'reasoning' => [
                'effort' => $reasoning_effort // medium or high for quality content
            ]
        ];
        
        // Add response format if needed for structured output
        if (isset($settings['require_json']) && $settings['require_json']) {
            $body['response_format'] = [
                'type' => 'json_object'
            ];
        }
        
        $response = wp_remote_post($this->responses_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 180, // 3 minutes for long content
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher GPT-5 API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('RSS Auto Publisher GPT-5 API HTTP Error: ' . $response_code);
            error_log('Response: ' . $response_body);
            
            // Check for rate limiting
            if ($response_code === 429) {
                $this->handle_rate_limit(['message' => 'Rate limited']);
            }
            
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher GPT-5 Error: ' . json_encode($data['error']));
            return false;
        }
        
        // Parse GPT-5 Responses API response
        return $this->parse_gpt5_response($data);
    }
    
    /**
     * Parse GPT-5 Responses API output (corrected)
     */
    private function parse_gpt5_response($data) {
        // GPT-5 Responses API returns output directly
        if (isset($data['output'])) {
            $output_text = $data['output'];
            
            // If output is already structured
            if (is_array($output_text)) {
                $output_text = json_encode($output_text);
            }
            
            // Try to parse as JSON
            $decoded = json_decode($output_text, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
            
            // If not JSON, parse as raw content
            return $this->parse_raw_content($output_text);
        }
        
        // Handle different response structures
        if (isset($data['choices'][0]['text'])) {
            return $this->parse_raw_content($data['choices'][0]['text']);
        }
        
        error_log('RSS Auto Publisher: Unexpected GPT-5 response structure: ' . json_encode($data));
        return false;
    }
    
    /**
     * Build optimized GPT-5 prompt
     */
    private function build_gpt5_prompt($title, $description, $analysis, $settings) {
        $domain = $analysis['domain'];
        $keywords = implode(', ', array_slice($analysis['seo_keywords'], 0, 5));
        $length_range = explode('-', $settings['content_length'] ?? '1500-2500');
        $min_words = $length_range[0];
        $max_words = $length_range[1] ?? ($min_words + 1000);
        
        // GPT-5 specific prompt optimized for high verbosity output
        $prompt = "You are an expert content writer. Create a comprehensive, detailed article about: '{$title}'

CRITICAL REQUIREMENTS:
- Target length: {$min_words}-{$max_words} words (MUST generate complete content)
- Style: Professional, engaging, informative
- Include ALL sections with substantial content (no placeholders or abbreviations)
- Output format: JSON with 'title' and 'content' fields

TARGET SEO KEYWORDS: {$keywords}
DOMAIN: {$domain}

ARTICLE STRUCTURE (every section must be fully written):

1. **Introduction** (250-300 words)
   Write an engaging introduction that:
   - Hooks the reader immediately
   - Clearly introduces the main topic
   - Explains why this matters to readers
   - Previews the main points to be covered

2. **Background and Context** (400-500 words)
   Provide comprehensive background including:
   - Historical context or development
   - Key concepts and definitions
   - Current state of the topic
   - Important trends or changes

3. **Detailed Analysis** (500-600 words)
   Deep dive into the main topic with:
   - Multiple perspectives and viewpoints
   - Supporting data and statistics
   - Expert insights or research findings
   - Real-world examples and case studies

4. **Practical Applications** (400-500 words)
   Cover real-world applications:
   - How to implement or use this information
   - Step-by-step guidance where applicable
   - Common challenges and solutions
   - Best practices and recommendations

5. **Tips and Strategies** (350-400 words)
   Provide 8-10 specific tips including:
   - Actionable advice readers can implement
   - Professional recommendations
   - Tools and resources
   - Common mistakes to avoid

6. **Frequently Asked Questions** (300-400 words)
   Answer 5-6 common questions with:
   - Comprehensive answers to each
   - Address misconceptions
   - Provide additional insights
   - Clarify complex points

7. **Conclusion** (200-250 words)
   Strong conclusion that:
   - Summarizes key takeaways
   - Reinforces main message
   - Provides future outlook
   - Includes clear call to action

FORMAT YOUR RESPONSE AS:
{
  \"title\": \"[SEO-optimized title, 60-70 characters]\",
  \"content\": \"[Complete HTML article using <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em> tags]\"
}

IMPORTANT: Generate COMPLETE, DETAILED content for EVERY section. This is a full article, not an outline or summary.";

        if (!empty($description)) {
            $prompt .= "\n\nAdditional context: " . substr($description, 0, 500);
        }
        
        if (!empty($settings['enhancement_prompt'])) {
            $prompt .= "\n\nCustom requirements: " . $settings['enhancement_prompt'];
        }
        
        return $prompt;
    }
    
    /**
     * Fallback to Chat Completions API
     */
    private function fallback_to_chat_completions($title, $description, $analysis, $settings) {
        $prompt = $this->build_gpt5_prompt($title, $description, $analysis, $settings);
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert content writer. Generate comprehensive, detailed articles with complete content in every section. Use high verbosity and ensure articles are at least 1500 words.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ];
        
        // Chat Completions API body for GPT-5
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'top_p' => 0.9,
            'presence_penalty' => 0.3,
            'frequency_penalty' => 0.3,
            'response_format' => [
                'type' => 'json_object'
            ]
        ];
        
        // Add max_tokens only if model doesn't support max_completion_tokens
        if (strpos($this->model, 'gpt-5') === false) {
            $body['max_tokens'] = 16000;
        } else {
            $body['max_completion_tokens'] = 16384; // GPT-5 uses this parameter
        }
        
        $response = wp_remote_post($this->chat_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 180,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher Chat API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher Chat API Error: ' . json_encode($data['error']));
            return false;
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            $decoded = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
            
            return $this->parse_raw_content($content);
        }
        
        return false;
    }
    
    /**
     * Enhanced content enhancement using GPT-5
     */
    public function enhance_content($title, $content, $options = []) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $current_words = str_word_count(strip_tags($content));
        $target_words = $options['target_words'] ?? 1500;
        
        $prompt = "Enhance and expand this article to be more comprehensive.
        
Current length: {$current_words} words
Target: {$target_words}+ words minimum

Requirements:
- Expand with substantial new content and sections
- Add specific examples, data, and case studies
- Improve SEO and structure
- Maintain factual accuracy
- Generate complete, detailed content (no placeholders)

Output as JSON: {\"title\": \"enhanced title\", \"content\": \"complete expanded HTML article\"}

Current Title: {$title}
Current Content: {$content}";

        // Use Responses API for enhancement
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'input' => $prompt,
            'text' => [
                'verbosity' => 'high' // High verbosity for expansion
            ],
            'reasoning' => [
                'effort' => 'medium'
            ]
        ];
        
        $response = wp_remote_post($this->responses_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 180,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        return $this->parse_gpt5_response($data);
    }
    
    /**
     * Translate content using GPT-5
     */
    public function translate_content($title, $content, $target_language, $source_language = 'auto') {
        if (!$this->is_configured()) {
            return false;
        }
        
        $target_name = $this->supported_languages[$target_language] ?? $target_language;
        
        $prompt = "Translate this article to {$target_name}.
Maintain ALL content, HTML formatting, and complete detail.
The translation must be natural and culturally appropriate.

Output as JSON: {\"title\": \"translated title\", \"content\": \"complete translated HTML article\"}

Title: {$title}
Content: {$content}";

        // Use Responses API for translation
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'input' => $prompt,
            'text' => [
                'verbosity' => 'high' // Maintain full content in translation
            ],
            'reasoning' => [
                'effort' => 'minimal' // Translation doesn't need complex reasoning
            ]
        ];
        
        $response = wp_remote_post($this->responses_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 180,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        return $this->parse_gpt5_response($data);
    }
    
    /**
     * Parse raw content fallback
     */
    private function parse_raw_content($text) {
        // Try to extract JSON from the text
        if (preg_match('/\{[\s\S]*"title"[\s\S]*"content"[\s\S]*\}/m', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
        }
        
        // Extract title and content from formatted text
        $title_match = '';
        $content_match = $text;
        
        // Look for title patterns
        if (preg_match('/^#\s*(.+)$/m', $text, $matches) || 
            preg_match('/^Title:\s*(.+)$/mi', $text, $matches)) {
            $title_match = trim($matches[1]);
            // Remove title from content
            $content_match = str_replace($matches[0], '', $text);
        }
        
        // Convert markdown to HTML if needed
        $content_html = $this->markdown_to_html(trim($content_match));
        
        return [
            'title' => !empty($title_match) ? $title_match : 'Article',
            'content' => $content_html
        ];
    }
    
    /**
     * Simple markdown to HTML converter
     */
    private function markdown_to_html($text) {
        // Convert headers
        $text = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
        
        // Convert bold and italic
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
        
        // Convert lists
        $text = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $text);
        
        // Wrap consecutive list items in ul tags
        $text = preg_replace('/(<li>.*<\/li>\s*)+/s', '<ul>$0</ul>', $text);
        
        // Convert paragraphs
        $paragraphs = explode("\n\n", $text);
        $formatted = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (!empty($p) && !preg_match('/^<[^>]+>/', $p)) {
                $p = '<p>' . $p . '</p>';
            }
            if (!empty($p)) {
                $formatted[] = $p;
            }
        }
        
        return implode("\n\n", $formatted);
    }
    
    /**
     * Check if rate limited
     */
    public function is_rate_limited() {
        return get_transient('rsp_rate_limited') !== false;
    }
    
    /**
     * Handle rate limit
     */
    private function handle_rate_limit($error) {
        $retry_after = 60; // Default to 60 seconds
        
        if (isset($error['message']) && preg_match('/(\d+)/', $error['message'], $matches)) {
            $retry_after = intval($matches[1]);
        }
        
        set_transient('rsp_rate_limited', true, $retry_after);
        error_log("RSS Auto Publisher: Rate limited - retry after {$retry_after} seconds");
    }
    
    /**
     * Record API usage
     */
    private function record_usage($usage_data) {
        if (!isset($usage_data['usage'])) {
            return;
        }
        
        $usage = $usage_data['usage'];
        $total_tokens = $usage['total_tokens'] ?? 0;
        $input_tokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
        
        // Calculate cost
        $cost = ($input_tokens / 1000000) * $this->pricing['input'];
        $cost += ($output_tokens / 1000000) * $this->pricing['output'];
        
        // Record in database
        RSP_Database::record_api_usage('openai-gpt5', 'responses', $total_tokens, $cost, true, [
            'input_tokens' => $input_tokens,
            'output_tokens' => $output_tokens,
            'model' => $this->model
        ]);
    }
    
    /**
     * Get usage stats
     */
    public function get_usage_stats($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rsp_api_usage';
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->get_row($wpdb->prepare(
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
    }
    
    /**
     * Get model info
     */
    public function get_model_info() {
        return [
            'model' => 'GPT-5',
            'version' => $this->model,
            'context_window' => 200000, // GPT-5 context window
            'max_output' => 65536, // GPT-5 max output
            'features' => [
                'verbosity_control' => true,
                'reasoning_effort' => true,
                'responses_api' => true,
                'custom_tools' => true,
                'minimal_reasoning' => true
            ],
            'pricing' => $this->pricing
        ];
    }
}
