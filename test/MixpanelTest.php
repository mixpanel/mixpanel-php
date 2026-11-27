<?php

class MixpanelTestConsumer extends ConsumerStrategies_AbstractConsumer {
    public static $succeed = true;
    public function persist($batch) {
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
        $mp->track("event");
        $this->assertFalse($mp->flush());

        $mp->reset();
    }

}
