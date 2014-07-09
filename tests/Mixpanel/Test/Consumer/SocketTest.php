<?php

namespace Mixpanel\Test\Consumer;

use Mixpanel\Consumer\Socket;

class SocketTest extends \PHPUnit_Framework_TestCase {

    /**
     * @var \Mixpanel\Consumer\Socket
     */
    protected $_instance = null;
    protected $_file = null;
    protected function setUp()
    {
        parent::setUp();
        $this->_instance = new Socket(array(
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
