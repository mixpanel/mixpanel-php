<?php

class MixpanelEventsProducerTest extends PHPUnit_Framework_TestCase {

    /**
     * @var Producers_MixpanelEvents
     */
    protected $_instance = null;

    protected function setUp()
    {
        parent::setUp();
        $this->_instance = new Producers_MixpanelEvents("token");
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->_instance->reset();
        $this->_instance = null;
    }


    public function testTrack() {
        $this->_instance->track("test_event", array("number" => 1));
        $queue = $this->_instance->getQueue();
        $this->assertEquals(1, count($queue));
        $this->assertEquals("test_event", $queue[0]['event']);
        $this->assertEquals(1, $queue[0]['properties']['number']);
    }

    public function testRegister() {
        $this->_instance->register("super_property", "super_value");
        $this->assertEquals("super_value", $this->_instance->getProperty("super_property"));
    }


    public function testRegisterAll() {
        $this->_instance->registerAll(array("prop1" => "val1", "prop2" => "val2"));
        $this->assertEquals("val1", $this->_instance->getProperty("prop1"));
        $this->assertEquals("val2", $this->_instance->getProperty("prop2"));
    }

    public function testRegisterOnce() {
        $this->_instance->registerOnce("prop3", "val3");
        $this->_instance->registerOnce("prop3", "val4");
        $this->assertEquals("val3", $this->_instance->getProperty("prop3"));
    }

    public function testRegisterAllOnce() {
        $this->_instance->registerAllOnce(array("prop5" => "val5", "prop6" => "val6"));
        $this->_instance->registerAllOnce(array("prop5" => "val6", "prop6" => "val7"));
        $this->assertEquals("val5", $this->_instance->getProperty("prop5"));
        $this->assertEquals("val6", $this->_instance->getProperty("prop6"));
    }

    public function unregister() {
        $this->_instance->register("prop7", "val7");
        $this->_instance->register("prop8", "val8");
        $this->assertEquals("val7", $this->_instance->getProperty("prop7"));
        $this->assertEquals("val8", $this->_instance->getProperty("prop8"));
        $this->_instance->unregister("prop7");
        $this->assertEquals(null, $this->_instance->getProperty("prop7"));
        $this->assertEquals("val8", $this->_instance->getProperty("prop8"));
    }

    public function unregisterAll() {
        $this->_instance->registerAll(array("prop9" => "val9", "prop10" => "val10"));
        $this->assertEquals("val9", $this->_instance->getProperty("prop9"));
        $this->assertEquals("val10", $this->_instance->getProperty("prop10"));
        $this->assertEquals("val11", $this->_instance->getProperty("prop11"));
        $this->_instance->unregisterAll(array("prop9", "prop10"));
        $this->assertEquals(null, $this->_instance->getProperty("prop9"));
        $this->assertEquals(null, $this->_instance->getProperty("prop10"));
        $this->assertEquals("val11", $this->_instance->getProperty("prop11"));
    }

    public function testCreateAlias() {
        $original_id = 1;
        $new_id = 2;
        $this->_instance->createAlias($original_id, $new_id);
        $queue = $this->_instance->getQueue();
        $lastEvent = $queue[count($queue)-1];
        $this->assertEquals('$create_alias', $lastEvent['event']);
        $this->assertEquals($original_id, $lastEvent['properties']['distinct_id']);
        $this->assertEquals($new_id, $lastEvent['properties']['alias']);
    }
}
