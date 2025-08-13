<?php
/**
 * OpenAI GPT-5 Integration for RSS Auto Publisher
 * Version 2.0.0 - Optimized for GPT-5 with proper API implementation
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
    private $api_url = 'https://api.openai.com/v1/responses'; // GPT-5 uses Responses API
    private $chat_api_url = 'https://api.openai.com/v1/chat/completions'; // Fallback
    
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
     * Enhanced content creation using GPT-5 Responses API
     */
    public function create_content_from_title($title, $description, $feed_settings) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Analyze the content
        $analyzer = new RSP_Content_Analyzer();
        $analysis = $analyzer->analyze_content($title, $description, $feed_settings);
        
        // Build optimized GPT-5 prompt
        $prompt = $this->build_gpt5_optimized_prompt($title, $description, $analysis, $feed_settings);
        
        // Use GPT-5 Responses API with proper parameters
        $response = $this->call_gpt5_responses_api($prompt);
        
        if (!$response) {
            // Fallback to Chat Completions API
            return $this->fallback_chat_completions($title, $description, $analysis, $feed_settings);
        }
        
        return $response;
    }
    
    /**
     * Call GPT-5 Responses API (Recommended by OpenAI)
     */
    private function call_gpt5_responses_api($prompt) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        // GPT-5 Responses API format
        $body = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => 'You are an expert content writer specializing in creating comprehensive, SEO-optimized articles. Always create COMPLETE articles with full content in each section.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'text' => [
                'verbosity' => 'high', // Critical for long articles
                'format' => [
                    'type' => 'json_object'
                ]
            ],
            'reasoning' => [
                'effort' => 'high' // High reasoning for complex article generation
            ]
        ];
        
        $response = wp_remote_post($this->api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 300,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher GPT-5 API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher GPT-5 Error: ' . json_encode($data['error']));
            return false;
        }
        
        // Extract content from GPT-5 Responses API format
        return $this->parse_gpt5_response($data);
    }
    
    /**
     * Parse GPT-5 Responses API output
     */
    private function parse_gpt5_response($data) {
        if (!isset($data['output']) || !is_array($data['output'])) {
            return false;
        }
        
        $content_text = '';
        
        // GPT-5 Responses API returns output as array of items
        foreach ($data['output'] as $item) {
            if (isset($item['content'])) {
                foreach ($item['content'] as $content_item) {
                    if (isset($content_item['text'])) {
                        $content_text .= $content_item['text'];
                    }
                }
            }
        }
        
        if (empty($content_text)) {
            return false;
        }
        
        // Try to parse as JSON
        $decoded = json_decode($content_text, true);
        if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
            return $decoded;
        }
        
        // Fallback parsing
        return $this->parse_raw_content($content_text);
    }
    
    /**
     * Build GPT-5 optimized prompt
     */
    private function build_gpt5_optimized_prompt($title, $description, $analysis, $settings) {
        $domain = $analysis['domain'];
        $keywords = implode(', ', array_slice($analysis['seo_keywords'], 0, 5));
        $length_range = explode('-', $settings['content_length'] ?? '1200-2000');
        $min_words = $length_range[0];
        $max_words = $length_range[1] ?? ($min_words + 500);
        
        // GPT-5 responds better to structured, clear instructions
        $prompt = "Create a comprehensive article based on: '{$title}'

REQUIREMENTS:
- Length: {$min_words}-{$max_words} words (STRICT REQUIREMENT)
- Style: Professional, engaging, informative
- SEO Keywords: {$keywords}
- Domain: {$domain}

STRUCTURE (All sections MUST be complete with substantial content):

1. INTRODUCTION (200+ words)
   - Engaging hook
   - Clear topic introduction  
   - Preview of main points
   - Why this matters to readers

2. MAIN SECTION 1: Background/Overview (300+ words)
   - Comprehensive background information
   - Key concepts explained
   - Historical context if relevant
   - Current state of the topic

3. MAIN SECTION 2: Deep Analysis (400+ words)
   - Detailed exploration of main aspects
   - Data, statistics, or examples
   - Expert insights or research findings
   - Multiple perspectives considered

4. MAIN SECTION 3: Practical Applications (300+ words)
   - Real-world applications
   - Case studies or examples
   - Implementation strategies
   - Common challenges and solutions

5. TIPS & RECOMMENDATIONS (250+ words)
   - 7-10 actionable tips
   - Specific, implementable advice
   - Best practices
   - Tools or resources

6. FAQ SECTION (200+ words)
   - 4-5 relevant questions
   - Comprehensive answers
   - Address common misconceptions
   - Provide clarity on complex points

7. CONCLUSION (150+ words)
   - Summarize key points
   - Reinforce main message
   - Future outlook
   - Call to action

FORMAT:
Return as JSON with this exact structure:
{
  \"title\": \"SEO-optimized title (60-70 characters)\",
  \"content\": \"Complete HTML article using <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em> tags\"
}

CRITICAL: 
- Generate COMPLETE content for EVERY section
- NO placeholders, NO ellipsis (...)
- Each section must have REAL, SUBSTANTIAL content
- Use specific examples and details
- Maintain high verbosity throughout";

        if (!empty($description)) {
            $prompt .= "\n\nContext: " . $description;
        }
        
        if (!empty($settings['enhancement_prompt'])) {
            $prompt .= "\n\nAdditional instructions: " . $settings['enhancement_prompt'];
        }
        
        return $prompt;
    }
    
    /**
     * Fallback to Chat Completions API
     */
    private function fallback_chat_completions($title, $description, $analysis, $settings) {
        $prompt = $this->build_gpt5_optimized_prompt($title, $description, $analysis, $settings);
        
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert content writer. Create comprehensive, complete articles with substantial content in every section. Use high verbosity.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ];
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => 8000, // Much higher for complete articles
            'temperature' => 0.7,
            'top_p' => 0.9,
            'presence_penalty' => 0.4,
            'frequency_penalty' => 0.4,
            'response_format' => [
                'type' => 'json_object'
            ]
        ];
        
        $response = wp_remote_post($this->chat_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 300,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher Fallback Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher API Error: ' . json_encode($data['error']));
            return false;
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            $decoded = json_decode($content, true);
            
            if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
                // Validate content length
                $word_count = str_word_count(strip_tags($decoded['content']));
                if ($word_count < 800) {
                    error_log('RSS Auto Publisher: Content too short (' . $word_count . ' words), regenerating...');
                    return $this->regenerate_with_higher_verbosity($title, $description, $settings);
                }
                return $decoded;
            }
        }
        
        return false;
    }
    
    /**
     * Regenerate with maximum verbosity
     */
    private function regenerate_with_higher_verbosity($title, $description, $settings) {
        $prompt = "IMPORTANT: Generate a COMPLETE, DETAILED article of at least 1500 words about: '{$title}'

This is a SECOND ATTEMPT - the first was too short. You MUST generate substantial content.

MINIMUM REQUIREMENTS:
- Total article length: 1500-2500 words
- Each main section: 300+ words minimum
- Include specific examples, data, case studies
- Provide comprehensive coverage

Create an in-depth article with these sections:
1. Comprehensive Introduction (250+ words)
2. Detailed Background & Context (400+ words)
3. In-Depth Analysis (500+ words)
4. Practical Applications & Examples (400+ words)
5. Expert Tips & Best Practices (300+ words)
6. Frequently Asked Questions (250+ words)
7. Thoughtful Conclusion (200+ words)

Include:
- Specific statistics and data points
- Real-world examples and case studies
- Expert insights and quotes
- Actionable recommendations
- Comprehensive explanations

Format as JSON: {\"title\": \"...\", \"content\": \"Complete HTML article\"}

CRITICAL: This must be a COMPLETE article with FULL content in every section. No shortcuts.";

        $messages = [
            [
                'role' => 'system',
                'content' => 'Generate comprehensive, detailed articles. Use maximum verbosity. Every section must be fully developed with substantial content.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ];
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => 10000, // Maximum tokens
            'temperature' => 0.8,
            'response_format' => [
                'type' => 'json_object'
            ]
        ];
        
        $response = wp_remote_post($this->chat_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 400,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            return json_decode($content, true);
        }
        
        return false;
    }
    
    /**
     * Enhanced content enhancement
     */
    public function enhance_content($title, $content, $options = []) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $defaults = [
            'style' => 'professional',
            'length' => 'longer',
            'seo_focus' => true,
            'keywords' => [],
            'custom_prompt' => ''
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        $prompt = "Enhance and expand this content to create a comprehensive article.
        
Current content length: " . str_word_count(strip_tags($content)) . " words
Target: Expand to 1500+ words minimum

Requirements:
- Add substantial new sections and details
- Include examples, data, and case studies
- Improve structure and flow
- Enhance SEO optimization
- Maintain factual accuracy

Title: {$title}
Content: {$content}

Return as JSON: {\"title\": \"Enhanced title\", \"content\": \"Complete expanded HTML article\"}";

        // Try Responses API first
        $response = $this->call_gpt5_responses_api($prompt);
        
        if (!$response) {
            // Fallback to Chat Completions
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert content enhancer. Expand content significantly while maintaining quality.'],
                ['role' => 'user', 'content' => $prompt]
            ];
            
            $response = $this->call_chat_api($messages, 8000);
        }
        
        return $response;
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
        Maintain ALL content, HTML formatting, and the same level of detail.
        The translation must be natural and culturally appropriate.
        
        Title: {$title}
        Content: {$content}
        
        Return as JSON: {\"title\": \"Translated title\", \"content\": \"Complete translated HTML article\"}";

        // Use Responses API with high verbosity for complete translation
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'input' => [
                ['role' => 'developer', 'content' => "You are a professional translator. Translate to {$target_name} while preserving all content and formatting."],
                ['role' => 'user', 'content' => $prompt]
            ],
            'text' => [
                'verbosity' => 'high',
                'format' => ['type' => 'json_object']
            ],
            'reasoning' => [
                'effort' => 'medium'
            ]
        ];
        
        $response = wp_remote_post($this->api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 300,
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
     * Helper: Call Chat API
     */
    private function call_chat_api($messages, $max_tokens = 5000) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => $max_tokens,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object']
        ];
        
        $response = wp_remote_post($this->chat_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 300,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return json_decode($data['choices'][0]['message']['content'], true);
        }
        
        return false;
    }
    
    /**
     * Parse raw content fallback
     */
    private function parse_raw_content($text) {
        // Try to extract JSON from the text
        if (preg_match('/\{.*"title".*"content".*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['title']) && isset($decoded['content'])) {
                return $decoded;
            }
        }
        
        // Fallback: create structure from raw text
        $lines = explode("\n", $text, 2);
        return [
            'title' => trim($lines[0]),
            'content' => isset($lines[1]) ? trim($lines[1]) : ''
        ];
    }
    
    /**
     * Get domain-specific instructions
     */
    private function get_domain_instructions($domain, $gambling_category = null) {
        $instructions = [
            'gambling' => 'Include responsible gambling disclaimers, strategy analysis, odds calculations, and bankroll management tips.',
            'sports' => 'Include current statistics, player/team analysis, performance metrics, and strategic insights.',
            'technology' => 'Explain technical concepts clearly, include code examples if relevant, discuss implementation details.',
            'business' => 'Provide market analysis, ROI calculations, strategic frameworks, and actionable business insights.',
            'health' => 'Include evidence-based information, cite studies where relevant, add medical disclaimers.',
            'lifestyle' => 'Provide practical tips, personal anecdotes, and actionable lifestyle improvements.',
            'news' => 'Provide balanced reporting, multiple perspectives, fact-based analysis, and context.',
            'general' => 'Focus on comprehensive coverage, practical applications, and reader value.'
        ];
        
        return $instructions[$domain] ?? $instructions['general'];
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
        $retry_after = isset($error['retry_after']) ? intval($error['retry_after']) : 60;
        set_transient('rsp_rate_limited', true, $retry_after);
        error_log("RSS Auto Publisher: Rate limited - retry after {$retry_after} seconds");
    }
    
    /**
     * Record usage
     */
    private function record_usage($usage_data) {
        $input_tokens = $usage_data['input_tokens'] ?? $usage_data['prompt_tokens'] ?? 0;
        $output_tokens = $usage_data['output_tokens'] ?? $usage_data['completion_tokens'] ?? 0;
        $total_tokens = $usage_data['total_tokens'] ?? ($input_tokens + $output_tokens);
        
        $cost = ($input_tokens / 1000000) * $this->pricing['input'];
        $cost += ($output_tokens / 1000000) * $this->pricing['output'];
        
        RSP_Database::record_api_usage('openai-gpt5', 'responses', $total_tokens, $cost, true);
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
            'version' => 'gpt-5-2025-08',
            'context_window' => 400000,
            'max_output' => 128000,
            'features' => [
                'verbosity_control' => true,
                'reasoning_effort' => true,
                'responses_api' => true,
                'custom_tools' => true,
                'cfg_support' => true
            ],
            'pricing' => $this->pricing
        ];
    }
}
