<?php

class MixpanelTestConsumer extends ConsumerStrategies_AbstractConsumer {
    public static $succeed = true;
    public static $persisted = array();
    public function persist($batch) {
        if (self::$succeed) {
            self::$persisted = array_merge(self::$persisted, $batch);
        }
        return self::$succeed;
    }
}

class MixpanelTest extends PHPUnit_Framework_TestCase {

    /**
     * @var Mixpanel
     */
    protected $_instance = null;

    protected function setUp() {
        parent::setUp();
        $this->_instance = Mixpanel::getInstance("token");
    }

    protected function tearDown() {
        parent::tearDown();
        $this->_instance->reset();
        $this->_instance = null;
        MixpanelTestConsumer::$succeed = true;
        MixpanelTestConsumer::$persisted = array();
    }

    public function testGetInstance() {
        $instance = Mixpanel::getInstance("token");
        $this->assertInstanceOf("Mixpanel", $instance);
        $this->assertEquals($this->_instance, $instance);
        $this->assertInstanceOf("Producers_MixpanelPeople", $this->_instance->people);
    }

    public function testFlushReturnsDeliveryResult() {
        $mp = new Mixpanel("token", array(
            "consumer"  => "test",
            "consumers" => array("test" => "MixpanelTestConsumer")
        ));

        MixpanelTestConsumer::$succeed = true;
        $mp->track("event");
        $this->assertTrue($mp->flush());

        MixpanelTestConsumer::$succeed = false;
        $mp->track("failed event");
        $this->assertFalse($mp->flush());

        // the failed event stays queued and is sent by the next flush
        MixpanelTestConsumer::$succeed = true;
        MixpanelTestConsumer::$persisted = array();
        $this->assertTrue($mp->flush());
        $this->assertCount(1, MixpanelTestConsumer::$persisted);
        $this->assertEquals("failed event", MixpanelTestConsumer::$persisted[0]["event"]);
    }

}
