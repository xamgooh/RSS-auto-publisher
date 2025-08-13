<?php
/**
 * OpenAI GPT Integration for RSS Auto Publisher
 * Version 2.1.0 - Fixed for proper article generation
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_OpenAI {
    /**
     * API configuration
     */
    private $api_key;
    private $model = 'gpt-4-turbo-preview'; // Use a valid model name
    private $chat_api_url = 'https://api.openai.com/v1/chat/completions'; // Correct endpoint
    
    /**
     * Model pricing per 1M tokens (adjust based on actual model)
     */
    private $pricing = [
        'input' => 10.00,
        'output' => 30.00
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
     * Enhanced content creation with proper API implementation
     */
    public function create_content_from_title($title, $description, $feed_settings) {
        if (!$this->is_configured()) {
            error_log('RSS Auto Publisher: API key not configured');
            return false;
        }
        
        // Analyze the content
        $analyzer = new RSP_Content_Analyzer();
        $analysis = $analyzer->analyze_content($title, $description, $feed_settings);
        
        // Build optimized prompt
        $prompt = $this->build_optimized_prompt($title, $description, $analysis, $feed_settings);
        
        // Call the API with proper settings
        $response = $this->call_openai_api($prompt, $feed_settings);
        
        if (!$response) {
            error_log('RSS Auto Publisher: Failed to get response from OpenAI');
            return false;
        }
        
        // Validate and potentially regenerate if too short
        $word_count = str_word_count(strip_tags($response['content']));
        $min_words = $feed_settings['min_article_words'] ?? 1200;
        
        if ($word_count < $min_words) {
            error_log("RSS Auto Publisher: Content too short ({$word_count} words), regenerating...");
            $response = $this->regenerate_with_higher_verbosity($title, $description, $feed_settings);
        }
        
        return $response;
    }
    
    /**
     * Call OpenAI API with proper configuration
     */
    private function call_openai_api($prompt, $settings = []) {
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
        ];
        
        // Prepare messages with clear system instruction
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert content writer. Create comprehensive, detailed articles with substantial content in every section. Always generate complete articles without placeholders or ellipsis. Each section must contain real, meaningful content.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ];
        
        // API request body with proper parameters
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 16000, // Much higher limit for complete articles
            'temperature' => 0.7,
            'top_p' => 0.9,
            'presence_penalty' => 0.3,
            'frequency_penalty' => 0.3
        ];
        
        // Add response format if the model supports it
        if (strpos($this->model, 'gpt-4') !== false) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        
        $response = wp_remote_post($this->chat_api_url, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 120, // Increased timeout for longer responses
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('RSS Auto Publisher API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            error_log('RSS Auto Publisher OpenAI Error: ' . json_encode($data['error']));
            
            // Handle rate limiting
            if (isset($data['error']['code']) && $data['error']['code'] === 'rate_limit_exceeded') {
                $this->handle_rate_limit($data['error']);
            }
            
            return false;
        }
        
        // Parse the response correctly
        return $this->parse_api_response($data);
    }
    
    /**
     * Parse API response properly
     */
    private function parse_api_response($data) {
        if (!isset($data['choices'][0]['message']['content'])) {
            error_log('RSS Auto Publisher: Invalid API response structure');
            return false;
        }
        
        $content = $data['choices'][0]['message']['content'];
        
        // Try to parse as JSON first
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['title']) && isset($decoded['content'])) {
            // Successfully parsed JSON
            return $decoded;
        }
        
        // If not JSON, try to extract structured content
        return $this->parse_raw_content($content);
    }
    
    /**
     * Build optimized prompt for complete article generation
     */
    private function build_optimized_prompt($title, $description, $analysis, $settings) {
        $domain = $analysis['domain'];
        $keywords = implode(', ', array_slice($analysis['seo_keywords'], 0, 5));
        $length_range = explode('-', $settings['content_length'] ?? '1500-2500');
        $min_words = $length_range[0];
        $max_words = $length_range[1] ?? ($min_words + 1000);
        
        $prompt = "Create a comprehensive, detailed article about: '{$title}'

CRITICAL REQUIREMENTS:
- Length: {$min_words}-{$max_words} words (MANDATORY - article must be complete)
- Format: Return as JSON with 'title' and 'content' keys
- Content must be in HTML format using proper tags: <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em>
- Every section must contain substantial, complete content
- NO placeholders, NO '...', NO abbreviated content
- Include specific examples, data, and detailed explanations

TARGET KEYWORDS: {$keywords}

ARTICLE STRUCTURE (all sections required with full content):

1. INTRODUCTION (250+ words)
   - Hook the reader with an engaging opening
   - Clearly introduce the topic
   - Explain why this matters
   - Preview what will be covered

2. BACKGROUND/OVERVIEW (400+ words)
   - Provide comprehensive context
   - Explain key concepts in detail
   - Include relevant history or background
   - Define important terms

3. MAIN ANALYSIS (500+ words)
   - Deep dive into the core topic
   - Provide multiple perspectives
   - Include data, statistics, or research
   - Use specific examples

4. PRACTICAL APPLICATIONS (400+ words)
   - Real-world uses and benefits
   - Step-by-step guidance where relevant
   - Case studies or success stories
   - Implementation strategies

5. EXPERT TIPS & BEST PRACTICES (350+ words)
   - 8-10 specific, actionable tips
   - Professional recommendations
   - Common mistakes to avoid
   - Tools and resources

6. FREQUENTLY ASKED QUESTIONS (300+ words)
   - 5-6 relevant questions
   - Comprehensive answers to each
   - Address common concerns
   - Clarify misconceptions

7. CONCLUSION (200+ words)
   - Summarize key takeaways
   - Reinforce main points
   - Future outlook or trends
   - Clear call to action

IMPORTANT: Generate COMPLETE content for EVERY section. This is a full article, not an outline.

JSON FORMAT REQUIRED:
{
  \"title\": \"[SEO-optimized title, 60-70 characters]\",
  \"content\": \"[Complete HTML article with all sections fully written]\"
}";

        if (!empty($description)) {
            $prompt .= "\n\nAdditional context: " . substr($description, 0, 500);
        }
        
        if (!empty($settings['enhancement_prompt'])) {
            $prompt .= "\n\nCustom instructions: " . $settings['enhancement_prompt'];
        }
        
        return $prompt;
    }
    
    /**
     * Regenerate with maximum verbosity for longer content
     */
    private function regenerate_with_higher_verbosity($title, $description, $settings) {
        $prompt = "URGENT: Generate a COMPLETE, DETAILED article of 2000+ words about: '{$title}'

This is a RETRY because the previous attempt was too short. You MUST generate a full-length article.

MANDATORY REQUIREMENTS:
- Minimum 2000 words total
- Each section must be fully written with detailed content
- Include specific examples, statistics, case studies
- Provide comprehensive coverage of the topic

Write a complete article with these detailed sections:

1. INTRODUCTION (300+ words) - Full introduction with context and overview
2. COMPREHENSIVE BACKGROUND (500+ words) - Detailed history and context
3. IN-DEPTH ANALYSIS (600+ words) - Thorough examination with data
4. PRACTICAL APPLICATIONS (500+ words) - Real examples and use cases
5. EXPERT INSIGHTS (400+ words) - Professional tips and strategies
6. FAQ SECTION (400+ words) - 6-8 questions with detailed answers
7. CONCLUSION (300+ words) - Complete summary and future outlook

Include:
- Specific statistics and numbers
- Real examples and case studies
- Detailed explanations
- Multiple perspectives
- Actionable recommendations

Return as JSON: {\"title\": \"...\", \"content\": \"[COMPLETE HTML article]\"}

CRITICAL: This MUST be a COMPLETE article. Write FULL content for every section.";

        return $this->call_openai_api($prompt, array_merge($settings, [
            'min_article_words' => 2000,
            'temperature' => 0.8
        ]));
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
        
        // Try to extract title and content from formatted text
        $title_match = '';
        $content_match = '';
        
        // Look for title patterns
        if (preg_match('/^#\s*(.+)$/m', $text, $matches) || 
            preg_match('/^Title:\s*(.+)$/mi', $text, $matches) ||
            preg_match('/^(.+)$/m', $text, $matches)) {
            $title_match = trim($matches[1]);
        }
        
        // Extract everything after the title as content
        $content_match = $text;
        if (!empty($title_match)) {
            $title_pos = strpos($text, $title_match);
            if ($title_pos !== false) {
                $content_match = substr($text, $title_pos + strlen($title_match));
            }
        }
        
        // Convert markdown to HTML if needed
        $content_match = $this->markdown_to_html($content_match);
        
        return [
            'title' => !empty($title_match) ? $title_match : 'Article',
            'content' => trim($content_match)
        ];
    }
    
    /**
     * Simple markdown to HTML converter
     */
    private function markdown_to_html($text) {
        // Convert headers
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
        
        // Convert bold and italic
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        
        // Convert lists
        $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/(<li>.*<\/li>)\n(<li>)/s', '$1$2', $text);
        $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);
        
        // Convert paragraphs
        $paragraphs = explode("\n\n", $text);
        $formatted = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (!empty($p) && !preg_match('/^<[^>]+>/', $p)) {
                $p = '<p>' . $p . '</p>';
            }
            $formatted[] = $p;
        }
        
        return implode("\n", $formatted);
    }
    
    /**
     * Translate content
     */
    public function translate_content($title, $content, $target_language, $source_language = 'auto') {
        if (!$this->is_configured()) {
            return false;
        }
        
        $target_name = $this->supported_languages[$target_language] ?? $target_language;
        
        $prompt = "Translate the following article to {$target_name}. 
        Maintain ALL content, HTML formatting, and the same level of detail.
        The translation must be natural and culturally appropriate.
        
        Return as JSON format: {\"title\": \"translated title\", \"content\": \"translated HTML content\"}
        
        Title: {$title}
        
        Content: {$content}";

        return $this->call_openai_api($prompt);
    }
    
    /**
     * Enhance existing content
     */
    public function enhance_content($title, $content, $options = []) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $current_words = str_word_count(strip_tags($content));
        $target_words = $options['target_words'] ?? 1500;
        
        $prompt = "Enhance and expand this article to create comprehensive content.
        
Current length: {$current_words} words
Target length: {$target_words}+ words

Requirements:
- Expand with substantial new sections
- Add examples, data, and detailed explanations
- Improve structure and SEO
- Maintain accuracy while adding depth
- Return as JSON: {\"title\": \"enhanced title\", \"content\": \"complete expanded HTML\"}

Current Title: {$title}
Current Content: {$content}";

        return $this->call_openai_api($prompt, $options);
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
        
        if (isset($error['message']) && preg_match('/try again in (\d+)s/', $error['message'], $matches)) {
            $retry_after = intval($matches[1]);
        }
        
        set_transient('rsp_rate_limited', true, $retry_after);
        error_log("RSS Auto Publisher: Rate limited - retry after {$retry_after} seconds");
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
             WHERE api_service LIKE %s 
             AND created_at > %s",
            'openai%',
            $since
        ));
    }
    
    /**
     * Get model info
     */
    public function get_model_info() {
        return [
            'model' => $this->model,
            'version' => 'Latest',
            'context_window' => 128000,
            'max_output' => 16000,
            'features' => [
                'json_mode' => true,
                'function_calling' => true,
                'vision' => false
            ],
            'pricing' => $this->pricing
        ];
    }
}
