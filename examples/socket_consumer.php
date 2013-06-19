<?php
require_once("../lib/Mixpanel.php"); // import the Mixpanel class

$mp = new Mixpanel("1ef7e30d2a58d27f4b90c42e31d6d7ad", array(
    "debug"             => true,
    "consumer"          => "socket",
    "use_ssl"           => false
));

$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event", array("color" => "red"));
