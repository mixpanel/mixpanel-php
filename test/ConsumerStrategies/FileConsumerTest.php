<?php

namespace Mixpanel\Test\ConsumerStrategies;

use Mixpanel\ConsumerStrategies\FileConsumer;
use PHPUnit\Framework\TestCase;

class FileConsumerTest extends TestCase {

    /**
     * @var FileConsumer
     */
    protected $_instance = null;
    protected $_file = null;
    protected function setUp()
    {
        parent::setUp();
        $this->_file = dirname(__FILE__)."/output-".time().".txt";
        $this->_instance = new FileConsumer(array("file" => $this->_file));
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->_instance = null;
        @unlink($this->_file);
    }

    public function testPersist() {
        $this->_instance->persist(array("msg"));
        $contents = file_get_contents($this->_file);
        $this->assertEquals('["msg"]'."\n", $contents);
    }

}
