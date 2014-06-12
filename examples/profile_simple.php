<?php
//Use the Mixpanel Factory class
use Mixpanel\Factory\MixpanelFactory;

// Get a Mixpanel instance
$factory = new MixpanelFactory("MIXPANEL_PROJECT_TOKEN");
$mp = $factory->get();

// create or update a profile with First Name, Last Name, E-Mail Address, Phone Number, and Favorite Color
$mp->people->set(12345, array(
    '$first_name'       => "John",
    '$last_name'        => "Doe",
    '$email'            => "john.doe@example.com",
    '$phone'            => "5555555555",
    "Favorite Color"    => "red"
));
