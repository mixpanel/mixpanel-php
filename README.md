Mixpanel PHP Library
============

##### _May 13, 2026_ - [2.11.0](https://github.com/mixpanel/mixpanel-php/releases/tag/2.11.0)

This library provides an API to track events and update profiles on Mixpanel.

Install with Composer
------------
Add mixpanel/mixpanel-php as a dependency and run composer update

```json
"require": {
    ...
    "mixpanel/mixpanel-php" : "2.*"
    ...
}
```

Now you can start tracking events and people:

```php
<?php
// import dependencies
require 'vendor/autoload.php';

// get the Mixpanel class instance, replace with your project token
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// track an event
$mp->track("button clicked", array("label" => "sign-up")); 

// create/update a profile for user id 12345
$mp->people->set(12345, array(
    '$first_name'       => "John",
    '$last_name'        => "Doe",
    '$email'            => "john.doe@example.com",
    '$phone'            => "5555555555",
    "Favorite Color"    => "red"
));
```


Install Manually
------------
 1. <a href="https://github.com/mixpanel/mixpanel-php/archive/master.zip">Download the Mixpanel PHP Library</a>
 2.  Extract the zip file to a directory called "mixpanel-php" in your project root
 3.  Now you can start tracking events and people:

```php
<?php
// import Mixpanel
require 'mixpanel-php/lib/Mixpanel.php';

// get the Mixpanel class instance, replace with your project token
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN");

// track an event
$mp->track("button clicked", array("label" => "sign-up"));

// create/update a profile for user id 12345
$mp->people->set(12345, array(
    '$first_name'       => "John",
    '$last_name'        => "Doe",
    '$email'            => "john.doe@example.com",
    '$phone'            => "5555555555",
    "Favorite Color"    => "red"
));
```

Feature Flags
-------------

`$mp->flags` evaluates Mixpanel feature flags either against the API on every
call (remote mode) or in-process after fetching definitions once (local mode).
Opt in by passing a `flags` config block when constructing the SDK:

```php
$mp = Mixpanel::getInstance("MIXPANEL_PROJECT_TOKEN", array(
    "flags" => array(
        "mode" => FeatureFlags_MixpanelFlags::MODE_REMOTE,  // or MODE_LOCAL
    ),
));

$context = array(
    "distinct_id"       => "user-12345",
    // Include any custom bucketing-key attributes the flag was configured
    // against (e.g., device_id) alongside distinct_id.
    "device_id"         => "abcdef-12345",
    "custom_properties" => array("email" => "alice@example.com", "plan" => "pro"),
);

if ($mp->flags->isEnabled("new-checkout", $context)) {
    // ...
}
```

`isEnabled()` is the boolean shortcut. For typed values or full variant
metadata, use `getVariantValue()` or `getVariant()`:

```php
$theme   = $mp->flags->getVariantValue("ui-theme", "light", $context);

$variant = $mp->flags->getVariant(
    "experiment-pricing",
    new FeatureFlags_MixpanelSelectedVariant(null, "control"),
    $context
);
echo $variant->variantKey;      // "treatment-a" / null
echo $variant->variantValue;    // mixed
echo $variant->experimentId;    // string|null
echo $variant->fallbackReason;  // null on success; REASON_* if the SDK fell back
```

**Local mode** requires one explicit fetch of the definitions before evaluation
(PHP's request-per-process model doesn't allow background polling like the
Python/Ruby/Go/Node SDKs do):

```php
$mp = Mixpanel::getInstance("TOKEN", array(
    "flags" => array("mode" => FeatureFlags_MixpanelFlags::MODE_LOCAL),
));
$mp->flags->loadDefinitions();                            // fetch once
$enabled = $mp->flags->isEnabled("my-flag", $context);    // in-process eval
```

For long-running CLI workers, re-fetch on whatever cadence fits:

```php
$lastRefresh = time();
while ($job = $queue->next()) {
    if (time() - $lastRefresh >= 60) {
        if ($mp->flags->loadDefinitions()) $lastRefresh = time();
    }
    processJob($job, $mp);
}
```

### Configuration

| Option | Default | Description |
| --- | --- | --- |
| `mode` | `"remote"` | `MODE_LOCAL` or `MODE_REMOTE` (raw strings work too). |
| `api_host` | inherits top-level `host`, then `"api.mixpanel.com"` | EU customers set `"api-eu.mixpanel.com"`; India `"api-in.mixpanel.com"`. |
| `request_timeout_in_seconds` | `10` | Total budget per flags HTTP call (applied to connect + read). |

The top-level `error_callback` option (shared with the event consumers) also
receives flag-side errors — backend failures, definition-fetch failures, and
warnings about missing `distinct_id` when an exposure can't be attached.

### Public API at a glance

```php
// Lifecycle (no-ops/sentinels in remote mode)
$mp->flags->loadDefinitions()  : bool       // local: fetch /flags/definitions
$mp->flags->areFlagsReady()    : bool       // local: ready check
$mp->flags->lastSyncedAt()     : int|null   // local: unix ts of last sync
$mp->flags->getMode()          : string     // "local" or "remote"
$mp->flags->shutdown()         : void

// Evaluation
$mp->flags->isEnabled($flagKey, $context)                       : bool
$mp->flags->getVariantValue($flagKey, $fallbackValue, $context) : mixed
$mp->flags->getVariant($flagKey, $fallback, $context, $reportExposure = true)
                                                                : FeatureFlags_MixpanelSelectedVariant
$mp->flags->getAllVariants($context)
                : array<string, FeatureFlags_MixpanelSelectedVariant>
$mp->flags->trackExposure($flagKey, $variant, $context)         : void

// SelectedVariant fields
$variant->variantKey           // string|null
$variant->variantValue         // mixed
$variant->experimentId         // string|null
$variant->isExperimentActive   // bool|null
$variant->isQaTester           // bool|null
$variant->fallbackReason       // null on success; REASON_* on fallback

// Fallback reasons (on FeatureFlags_MixpanelSelectedVariant)
REASON_FLAG_NOT_FOUND          // flag key doesn't exist
REASON_MISSING_CONTEXT_KEY     // context lacks the flag's bucketing attribute
REASON_NO_ROLLOUT_MATCH        // flag exists, no rollout matched
REASON_BACKEND_ERROR           // remote: HTTP transport / status failure
REASON_NOT_READY               // local: getVariant called before loadDefinitions
```

`isEnabled()` returns `true` only when the resolved variant value is literal
`bool(true)` — strings and other truthy values resolve to `false`. Use
`getVariantValue()` if your flag carries a non-boolean value.

`getAllVariants()` does NOT auto-fire exposure events (bulk exposure would
skew analytics for flags the caller never actually reads). Pair it with
`trackExposure()` per flag you consume.

### Common gotcha

`Mixpanel::getInstance()` caches per-token and **ignores `$options` on
subsequent calls**. If anything in your app calls `Mixpanel::getInstance($token)`
before your flag-aware code does, the cached instance's `$mp->flags` will be
`null`. Two safe patterns:

```php
// Always pass flags config at the first call site
Mixpanel::getInstance("TOKEN", array("flags" => array("mode" => "remote")));

// Or bypass the singleton entirely
$mp = new Mixpanel("TOKEN", array("flags" => array("mode" => "remote")));
```

Full reference docs live on the Mixpanel docs site.

Production Notes
-------------
By default, data is sent using ssl over cURL. This works fine when you're tracking a small number of events or aren't concerned with the potentially blocking nature of the PHP cURL calls. However, this isn't very efficient when you're sending hundreds of events (such as in batch processing). Our library comes packaged with an easy way to use a persistent socket connection for much more efficient writes. To enable the persistent socket, simply pass `'consumer' => 'socket'` as an entry in the `$options` array when you instantiate the Mixpanel class. Additionally, you can contribute your own persistence implementation by creating a custom Consumer.

Testing
-------------
mixpanel-php uses `phpunit` as the testing framework. Please ensure that `composer` is up to date. 
To run tests, execute `composer run-script unit-tests` on the root directory. 

Documentation
-------------
* <a href="https://mixpanel.com/help/reference/php" target="_blank">Reference Docs</a>
* <a href="http://mixpanel.github.io/mixpanel-php" target="_blank">Full API Reference</a>

For further examples and options check out the "examples" folder.

Changelog
-------------
Version 2.11.0
* Fix identify regex for $anon_id
* Fix PHP 8.2 deprecation warning

Version 2.10.0
* send millisecond precision timestamps

Version 2.9.0
* update regex for $anon_id check
* Group Analytics Support
* Fix PHP 8.1 deprecation warning
* PHP 7.4 compatibility

Version 2.8.1
* Updated `$anon_id` regex in `identify` method to support all Mixpanel distinct IDs

Version 2.8.0
* Added `$anon_id` parameter to `identify` method, and a track call when parameter exists and is in UUID v4 format
* Change parameter names for `createAlias` method to `$distinct_id` and `$alias`
* Prevent unnecessary call to _encode on non-forked CurlConsumer
* make sure 'Connection' exists before accessing it

Version 2.7.0:
 * Dropped test support for EOL PHP version (all < 7.1)
 * Added <a href="https://github.com/mixpanel/mixpanel-php/commit/6f15000309093b54f7f59f07af297f576fd3a498">Parallel cURL implementation</a>
 * <a href="https://github.com/mixpanel/mixpanel-php/commit/1f814c1be704217e4bc8bf570fad844360fa7318">Make createAlias adhere to the consumer config</a>
 * Added <a href="https://github.com/mixpanel/mixpanel-php/commit/f2812f4e696ef747b2ab0640f46df97d1bf309c0">option to set $ignore_alias on people updates</a>
 * Fixed <a href="https://github.com/mixpanel/mixpanel-php/commit/d50267c48b08eb3c5e1dee2b5dd932cf2b4c3977">Singleton instance must depend on requested token</a>
 * Fixed license type in composer.json
 * Remove testing on HHVM as its no longer support by composer

Version 2.6.2:
 * Added support for $ignore_time
 * Cleaned up some comments to be more clear

Version 2.6.1:
 * Fixed bug in SocketConsumer timeout

Version 2.6:
 * Updated default for `connect_timeout` in SocketConsumer to be 5

Version 2.5:
 * `timeout` option now refers to `CURLOPT_TIMEOUT` instead of `CURLOPT_CONNECTTIMEOUT` in non-forked cURL calls, it has been removed from the SocketConsumer in favor of a new `connect_timeout` option.
 * Added a new `connect_timeout` option for CURLOPT_CONNECTTIMEOUT in non-forked cURL calls (CurlConsumer) and the socket timeout (SocketConsumer)
 * Set default timeout (CURLOPT_TIMEOUT) to 30 seconds in non-forked cURL calls
 * Set default connection timeoute (CURLOPT_CONNECTTIMEOUT) to 5 seconds in non-forked cURL calls
 * We now pass cURL errors from non-forked cURL calls to `_handle_error` with the curl errno and message


Version 2.4:
 * Fixed a bug where passing the integer 0 for the `ip` parameter would be ignored

Version 2.1 - 2.3:
 * Broken releases

Version 2.0:
 * Changed the default consumer to be 'curl' (CurlConsumer)
 * Changed the default setting of 'fork' to false in the Curl Consumer. This means that by default, events and profile updates are sent synchronously using the PHP cURL lib when using the Curl Consumer.
 * 'createAlias' uses the CurlConsumer with 'fork' explicitly set to false (as we need this to be synchronous) instead of the SocketConsumer. 
 * Fixed bug where max_queue_size was never read
 
