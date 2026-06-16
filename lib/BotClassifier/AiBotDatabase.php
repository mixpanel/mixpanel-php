<?php
/**
 * Provides the AI bot user-agent pattern database.
 * Each entry contains a regex pattern (for preg_match()), bot name, provider, category, and description.
 */
class BotClassifier_AiBotDatabase {
    /** @var array The built-in AI bot pattern database */
    private static $_database = array(
        // === OpenAI ===
        array("pattern" => "/GPTBot\//i",        "name" => "GPTBot",
              "provider" => "OpenAI",             "category" => "indexing",
              "description" => "OpenAI web crawler for model training data"),
        array("pattern" => "/ChatGPT-User\//i",  "name" => "ChatGPT-User",
              "provider" => "OpenAI",             "category" => "retrieval",
              "description" => "ChatGPT real-time retrieval for user queries (RAG)"),
        array("pattern" => "/OAI-SearchBot\//i",  "name" => "OAI-SearchBot",
              "provider" => "OpenAI",             "category" => "indexing",
              "description" => "OpenAI search indexing crawler"),
        // === Anthropic ===
        array("pattern" => "/ClaudeBot\//i",      "name" => "ClaudeBot",
              "provider" => "Anthropic",           "category" => "indexing",
              "description" => "Anthropic web crawler for model training"),
        array("pattern" => "/Claude-User\//i",    "name" => "Claude-User",
              "provider" => "Anthropic",           "category" => "retrieval",
              "description" => "Claude real-time retrieval for user queries"),
        // === Google ===
        array("pattern" => "/Google-Extended\//i", "name" => "Google-Extended",
              "provider" => "Google",              "category" => "indexing",
              "description" => "Google AI training data crawler (separate from Googlebot)"),
        // === Perplexity ===
        array("pattern" => "/PerplexityBot\//i",  "name" => "PerplexityBot",
              "provider" => "Perplexity",          "category" => "retrieval",
              "description" => "Perplexity AI search crawler"),
        // === ByteDance ===
        array("pattern" => "/Bytespider\//i",     "name" => "Bytespider",
              "provider" => "ByteDance",           "category" => "indexing",
              "description" => "ByteDance/TikTok AI crawler"),
        // === Common Crawl ===
        array("pattern" => "/CCBot\//i",          "name" => "CCBot",
              "provider" => "Common Crawl",        "category" => "indexing",
              "description" => "Common Crawl bot (data used by many AI models)"),
        // === Apple ===
        array("pattern" => "/Applebot-Extended\//i", "name" => "Applebot-Extended",
              "provider" => "Apple",               "category" => "indexing",
              "description" => "Apple AI/Siri training data crawler"),
        // === Meta ===
        array("pattern" => "/Meta-ExternalAgent\//i", "name" => "Meta-ExternalAgent",
              "provider" => "Meta",                "category" => "indexing",
              "description" => "Meta/Facebook AI training data crawler"),
        // === Cohere ===
        array("pattern" => "/cohere-ai\//i",      "name" => "cohere-ai",
              "provider" => "Cohere",              "category" => "indexing",
              "description" => "Cohere AI training data crawler"),
    );

    /** @return array */
    public static function getDatabase() {
        return self::$_database;
    }

    /**
     * Returns database entries without regex patterns (safe for inspection).
     * @return array
     */
    public static function getDatabaseForInspection() {
        $result = array();
        foreach (self::$_database as $entry) {
            $result[] = array(
                "name"        => $entry["name"],
                "provider"    => $entry["provider"],
                "category"    => $entry["category"],
                "description" => $entry["description"]
            );
        }
        return $result;
    }
}
