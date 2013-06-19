<?php

class ConsumerStrategies_CurlConsumerTest extends PHPUnit_Framework_TestCase {

    public function testSettings() {
        $consumer = new CurlConsumer(array(
            "host"      => "localhost",
            "endpoint"  => "/endpoint",
            "timeout"   => 2,
            "use_ssl"   => false,
            "fork"      => false
        ));

        $this->assertEquals("localhost", $consumer->getHost());
        $this->assertEquals("/endpoint", $consumer->getEndpoint());
        $this->assertEquals(2, $consumer->getTimeout());
        $this->assertEquals("http", $consumer->getProtocol());
        $this->assertEquals(false, $consumer->getFork());
    }

    public function testBlocking() {
        $consumer = new CurlConsumer(array(
            "host"      => "localhost",
            "endpoint"  => "/endpoint",
            "timeout"   => 2,
            "use_ssl"   => true,
            "fork"      => false
        ));
        $consumer->persist(array("msg"));
        $this->assertEquals(1, $consumer->blockingCalls);
    }


    public function testForked() {
        $consumer = new CurlConsumer(array(
            "host"      => "localhost",
            "endpoint"  => "/endpoint",
            "timeout"   => 2,
            "use_ssl"   => true,
            "fork"      => true
        ));
        $consumer->persist(array("msg"));
        $this->assertEquals(1, $consumer->forkedCalls);
    }

}

class CurlConsumer extends ConsumerStrategies_CurlConsumer {

    public $forkedCalls = 0;
    public $blockingCalls = 0;

    /**
     * @return string
     */
    public function getEndpoint()
    {
        return $this->_endpoint;
    }

    /**
     * @return bool|null
     */
    public function getFork()
    {
        return $this->_fork;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->_host;
    }

    /**
     * @return string
     */
    public function getProtocol()
    {
        return $this->_protocol;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->_timeout;
    }

    protected function _execute($url, $data)
    {
        $this->blockingCalls++;
        return parent::_execute($url, $data);
    }

    protected function _execute_forked($url, $data)
    {
        $this->forkedCalls++;
        return parent::_execute_forked($url, $data);
    }


}