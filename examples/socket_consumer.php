<?php

use Mixpanel\Mixpanel;

$mp = new Mixpanel("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "consumer"          => "socket",
    "use_ssl"           => false
));

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
