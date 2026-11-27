<?php

class MixpanelTest extends PHPUnit_Framework_TestCase {

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
        $this->assertInstanceOf("Mixpanel", $instance);
        $this->assertEquals($this->_instance, $instance);
        $this->assertInstanceOf("Producers_MixpanelPeople", $this->_instance->people);
    }

    public function testLoadingASecondCopyOfTheLibraryDoesNotRedeclareClasses() {
        // Simulates two WordPress plugins each bundling the SDK at a different path.
        $copy = sys_get_temp_dir() . "/mixpanel-php-copy-" . uniqid();
        $lib = dirname(__FILE__) . "/../lib";
        $dirs = array("", "/Base", "/ConsumerStrategies", "/Producers");
        try {
            foreach ($dirs as $dir) {
                mkdir($copy . $dir, 0777, true);
                foreach (glob($lib . $dir . "/*.php") as $file) {
                    copy($file, $copy . $dir . "/" . basename($file));
                }
            }

            require_once($copy . "/Mixpanel.php");

            $class = new ReflectionClass("Mixpanel");
            $this->assertEquals(realpath($lib . "/Mixpanel.php"), realpath($class->getFileName()));
        } finally {
            foreach (array_reverse($dirs) as $dir) {
                array_map("unlink", glob($copy . $dir . "/*.php"));
                @rmdir($copy . $dir);
            }
        }
    }

}

