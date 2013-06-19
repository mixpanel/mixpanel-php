<?php
// import the Mixpanel class
require_once("../lib/Mixpanel.php");

// instantiate the Mixpanel class
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// create an alias for user id 12345
$mp->createAlias(12345, "john.doe@example.com");

// force a flush so that the alias gets created before any events are sent
$mp->flush();

// update the record previously identified by 12345 using the new alias
$mp->people->set("john.doe@example.com", array(
    '$last_name'        => "Doe-Aliased",
    'aliased'           => "indeed"
));
