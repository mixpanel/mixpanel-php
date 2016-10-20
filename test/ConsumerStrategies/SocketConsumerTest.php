<?php

namespace Mixpanel\Test\ConsumerStrategies;

use Mixpanel\ConsumerStrategies;

class SocketConsumerTest extends \PHPUnit_Framework_TestCase {

    /**
     * @var ConsumerStrategies_SocketConsumer
     */
    protected $_instance = null;
    protected $_file = null;
    protected function setUp()
    {
        parent::setUp();
        $this->_instance = new ConsumerStrategies\SocketConsumer(array(
            "host"      => "localhost",
            "endpoint"  => "/endpoint",
            "timeout"   => 2,
            "use_ssl"   => false
        ));
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->_instance = null;
    }

    public function testPersist() {

    }


}
