<?php

class ConsumerStrategies_SocketConsumerTest extends PHPUnit\Framework\TestCase {

    /**
     * @var ConsumerStrategies_SocketConsumer
     */
    protected $_instance = null;
    protected $_file = null;
    protected function setUp() : void
    {
        parent::setUp();
        $this->_instance = new ConsumerStrategies_SocketConsumer(array(
            "host"      => "localhost",
            "endpoint"  => "/endpoint",
            "timeout"   => 2,
            "use_ssl"   => false
        ));
    }

    protected function tearDown() : void
    {
        parent::tearDown();
        $this->_instance = null;
    }

    public function testPersist() {

    }


}
