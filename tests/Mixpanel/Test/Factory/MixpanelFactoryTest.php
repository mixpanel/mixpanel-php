<?php

namespace Mixpanel\Test\Factory;

use Mixpanel\Mixpanel;
use Mixpanel\Factory\MixpanelFactory;

class MixpanelFactoryTest extends \PHPUnit_Framework_TestCase {

    /**
     * @var Mixpanel
     */
    protected $_instance = null;

    protected $_factory = null;

    protected function setUp() {
        parent::setUp();
        $this->_factory = new MixpanelFactory("token");
        $this->_instance = $this->_factory->get();
    }

    protected function tearDown() {
        parent::tearDown();
        unset($this->_instance);
        unset($this->_instance);
    }

    public function testGetInstance() {
        $instance = $this->_factory->get();
        $this->assertInstanceOf('Mixpanel\Mixpanel', $instance);
        $this->assertEquals($this->_instance, $instance);
    }

    public function testDefaultPeopleObjectIsInstantiated() {
        $mp = $this->_factory->get();
        $this->assertInstanceOf('Mixpanel\Producer\People', $mp->getPeople());
    }

    public function testDefaultEventsObjectIsInstantiated() {
        $mp = $this->_factory->get();
        $this->assertInstanceOf('Mixpanel\Producer\Events', $mp->getEvents());
    }


}

