<?php

namespace Mixpanel\Test;

use Mixpanel\Mixpanel;
use Mixpanel\Producers\PeopleProducer;
use PHPUnit\Framework\TestCase;

class MixpanelTest extends TestCase {

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
        $this->assertInstanceOf(Mixpanel::class, $instance);
        $this->assertEquals($this->_instance, $instance);
        $this->assertInstanceOf(PeopleProducer::class, $this->_instance->people);
    }

}

