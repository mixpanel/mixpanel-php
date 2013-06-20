<?php
require_once("../lib/Mixpanel.php"); // import the Mixpanel class

$mp = new Mixpanel("MIXPANEL_PROJECT_TOKEN", array(
    "debug"             => true,
    "consumers"         => array("ob" => "ObConsumer"),
    "consumer"          => "ob",
    "error_callback"    => "handleError"
));


// register the special property "distinct_id" with value of our user with id 12345
// to be attached to all subsequent track calls
$mp->register("distinct_id", 12345);

// track a custom "button click" event
$mp->track("button click", array("label" => "Login"));

// track a custom "logged in" event
$mp->track("logged in", array("landing page" => "/specials"));

// create/update a profile identified by id 12345 with $first_name set to John and $email set to john.doe@example.com
// now we can send them Notifications!
$mp->people->set(12345, array(
    '$first_name' => "John",
    '$email' => "john.doe@example.com"
));

// update John's profile with property ad_source to be "google" but don't override ad_source if it exists already
$mp->people->setOnce(12345, array("ad_source" => "google"));

// increment John's total logins by one
$mp->people->increment(12345, "login count", 1);

// append a new favorite to John's favorites
$mp->people->append(12345, "favorites", "Apples");

// append a few more favorites to John's favorites
$mp->people->append(12345, "favorites", array("Baseball", "Reading"));

// track a purchase or charge of $9.99 for user 12345 where the transaction happened just now
$mp->people->trackCharge(12345, "9.99");

// track a purchase or charge of $20 for user 12345 where the transaction happened on June 01, 2013 at 5pm EST
$mp->people->trackCharge(12345, "20.00", strtotime("01 Jun 2013 5:00:00 PM EST"));

// clear all purchases for user 12345
$mp->people->clearCharges(12345);

// delete the profile for user 12345
$mp->people->deleteUser(12345);

// create an alias for user 12345
$mp->createAlias(12345, "johndoe1");

// track an even using the alias
$mp->track("logout", array("distinct_id" => "johndoe1"));

// manually put messages on the queue (useful for batch processing)
$mp->enqueueAll(array(
    array("event" => "test"),
    array("event" => "test2")
));