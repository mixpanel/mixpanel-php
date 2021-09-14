<?php

class MixpanelGroupProducerTest extends PHPUnit_Framework_TestCase {

    /**
     * @var Producers_MixpanelGroup
     */
    protected $_instance = null;

    protected function setUp()
    {
        parent::setUp();
        $this->_instance = new Producers_MixpanelGroup("token");
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->_instance->reset();
        $this->_instance = null;
    }

    public function testSet() {
        $this->_instance->set('company',"ACME Co", array("company_size" => 123), "192.168.0.1");
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

        $this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
        $this->assertEquals("token", $msg['$token']);
        $this->assertEquals("192.168.0.1", $msg['$ip']);
        $this->assertArrayNotHasKey('$ignore_time', $msg);
        $this->assertArrayNotHasKey('$ignore_alias', $msg);
        $this->assertArrayHasKey('$set', $msg);
        $this->assertArrayHasKey("company_size", $msg['$set']);
        $this->assertEquals(123, $msg['$set']['company_size']);
    }

    public function testSetIgnoreTime() {
        $this->_instance->set('company',"ACME Co", array("company_size" => 123), "192.168.0.1", true);
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

		$this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
		$this->assertEquals("token", $msg['$token']);
		$this->assertEquals("192.168.0.1", $msg['$ip']);
		$this->assertEquals(true, $msg['$ignore_time']);
		$this->assertArrayNotHasKey('$ignore_alias', $msg);
		$this->assertArrayHasKey('$set', $msg);
		$this->assertArrayHasKey("company_size", $msg['$set']);
		$this->assertEquals(123, $msg['$set']['company_size']);
    }

    public function testSetIgnoreAlias() {
        $this->_instance->set('company',"ACME Co", array("company_size" => 123), "192.168.0.1", false, true);
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

		$this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
		$this->assertEquals("token", $msg['$token']);
		$this->assertEquals("192.168.0.1", $msg['$ip']);
		$this->assertArrayNotHasKey('$ignore_time', $msg);
		$this->assertEquals(true, $msg['$ignore_alias']);
		$this->assertArrayHasKey('$set', $msg);
		$this->assertArrayHasKey("company_size", $msg['$set']);
		$this->assertEquals(123, $msg['$set']['company_size']);
    }


    public function testSetOnce() {
        $this->_instance->setOnce('company',"ACME Co", array("company_size" => 123), "192.168.0.1");
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

        $this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
        $this->assertEquals("token", $msg['$token']);
        $this->assertEquals("192.168.0.1", $msg['$ip']);
        $this->assertArrayHasKey('$set_once', $msg);
        $this->assertArrayHasKey("company_size", $msg['$set_once']);
        $this->assertEquals(123, $msg['$set_once']['company_size']);
    }

    public function testAppendSingle() {
        $this->_instance->append('company',"ACME Co", "actions", "Logged In", "192.168.0.1");
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

        $this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
        $this->assertEquals("token", $msg['$token']);
        $this->assertEquals("192.168.0.1", $msg['$ip']);
        $this->assertArrayHasKey('$append', $msg);
        $this->assertArrayHasKey("actions", $msg['$append']);
        $this->assertEquals("Logged In", $msg['$append']['actions']);
    }

    public function testAppendMultiple() {
        $this->_instance->append('company',"ACME Co", "actions", array("Logged In", "Logged Out"), "192.168.0.1");
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

        $this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
        $this->assertEquals("token", $msg['$token']);
        $this->assertEquals("192.168.0.1", $msg['$ip']);
        $this->assertArrayHasKey('$union', $msg);
        $this->assertArrayHasKey("actions", $msg['$union']);
        $this->assertEquals(array("Logged In", "Logged Out"), $msg['$union']['actions']);
    }

    public function testDeleteGroup() {
        $this->_instance->deleteGroup('company',"ACME Co","192.168.0.1");
        $queue = $this->_instance->getQueue();
        $msg = $queue[count($queue)-1];

        $this->assertEquals("company", $msg['$group_key']);
		$this->assertEquals("ACME Co", $msg['$group_id']);
        $this->assertEquals("token", $msg['$token']);
        $this->assertEquals("192.168.0.1", $msg['$ip']);
        $this->assertArrayHasKey('$delete', $msg);
        $this->assertEquals("", $msg['$delete']);
    }
}
