<?php
$mp = new Mixpanel("1ef7e30d2a58d27f4b90c42e31d6d7ad", array(
    "debug"             => true,
    "consumers"         => array("ob" => "ObConsumer"),
    "consumer"          => "ob",
    "error_callback"    => "handleError"
));

$mp->createAlias(12345, "jbwyme");
$mp->register("distinct_id", "jbwyme"); // will get passed in all subsequent track calls
$mp->track("test_event", array("color" => "blue"));
$mp->track("test_event2", array("color" => "blue"));
$mp->people->set(12345, array('$first_name' => "Josh"));
$mp->people->set(12345, array('$email' => "wymetyme@gmail.com"));
$mp->people->setOnce(12345, array("ad_source" => "google"));
$mp->people->increment(12345, 12, 1);
$mp->people->append(12345, "page_views", "homepage12");
$mp->people->append(12345, "page_views", array("homepage2", "landing_page3", "login_page4"));
$mp->people->trackCharge(12345, "25.50", time()-300000);
$mp->people->trackCharge(12345, "25.50");
$mp->people->clear_charges(12345);
$mp->people->delete_user(12345);

// manually queue messages (useful for batch processing)
$mp->enqueueAll(array(
    array("event" => "test"),
    array("event" => "test2")
));