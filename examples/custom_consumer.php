<?php

use Mixpanel\Mixpanel;

// import the autoloader
require_once __DIR__ . "/../vendor/autoload.php";

// import the custom consumer
require_once __DIR__ . "/consumers/ObConsumer.php";

$mp = new Mixpanel("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "max_batch_size"    => 1,
    "consumers"         => array("ob" => "ObConsumer"),
    "consumer"          => "ob"
));

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
