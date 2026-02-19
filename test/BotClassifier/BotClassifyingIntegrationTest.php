<?php
require_once(dirname(__FILE__) . "/../../lib/Mixpanel.php");

use PHPUnit\Framework\TestCase;

class BotClassifyingIntegrationTest extends TestCase {

    /** @var Mixpanel */
    protected $_instance = null;

    protected function setUp(): void {
        parent::setUp();
        $this->_instance = new Mixpanel("test-token", array(
            "bot_detection" => true
        ));
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->_instance->reset();
        $this->_instance = null;
    }

    // === CORE CLASSIFICATION VIA track() ===

    public function testEnrichesTrackCallsWhenUserAgentPresent() {
        $this->_instance->track("page_view", array(
            '$user_agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)',
            'distinct_id' => 'user123'
        ));
        $queue = $this->_instance->getQueue();
        $this->assertEquals(1, count($queue));
        $this->assertEquals("page_view", $queue[0]['event']);
        $props = $queue[0]['properties'];
        $this->assertTrue($props['$is_ai_bot']);
        $this->assertEquals("GPTBot", $props['$ai_bot_name']);
        $this->assertEquals("OpenAI", $props['$ai_bot_provider']);
        $this->assertEquals("indexing", $props['$ai_bot_category']);
    }

    public function testNoClassificationWhenUserAgentAbsent() {
        $this->_instance->track("page_view", array(
            'distinct_id' => 'user123', 'page' => '/home'
        ));
        $queue = $this->_instance->getQueue();
        $props = $queue[0]['properties'];
        $this->assertArrayNotHasKey('$is_ai_bot', $props);
        $this->assertArrayNotHasKey('$ai_bot_name', $props);
    }

    public function testIsAiBotFalseWhenUserAgentIsNotBot() {
        $this->_instance->track("page_view", array(
            '$user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            'distinct_id' => 'user123'
        ));
        $queue = $this->_instance->getQueue();
        $props = $queue[0]['properties'];
        $this->assertFalse($props['$is_ai_bot']);
        $this->assertArrayNotHasKey('$ai_bot_name', $props);
    }

    // === PROPERTY PRESERVATION ===

    public function testPreservesUserProperties() {
        $this->_instance->track("page_view", array(
            '$user_agent' => 'GPTBot/1.2',
            'page_url' => '/products', 'custom_prop' => 'value', 'distinct_id' => 'user123'
        ));
        $props = $this->_instance->getQueue()[0]['properties'];
        $this->assertEquals("/products", $props['page_url']);
        $this->assertEquals("value", $props['custom_prop']);
        $this->assertEquals("user123", $props['distinct_id']);
        $this->assertTrue($props['$is_ai_bot']);
    }

    public function testSuperPropertiesMergeCorrectly() {
        $this->_instance->register("platform", "web");
        $this->_instance->register("app_version", "2.0");
        $this->_instance->track("page_view", array(
            '$user_agent' => 'GPTBot/1.2', 'distinct_id' => 'user123'
        ));
        $props = $this->_instance->getQueue()[0]['properties'];
        $this->assertEquals("web", $props['platform']);
        $this->assertEquals("2.0", $props['app_version']);
        $this->assertTrue($props['$is_ai_bot']);
    }

    public function testSdkDefaultPropertiesStillAdded() {
        $this->_instance->track("page_view", array('$user_agent' => 'GPTBot/1.2'));
        $props = $this->_instance->getQueue()[0]['properties'];
        $this->assertEquals("test-token", $props['token']);
        $this->assertArrayHasKey("time", $props);
        $this->assertEquals("php", $props['mp_lib']);
    }

    // === BOT DETECTION DISABLED ===

    public function testNoClassificationWhenBotDetectionDisabled() {
        $mp = new Mixpanel("test-token", array("bot_detection" => false));
        $mp->track("page_view", array(
            '$user_agent' => 'GPTBot/1.2', 'distinct_id' => 'user123'
        ));
        $props = $mp->getQueue()[0]['properties'];
        $this->assertArrayNotHasKey('$is_ai_bot', $props);
        $mp->reset();
    }

    public function testNoClassificationWhenBotDetectionNotSet() {
        $mp = new Mixpanel("test-token");
        $mp->track("page_view", array(
            '$user_agent' => 'GPTBot/1.2', 'distinct_id' => 'user123'
        ));
        $props = $mp->getQueue()[0]['properties'];
        $this->assertArrayNotHasKey('$is_ai_bot', $props);
        $mp->reset();
    }

    // === MULTIPLE BOT TYPES ===

    public function testMultipleBotTypesClassifiedCorrectly() {
        $bots = array(
            array('GPTBot/1.2', 'GPTBot', 'OpenAI'),
            array('ClaudeBot/1.0', 'ClaudeBot', 'Anthropic'),
            array('PerplexityBot/1.0', 'PerplexityBot', 'Perplexity'),
            array('CCBot/2.0', 'CCBot', 'Common Crawl'),
        );
        foreach ($bots as $bot) {
            $this->_instance->reset();
            $this->_instance->track("page_view", array('$user_agent' => $bot[0]));
            $props = $this->_instance->getQueue()[0]['properties'];
            $this->assertTrue($props['$is_ai_bot'], "Failed for " . $bot[0]);
            $this->assertEquals($bot[1], $props['$ai_bot_name'], "Wrong name for " . $bot[0]);
            $this->assertEquals($bot[2], $props['$ai_bot_provider'], "Wrong provider for " . $bot[0]);
        }
    }

    // === CONSUMER WRAPPER APPROACH ===

    public function testConsumerWrapperClassifiesEvents() {
        $file = dirname(__FILE__) . "/consumer-test-" . time() . ".txt";
        $mp = new Mixpanel("test-token", array(
            "consumers" => array("bot_classifying" => "ConsumerStrategies_BotClassifyingConsumer"),
            "consumer" => "bot_classifying",
            "bot_classifying_inner_consumer" => "file",
            "file" => $file
        ));
        $mp->track("page_view", array(
            '$user_agent' => 'GPTBot/1.2', 'distinct_id' => 'user123'
        ));
        $queue = $mp->getQueue();
        $this->assertEquals(1, count($queue));
        $this->assertEquals("page_view", $queue[0]['event']);
        // Classification happens in the consumer during flush, not at track() time
        $mp->flush();
        $this->assertFileExists($file);
        $contents = file_get_contents($file);
        $this->assertStringContainsString('$is_ai_bot', $contents);
        $this->assertStringContainsString('$ai_bot_name', $contents);
        $this->assertStringContainsString('$ai_bot_provider', $contents);
        $this->assertStringContainsString('$ai_bot_category', $contents);
        $mp->reset();
        if (file_exists($file)) { unlink($file); }
    }

    // === EVENT NAME + IDENTIFY ===

    public function testEventNamePreserved() {
        $this->_instance->track("custom_event_name", array('$user_agent' => 'GPTBot/1.2'));
        $this->assertEquals("custom_event_name", $this->_instance->getQueue()[0]['event']);
    }

    public function testIdentifyStillWorksWithBotDetection() {
        $this->_instance->identify("user123");
        $this->_instance->track("page_view", array('$user_agent' => 'GPTBot/1.2'));
        $props = $this->_instance->getQueue()[0]['properties'];
        $this->assertEquals("user123", $props['distinct_id']);
        $this->assertTrue($props['$is_ai_bot']);
    }
}
