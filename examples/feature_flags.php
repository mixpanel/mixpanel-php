<?php
require __DIR__ . '/../vendor/autoload.php';

// Replace with your project token.
$mp = Mixpanel::getInstance("MY_TOKEN", array(
    "flags" => array(
        // Either the MODE_* constants or the raw strings 'local' /
        // 'remote' are accepted.
        "mode"             => FeatureFlags_MixpanelFlags::MODE_REMOTE,
        "report_exposure"  => true,
    ),
));

// IMPORTANT: when a flag uses a non-default Variant Assignment Key
// (e.g., device_id or a custom group key), supply BOTH that key AND
// distinct_id in the context — otherwise the SDK can't tie the
// exposure event back to a profile.
$context = array(
    "distinct_id"        => "user-12345",
    "device_id"          => "abcdef-12345",                  // for flags bucketed on device_id
    "custom_properties"  => array(
        "email" => "alice@example.com",
        "plan"  => "pro",
    ),
);

// Boolean-style probe.
if ($mp->flags->isEnabled("new-checkout", $context)) {
    echo "new-checkout is enabled\n";
} else {
    echo "new-checkout fell back; reason: " . $mp->flags->lastFailureReason() . "\n";
}

// Variant value with a typed fallback.
$theme = $mp->flags->getVariantValue("ui-theme", "light", $context);
echo "theme = $theme\n";

// Full variant for richer reporting.
$variant = $mp->flags->getVariant(
    "experiment-pricing",
    new FeatureFlags_MixpanelSelectedVariant(null, "control"),
    $context
);
printf("experiment-pricing => variant=%s value=%s exp_id=%s\n",
    $variant->variantKey,
    json_encode($variant->variantValue),
    $variant->experimentId
);

// Bulk evaluation. trackExposure() can be called per-flag after the
// caller actually consumes the value.
$all = $mp->flags->getAllVariants($context);
foreach ($all as $key => $v) {
    echo "[$key] {$v->variantKey} = " . json_encode($v->variantValue) . "\n";
}

// Local mode usage:
//
//   $mp = Mixpanel::getInstance("MY_TOKEN", array(
//       "flags" => array("mode" => FeatureFlags_MixpanelFlags::MODE_LOCAL),
//   ));
//   $mp->flags->loadDefinitions();   // fetch once per process
//   $variant = $mp->flags->getVariant("my-flag", $fallback, $context);
//
// In long-running CLI workers you can call loadDefinitions() on
// whatever schedule fits (e.g., every N minutes). The PHP SDK does not
// spawn background polling threads — request-per-process FPM/Apache
// deployments don't have a place to host them.

$mp->flags->shutdown();
$mp->flush();
