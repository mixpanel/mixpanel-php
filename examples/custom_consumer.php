<?php

use Mixpanel\Mixpanel;

$mp = new Mixpanel("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "max_batch_size"    => 1,
    "consumers"         => array("ob" => ObConsumer::class),
    "consumer"          => "ob"
));

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
