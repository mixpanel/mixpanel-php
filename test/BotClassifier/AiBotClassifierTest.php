<?php
require_once(dirname(__FILE__) . "/../../lib/Mixpanel.php");

use PHPUnit\Framework\TestCase;

class AiBotClassifierTest extends TestCase {

    /** @var BotClassifier_AiBotClassifier */
    protected $_classifier = null;

    protected function setUp(): void {
        parent::setUp();
        $this->_classifier = new BotClassifier_AiBotClassifier();
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->_classifier = null;
    }

    // === POSITIVE MATCHES — OpenAI ===

    public function testClassifiesGPTBot() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)"
        );
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("GPTBot", $result['$ai_bot_name']);
        $this->assertEquals("OpenAI", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesChatGPTUser() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)"
        );
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("ChatGPT-User", $result['$ai_bot_name']);
        $this->assertEquals("OpenAI", $result['$ai_bot_provider']);
        $this->assertEquals("retrieval", $result['$ai_bot_category']);
    }

    public function testClassifiesOAISearchBot() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)"
        );
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("OAI-SearchBot", $result['$ai_bot_name']);
        $this->assertEquals("OpenAI", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    // === POSITIVE MATCHES — Anthropic ===

    public function testClassifiesClaudeBot() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)"
        );
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("ClaudeBot", $result['$ai_bot_name']);
        $this->assertEquals("Anthropic", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesClaudeUser() {
        $result = $this->_classifier->classify("Mozilla/5.0 (compatible; Claude-User/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("Claude-User", $result['$ai_bot_name']);
        $this->assertEquals("Anthropic", $result['$ai_bot_provider']);
        $this->assertEquals("retrieval", $result['$ai_bot_category']);
    }

    // === POSITIVE MATCHES — Google, Perplexity, ByteDance, Common Crawl, Apple, Meta, Cohere ===

    public function testClassifiesGoogleExtended() {
        $result = $this->_classifier->classify("Mozilla/5.0 (compatible; Google-Extended/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("Google-Extended", $result['$ai_bot_name']);
        $this->assertEquals("Google", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesPerplexityBot() {
        $result = $this->_classifier->classify("Mozilla/5.0 (compatible; PerplexityBot/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("PerplexityBot", $result['$ai_bot_name']);
        $this->assertEquals("Perplexity", $result['$ai_bot_provider']);
        $this->assertEquals("retrieval", $result['$ai_bot_category']);
    }

    public function testClassifiesBytespider() {
        $result = $this->_classifier->classify("Mozilla/5.0 (compatible; Bytespider/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("Bytespider", $result['$ai_bot_name']);
        $this->assertEquals("ByteDance", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesCCBot() {
        $result = $this->_classifier->classify("CCBot/2.0 (https://commoncrawl.org/faq/)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("CCBot", $result['$ai_bot_name']);
        $this->assertEquals("Common Crawl", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesApplebotExtended() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 (Macintosh; Intel Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Applebot-Extended/0.1"
        );
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("Applebot-Extended", $result['$ai_bot_name']);
        $this->assertEquals("Apple", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesMetaExternalAgent() {
        $result = $this->_classifier->classify("Mozilla/5.0 (compatible; Meta-ExternalAgent/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("Meta-ExternalAgent", $result['$ai_bot_name']);
        $this->assertEquals("Meta", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    public function testClassifiesCohereAi() {
        $result = $this->_classifier->classify("Mozilla/5.0 (compatible; cohere-ai/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("cohere-ai", $result['$ai_bot_name']);
        $this->assertEquals("Cohere", $result['$ai_bot_provider']);
        $this->assertEquals("indexing", $result['$ai_bot_category']);
    }

    // === NEGATIVE CASES ===

    public function testNotAiBotChrome() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
        );
        $this->assertFalse($result['$is_ai_bot']);
        $this->assertArrayNotHasKey('$ai_bot_name', $result);
    }

    public function testNotAiBotGooglebot() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"
        );
        $this->assertFalse($result['$is_ai_bot']);
    }

    public function testNotAiBotBingbot() {
        $result = $this->_classifier->classify(
            "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)"
        );
        $this->assertFalse($result['$is_ai_bot']);
    }

    public function testNotAiBotCurl() {
        $result = $this->_classifier->classify("curl/7.64.1");
        $this->assertFalse($result['$is_ai_bot']);
    }

    public function testEmptyUserAgent() {
        $result = $this->_classifier->classify("");
        $this->assertFalse($result['$is_ai_bot']);
    }

    public function testNullUserAgent() {
        $result = $this->_classifier->classify(null);
        $this->assertFalse($result['$is_ai_bot']);
    }

    // === CASE SENSITIVITY ===

    public function testCaseInsensitiveMatching() {
        $result = $this->_classifier->classify("mozilla/5.0 (compatible; gptbot/1.2)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("GPTBot", $result['$ai_bot_name']);
    }

    public function testCaseInsensitiveClaudeBot() {
        $result = $this->_classifier->classify("claudebot/1.0");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("ClaudeBot", $result['$ai_bot_name']);
    }

    // === RETURN SHAPE VALIDATION ===

    public function testMatchReturnsAllExpectedKeys() {
        $result = $this->_classifier->classify("GPTBot/1.2");
        $this->assertArrayHasKey('$is_ai_bot', $result);
        $this->assertArrayHasKey('$ai_bot_name', $result);
        $this->assertArrayHasKey('$ai_bot_provider', $result);
        $this->assertArrayHasKey('$ai_bot_category', $result);
        $this->assertTrue(is_string($result['$ai_bot_name']));
        $this->assertTrue(is_string($result['$ai_bot_provider']));
        $this->assertTrue(
            in_array($result['$ai_bot_category'], array("indexing", "retrieval", "agent"))
        );
    }

    public function testNonMatchReturnsOnlyIsAiBot() {
        $result = $this->_classifier->classify("Mozilla/5.0 Chrome/120");
        $this->assertEquals(array('$is_ai_bot'), array_keys($result));
        $this->assertFalse($result['$is_ai_bot']);
    }

    // === CUSTOM BOT REGISTRATION ===

    public function testCustomBotPatternIsRecognized() {
        $classifier = BotClassifier_AiBotClassifier::createClassifier(array(
            "additional_bots" => array(
                array(
                    "pattern"  => "/MyCustomBot\//i",
                    "name"     => "MyCustomBot",
                    "provider" => "CustomCorp",
                    "category" => "indexing"
                )
            )
        ));
        $result = $classifier->classify("Mozilla/5.0 (compatible; MyCustomBot/1.0)");
        $this->assertTrue($result['$is_ai_bot']);
        $this->assertEquals("MyCustomBot", $result['$ai_bot_name']);
    }

    public function testCustomBotTakesPriorityOverBuiltIn() {
        $classifier = BotClassifier_AiBotClassifier::createClassifier(array(
            "additional_bots" => array(
                array(
                    "pattern"  => "/GPTBot\//i",
                    "name"     => "GPTBot-Custom",
                    "provider" => "CustomProvider",
                    "category" => "retrieval"
                )
            )
        ));
        $result = $classifier->classify("GPTBot/1.2");
        $this->assertEquals("GPTBot-Custom", $result['$ai_bot_name']);
        $this->assertEquals("CustomProvider", $result['$ai_bot_provider']);
    }

    // === BOT DATABASE INSPECTION ===

    public function testGetBotDatabaseReturnsArray() {
        $db = $this->_classifier->getBotDatabase();
        $this->assertTrue(is_array($db));
        $this->assertGreaterThan(0, count($db));
    }

    public function testGetBotDatabaseEntriesHaveRequiredFields() {
        $db = $this->_classifier->getBotDatabase();
        foreach ($db as $entry) {
            $this->assertArrayHasKey("name", $entry);
            $this->assertArrayHasKey("provider", $entry);
            $this->assertArrayHasKey("category", $entry);
            $this->assertTrue(
                in_array($entry["category"], array("indexing", "retrieval", "agent"))
            );
        }
    }

    public function testGetBotDatabaseHasAtLeast12Entries() {
        $db = $this->_classifier->getBotDatabase();
        $this->assertGreaterThanOrEqual(12, count($db));
    }

    // === PATTERN VERIFICATION (via getDatabase() directly) ===

    public function testRawDatabaseEntriesHavePatternKey() {
        $db = BotClassifier_AiBotDatabase::getDatabase();
        $this->assertGreaterThan(0, count($db));
        foreach ($db as $index => $entry) {
            $this->assertArrayHasKey("pattern", $entry,
                "Bot database entry at index $index is missing 'pattern' key"
            );
            $this->assertTrue(is_string($entry["pattern"]),
                "Bot database entry at index $index 'pattern' must be a string"
            );
            // Verify the pattern is a valid regex
            $this->assertNotFalse(@preg_match($entry["pattern"], ""),
                "Bot database entry at index $index has invalid regex pattern: " . $entry["pattern"]
            );
        }
    }
}
