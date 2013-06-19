<?php
require_once("../lib/Mixpanel.php"); // import the Mixpanel class
$mp = Mixpanel::getInstance("1ef7e30d2a58d27f4b90c42e31d6d7ad"); // instantiate the Mixpanel class

// create an alias for user id 12345
$mp->createAlias(12345, "john.doe@example.com");

// force a flush so that the alias gets created before any events are sent
$mp->flush();

// update the record previously identified by 12345 using the new alias
$mp->people->set("john.doe@example.com", array(
    '$last_name'        => "Doe-Aliased",
    'aliased'           => "indeed"
));
