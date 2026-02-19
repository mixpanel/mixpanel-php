<?php

use PHPUnit\Framework\TestCase;

class MixpanelTest extends TestCase {

    /**
     * @var Mixpanel
     */
    protected $_instance = null;

    protected function setUp(): void {
        parent::setUp();
        $this->_instance = Mixpanel::getInstance("token");
    }

    protected function tearDown(): void {
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

}

