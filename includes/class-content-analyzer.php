<?php
/**
 * Universal Content Analyzer for RSS Auto Publisher
 */
if (!defined('ABSPATH')) {
    exit;
}

class RSP_Content_Analyzer {
    
    private $domain_keywords = [
        'gambling' => ['betting', 'casino', 'poker', 'odds', 'wager', 'jackpot', 'sportsbook', 'roulette', 'blackjack', 'slot', 'gamble', 'bet', 'stakes', 'bookmaker'],
        'sports' => ['football', 'basketball', 'fpl', 'fantasy', 'team', 'player', 'match', 'game', 'soccer', 'tennis', 'baseball', 'hockey', 'season', 'league'],
        'technology' => ['ai', 'software', 'tech', 'app', 'digital', 'code', 'data', 'algorithm', 'programming', 'computer', 'internet', 'blockchain', 'crypto'],
        'business' => ['finance', 'marketing', 'startup', 'economy', 'profit', 'investment', 'money', 'revenue', 'sales', 'market', 'stock', 'trading'],
        'health' => ['fitness', 'wellness', 'nutrition', 'medical', 'health', 'diet', 'exercise', 'doctor', 'treatment', 'vitamin', 'supplement'],
        'lifestyle' => ['travel', 'food', 'fashion', 'home', 'recipe', 'style', 'beauty', 'luxury', 'entertainment', 'culture'],
        'news' => ['politics', 'world', 'breaking', 'current', 'government', 'election', 'news', 'report', 'update', 'announcement']
    ];
    
    private $gambling_categories = [
        'sports_betting' => ['betting', 'odds', 'sportsbook', 'wager', 'pick', 'prediction', 'spread', 'line'],
        'casino' => ['casino', 'slots', 'blackjack', 'poker', 'roulette', 'jackpot', 'table', 'game'],
        'poker' => ['poker', 'tournament', 'cash game', 'strategy', 'bluff', 'wsop', 'hold em'],
        'horse_racing' => ['racing', 'horse', 'jockey', 'track', 'handicap', 'derby'],
        'esports_betting' => ['esports', 'gaming', 'tournament', 'team', 'league of legends', 'csgo', 'dota']
    ];
    
    public function analyze_content($title, $description, $feed_settings) {
        // If user specified domain, use it; otherwise auto-detect
        $domain = ($feed_settings['content_domain'] === 'auto') 
            ? $this->auto_detect_domain($title, $description)
            : $feed_settings['content_domain'];
            
        $gambling_subcategory = null;
        if ($domain === 'gambling') {
            $gambling_subcategory = $this->detect_gambling_category($title, $description);
        }
            
        return [
            'domain' => $domain,
            'gambling_category' => $gambling_subcategory,
            'entities' => $this->extract_entities($title, $description),
            'keywords' => $this->extract_keywords($title, $description),
            'suggested_angle' => $this->suggest_angle($title, $domain, $feed_settings),
            'seo_keywords' => $this->generate_seo_keywords($title, $domain, $feed_settings['target_keywords'])
        ];
    }
    
    private function auto_detect_domain($title, $description) {
        $text = strtolower($title . ' ' . $description);
        $scores = [];
        
        foreach ($this->domain_keywords as $domain => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score++;
                }
            }
            $scores[$domain] = $score;
        }
        
        return $scores && max($scores) > 0 ? array_keys($scores, max($scores))[0] : 'general';
    }
    
    private function detect_gambling_category($title, $description) {
        $text = strtolower($title . ' ' . $description);
        $scores = [];
        
        foreach ($this->gambling_categories as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score++;
                }
            }
            $scores[$category] = $score;
        }
        
        return $scores && max($scores) > 0 ? array_keys($scores, max($scores))[0] : 'general_gambling';
    }
    
    private function extract_entities($title, $description) {
        $text = $title . ' ' . $description;
        $entities = [];
        
        // Extract capitalized words (likely proper nouns)
        preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $text, $matches);
        $entities['names'] = array_unique($matches[0]);
        
        // Extract numbers/prices
        preg_match_all('/\$?\d+(?:\.\d+)?[kmb%]?/', $text, $matches);
        $entities['numbers'] = array_unique($matches[0]);
        
        // Extract dates
        preg_match_all('/\b\d{1,2}\/\d{1,2}\/\d{4}\b|\b\w+\s+\d{1,2},?\s+\d{4}\b/', $text, $matches);
        $entities['dates'] = array_unique($matches[0]);
        
        return $entities;
    }
    
    private function extract_keywords($title, $description) {
        $text = strtolower($title . ' ' . $description);
        $words = preg_split('/\W+/', $text);
        
        // Remove common words
        $stopwords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'this', 'that', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'will', 'would', 'could', 'should'];
        $keywords = array_diff($words, $stopwords);
        
        // Return words longer than 3 characters
        return array_filter($keywords, function($word) {
            return strlen($word) > 3;
        });
    }
    
    private function suggest_angle($title, $domain, $settings) {
        if ($settings['content_angle'] !== 'auto') {
            return $settings['content_angle'];
        }
        
        $title_lower = strtolower($title);
        
        // Angle detection based on title patterns
        if (strpos($title_lower, 'how to') !== false || strpos($title_lower, 'guide') !== false) {
            return 'beginner_guide';
        }
        if (strpos($title_lower, 'best') !== false || strpos($title_lower, 'top') !== false) {
            return 'comparison';
        }
        if (strpos($title_lower, 'preview') !== false || strpos($title_lower, 'prediction') !== false) {
            return 'prediction';
        }
        if (strpos($title_lower, 'review') !== false || strpos($title_lower, 'vs') !== false) {
            return 'comparison';
        }
        if (strpos($title_lower, 'tips') !== false || strpos($title_lower, 'advice') !== false) {
            return 'practical_tips';
        }
        
        // Default by domain
        $domain_defaults = [
            'gambling' => 'practical_tips',
            'sports' => 'expert_analysis',
            'technology' => 'beginner_guide',
            'business' => 'practical_tips',
            'health' => 'beginner_guide',
            'lifestyle' => 'practical_tips',
            'news' => 'expert_analysis'
        ];
        
        return $domain_defaults[$domain] ?? 'practical_tips';
    }
    
    private function generate_seo_keywords($title, $domain, $target_keywords) {
        $seo_keywords = [];
        
        // Use provided target keywords
        if (!empty($target_keywords)) {
            $seo_keywords = array_map('trim', explode(',', $target_keywords));
        }
        
        // Extract keywords from title
        $title_words = $this->extract_keywords($title, '');
        $seo_keywords = array_merge($seo_keywords, array_slice($title_words, 0, 3));
        
        // Add domain-specific high-value keywords
        $domain_seo_keywords = [
            'gambling' => ['betting strategy', 'best odds', 'online casino', 'gambling tips', 'betting guide'],
            'sports' => ['fantasy football', 'sports analysis', 'player stats', 'team preview', 'betting picks'],
            'technology' => ['tech review', 'software guide', 'tech news', 'how to', 'best apps'],
            'business' => ['business strategy', 'marketing tips', 'finance guide', 'investment advice'],
            'health' => ['health tips', 'fitness guide', 'nutrition advice', 'wellness'],
            'lifestyle' => ['lifestyle tips', 'travel guide', 'food review', 'style advice'],
            'news' => ['breaking news', 'current events', 'news analysis', 'latest update']
        ];
        
        if (isset($domain_seo_keywords[$domain])) {
            $seo_keywords = array_merge($seo_keywords, array_slice($domain_seo_keywords[$domain], 0, 2));
        }
        
        return array_unique($seo_keywords);
    }
    
    public function get_content_suggestions($analysis) {
        $domain = $analysis['domain'];
        $angle = $analysis['suggested_angle'];
        
        $suggestions = [
            'recommended_sections' => $this->get_recommended_sections($domain, $angle),
            'seo_tips' => $this->get_seo_tips($domain),
            'content_ideas' => $this->get_content_ideas($domain, $analysis['entities'])
        ];
        
        return $suggestions;
    }
    
    private function get_recommended_sections($domain, $angle) {
        $sections = [
            'gambling' => [
                'beginner_guide' => ['Introduction to Topic', 'Basic Strategies', 'Getting Started', 'Common Mistakes to Avoid', 'Responsible Gambling', 'FAQ'],
                'expert_analysis' => ['Advanced Strategies', 'Market Analysis', 'Statistical Breakdown', 'Expert Tips', 'Risk Assessment', 'Conclusion'],
                'practical_tips' => ['Quick Tips', 'Step-by-Step Guide', 'Best Practices', 'Tools & Resources', 'Action Plan', 'FAQ'],
                'comparison' => ['Overview', 'Feature Comparison', 'Pros & Cons', 'Recommendations', 'Final Verdict']
            ],
            'sports' => [
                'expert_analysis' => ['Team/Player Analysis', 'Statistical Breakdown', 'Performance Trends', 'Strategic Insights', 'Predictions', 'Conclusion'],
                'prediction' => ['Current Form Analysis', 'Key Factors', 'Prediction Model', 'Confidence Level', 'Alternative Scenarios'],
                'practical_tips' => ['Key Strategies', 'Implementation Guide', 'Tools & Resources', 'Common Pitfalls', 'Action Items']
            ]
        ];
        
        return $sections[$domain][$angle] ?? ['Introduction', 'Main Analysis', 'Key Points', 'Practical Applications', 'Conclusion', 'FAQ'];
    }
    
    private function get_seo_tips($domain) {
        $tips = [
            'gambling' => [
                'Include responsible gambling disclaimers',
                'Mention legal considerations and jurisdictions',
                'Use specific odds and numbers',
                'Include current promotional offers',
                'Add location-based keywords where relevant'
            ],
            'sports' => [
                'Include current season statistics',
                'Mention specific player names and teams',
                'Use current matchup information',
                'Include fantasy-relevant details',
                'Add injury and lineup updates'
            ],
            'general' => [
                'Use target keywords naturally throughout',
                'Include related semantic keywords',
                'Add specific numbers and dates',
                'Create engaging subheadings',
                'Include FAQ section for featured snippets'
            ]
        ];
        
        return array_merge($tips['general'], $tips[$domain] ?? []);
    }
    
    private function get_content_ideas($domain, $entities) {
        $ideas = [];
        
        if (!empty($entities['names'])) {
            $ideas[] = "Feature analysis of " . implode(', ', array_slice($entities['names'], 0, 3));
        }
        
        if (!empty($entities['numbers'])) {
            $ideas[] = "Include statistical data: " . implode(', ', array_slice($entities['numbers'], 0, 3));
        }
        
        $domain_ideas = [
            'gambling' => [
                'Add bankroll management section',
                'Include current odds comparison',
                'Mention mobile app features',
                'Add bonus and promotion details'
            ],
            'sports' => [
                'Include injury impact analysis',
                'Add lineup predictions',
                'Mention fantasy implications',
                'Include historical performance data'
            ]
        ];
        
        if (isset($domain_ideas[$domain])) {
            $ideas = array_merge($ideas, $domain_ideas[$domain]);
        }
        
        return $ideas;
    }
}
