<?php
/**
 * This example demonstrates using Service Account credentials with the Mixpanel PHP library.
 * Service Accounts are the recommended authentication method for server-to-server integration.
 */

require_once(dirname(__FILE__) . "/../lib/Mixpanel.php");

// Initialize Mixpanel with Service Account credentials
$credentials = new Credentials_ServiceAccountCredentials(
    "YOUR_PROJECT_ID",           // Your Mixpanel project ID
    "YOUR_SERVICE_ACCOUNT_USERNAME",  // Service account username
    "YOUR_SERVICE_ACCOUNT_SECRET"     // Service account secret
);

// Get a Mixpanel instance with the credentials
$mp = Mixpanel::getInstance($credentials);

// Track an event
$mp->track("Service Account Event", array(
    "authentication" => "service_account",
    "environment" => "production"
));

// Update a user profile
$mp->people->set("user123", array(
    '$first_name' => "John",
    '$last_name' => "Doe",
    '$email' => "john@example.com",
    'plan' => "premium"
));

// Track a group event
$mp->group->set("company_id", "acme-corp", array(
    "company_name" => "Acme Corporation",
    "plan" => "enterprise",
    "employee_count" => 500
));

echo "Events and profile updates sent successfully using Service Account credentials!\n";
