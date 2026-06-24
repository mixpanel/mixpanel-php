<?php

require_once(dirname(__FILE__) . "/MixpanelFlagsBase.php");

/**
 * In-process feature-flag evaluator. Pulls definitions from
 * `/flags/definitions` on demand via loadDefinitions(); evaluates each
 * call against the cached definitions using the same FNV-1a + JSON
 * Logic algorithms as every other Mixpanel SDK.
 *
 * PHP has no native threading and FPM/Apache processes don't survive
 * past a single request, so this SDK deliberately omits the background
 * polling thread that the Python, Ruby, Go, Java, and Node server SDKs
 * use to refresh definitions. Long-lived CLI workers should instead
 * configure `refresh_interval_in_seconds` and call refresh() inside
 * their main loop — refresh() is a no-op until that interval elapses,
 * so it's safe to call on every iteration.
 */
class FeatureFlags_MixpanelLocalFlags extends FeatureFlags_MixpanelFlagsBase {

    const DEFINITIONS_PATH = '/flags/definitions';

    /** @var array map of flag_key => flag definition (decoded JSON) */
    private $_definitions = array();

    /** @var bool */
    private $_ready = false;

    /** @var int|null unix timestamp of last successful loadDefinitions */
    private $_lastSyncedAt = null;

    /**
     * @var int|null seconds after which cached definitions are
     * considered stale. null = no staleness check; refresh() does
     * nothing in that case and the caller must manage refreshes via
     * loadDefinitions() directly.
     */
    private $_refreshInterval = null;

    public function __construct($token, $version, $tracker, array $options) {
        parent::__construct($token, $version, $tracker, $options);
        $flagsOpts = isset($options['flags']) && is_array($options['flags']) ? $options['flags'] : array();
        if (isset($flagsOpts['refresh_interval_in_seconds'])) {
            $val = (int) $flagsOpts['refresh_interval_in_seconds'];
            // Treat 0/negative as "always stale" (refresh fetches on
            // every call) only if the caller explicitly set it; null
            // means "no staleness behavior at all."
            $this->_refreshInterval = max(0, $val);
        }
    }

    /**
     * Whether cached definitions are missing or older than the
     * configured refresh interval. Always returns true when
     * loadDefinitions() has never succeeded; returns false in remote
     * mode (the facade forwards there) since there's nothing cached
     * to go stale.
     *
     * @return bool
     */
    public function needsRefresh() {
        if (!$this->_ready) {
            return true;
        }
        if ($this->_refreshInterval === null) {
            // Customer opted out of staleness checks — definitions
            // are considered fresh until they explicitly reload.
            return false;
        }
        if ($this->_lastSyncedAt === null) {
            return true;
        }
        return (time() - $this->_lastSyncedAt) >= $this->_refreshInterval;
    }

    /**
     * Convenience for long-running workers: call inside the main loop
     * and the SDK refreshes definitions only when they're missing or
     * past the configured `refresh_interval_in_seconds`. Returns true
     * when definitions are usable after the call (either already
     * fresh, or freshly fetched); false when a fetch was attempted
     * and failed.
     *
     * @return bool
     */
    public function refresh() {
        if (!$this->needsRefresh()) {
            return true;
        }
        return $this->loadDefinitions();
    }

    /**
     * Fetch the latest flag definitions from Mixpanel. Throws nothing
     * on transport failure — the error is routed to error_callback so
     * the existing definitions (if any) keep working until the next
     * successful call. Returns true on success.
     *
     * @return bool
     */
    public function loadDefinitions() {
        try {
            $response = $this->_httpGet(self::DEFINITIONS_PATH);
        } catch (Exception $e) {
            $this->_handleError($e->getCode(), 'Failed to fetch flag definitions: ' . $e->getMessage());
            return false;
        }

        $flags = isset($response['flags']) && is_array($response['flags']) ? $response['flags'] : array();
        $byKey = array();
        foreach ($flags as $flag) {
            if (!isset($flag['key'])) {
                continue;
            }
            if (isset($flag['ruleset']['variants']) && is_array($flag['ruleset']['variants'])) {
                // Sort variants by key for deterministic bucket assignment.
                usort($flag['ruleset']['variants'], array(__CLASS__, '_compareVariantKeys'));
            }
            $byKey[$flag['key']] = $flag;
        }
        $this->_definitions = $byKey;
        $this->_ready = true;
        $this->_lastSyncedAt = time();
        return true;
    }

    public static function _compareVariantKeys($a, $b) {
        $ak = isset($a['key']) ? (string) $a['key'] : '';
        $bk = isset($b['key']) ? (string) $b['key'] : '';
        return strcmp($ak, $bk);
    }

    /** @return bool true once loadDefinitions has succeeded at least once */
    public function areFlagsReady() {
        return $this->_ready;
    }

    /** @return int|null unix timestamp of most recent successful sync */
    public function lastSyncedAt() {
        return $this->_lastSyncedAt;
    }

    protected function _evaluationMode() {
        return 'local';
    }

    public function getVariant($flagKey, FeatureFlags_MixpanelSelectedVariant $fallback, array $context, $reportExposure = null) {
        $reportExposure = $reportExposure === null ? $this->_reportExposureDefault : (bool) $reportExposure;
        $startTime = microtime(true);

        if (!$this->_ready) {
            // Distinguish "definitions never loaded" from "definitions
            // loaded but flag not present" — the audit-driven reason
            // enum is the seam a future OpenFeature wrapper uses.
            $this->_lastFailureReason = self::REASON_NOT_READY;
            $this->_handleError(
                'mixpanel-flags',
                "getVariant called before loadDefinitions() succeeded; call loadDefinitions() first."
            );
            return $fallback;
        }

        if (!isset($this->_definitions[$flagKey])) {
            $this->_lastFailureReason = self::REASON_FLAG_NOT_FOUND;
            return $fallback;
        }

        $flag = $this->_definitions[$flagKey];
        $bucketingKey = isset($flag['context']) ? $flag['context'] : 'distinct_id';
        if (!isset($context[$bucketingKey]) || $context[$bucketingKey] === '' || $context[$bucketingKey] === null) {
            $this->_lastFailureReason = self::REASON_MISSING_CONTEXT_KEY;
            $this->_handleError(
                'mixpanel-flags',
                "Flag '{$flagKey}' requires context key '{$bucketingKey}' which was not supplied"
            );
            return $fallback;
        }
        $contextValue = (string) $context[$bucketingKey];

        // Test-user variant overrides always win, by design.
        $selected = $this->_overrideForTestUser($flag, $context);

        if ($selected === null) {
            $rollout = $this->_assignedRollout($flag, $contextValue, $context);
            if ($rollout !== null) {
                $selected = $this->_assignedVariant($flag, $contextValue, $flagKey, $rollout);
            }
        }

        if ($selected === null) {
            $this->_lastFailureReason = self::REASON_NO_ROLLOUT_MATCH;
            return $fallback;
        }

        $this->_lastFailureReason = self::REASON_OK;

        if ($reportExposure) {
            $latencyMs = (microtime(true) - $startTime) * 1000.0;
            $this->_trackExposure($flagKey, $selected, $context, 'local', $latencyMs);
        }

        return $selected;
    }

    public function getAllVariants(array $context) {
        $out = array();
        foreach ($this->_definitions as $flagKey => $_def) {
            $fallback = new FeatureFlags_MixpanelSelectedVariant(null, null);
            $variant = $this->getVariant($flagKey, $fallback, $context, false);
            if ($variant->variantKey !== null) {
                $out[$flagKey] = $variant;
            }
        }
        return $out;
    }

    private function _overrideForTestUser(array $flag, array $context) {
        if (!isset($flag['ruleset']['test']['users']) || !is_array($flag['ruleset']['test']['users'])) {
            return null;
        }
        if (!isset($context['distinct_id'])) {
            return null;
        }
        $distinctId = (string) $context['distinct_id'];
        $users = $flag['ruleset']['test']['users'];
        if (!isset($users[$distinctId])) {
            return null;
        }
        return $this->_matchingVariant($users[$distinctId], $flag, /* isQaTester */ true);
    }

    private function _matchingVariant($variantKey, array $flag, $isQaTester = false) {
        if (!isset($flag['ruleset']['variants']) || !is_array($flag['ruleset']['variants'])) {
            return null;
        }
        $targetKey = mb_strtolower((string) $variantKey, 'UTF-8');
        foreach ($flag['ruleset']['variants'] as $variant) {
            if (!isset($variant['key'])) {
                continue;
            }
            if (mb_strtolower((string) $variant['key'], 'UTF-8') === $targetKey) {
                return new FeatureFlags_MixpanelSelectedVariant(
                    $variant['key'],
                    isset($variant['value']) ? $variant['value'] : null,
                    isset($flag['experiment_id']) ? $flag['experiment_id'] : null,
                    isset($flag['is_experiment_active']) ? $flag['is_experiment_active'] : null,
                    $isQaTester ? true : null
                );
            }
        }
        return null;
    }

    private function _assignedRollout(array $flag, $contextValue, array $context) {
        if (!isset($flag['ruleset']['rollout']) || !is_array($flag['ruleset']['rollout'])) {
            return null;
        }
        $flagKey = isset($flag['key']) ? $flag['key'] : '';
        $hashSalt = isset($flag['hash_salt']) ? $flag['hash_salt'] : null;

        foreach ($flag['ruleset']['rollout'] as $index => $rollout) {
            if ($hashSalt !== null) {
                $salt = $flagKey . $hashSalt . $index;
            } else {
                $salt = $flagKey . 'rollout';
            }
            $rolloutHash = FeatureFlags_MixpanelFlagsUtils::normalizedHash($contextValue, $salt);

            $rolloutPercentage = isset($rollout['rollout_percentage']) ? (float) $rollout['rollout_percentage'] : 0.0;
            if ($rolloutHash < $rolloutPercentage && $this->_runtimeRulesSatisfied($rollout, $context)) {
                return $rollout;
            }
        }
        return null;
    }

    private function _assignedVariant(array $flag, $contextValue, $flagKey, array $rollout) {
        if (isset($rollout['variant_override']['key'])) {
            $override = $this->_matchingVariant($rollout['variant_override']['key'], $flag);
            if ($override !== null) {
                return $override;
            }
        }

        $hashSalt = isset($flag['hash_salt']) ? $flag['hash_salt'] : '';
        $salt = $flagKey . $hashSalt . 'variant';
        $variantHash = FeatureFlags_MixpanelFlagsUtils::normalizedHash($contextValue, $salt);

        $variants = isset($flag['ruleset']['variants']) ? $flag['ruleset']['variants'] : array();
        // Apply per-rollout split overrides without mutating the cached definition.
        if (isset($rollout['variant_splits']) && is_array($rollout['variant_splits'])) {
            foreach ($variants as $i => $v) {
                if (isset($v['key']) && isset($rollout['variant_splits'][$v['key']])) {
                    $variants[$i]['split'] = $rollout['variant_splits'][$v['key']];
                }
            }
        }

        $selected = isset($variants[0]) ? $variants[0] : null;
        $cumulative = 0.0;
        foreach ($variants as $variant) {
            $selected = $variant;
            $cumulative += isset($variant['split']) ? (float) $variant['split'] : 0.0;
            if ($variantHash < $cumulative) {
                break;
            }
        }

        if ($selected === null) {
            return null;
        }

        return new FeatureFlags_MixpanelSelectedVariant(
            isset($selected['key']) ? $selected['key'] : null,
            isset($selected['value']) ? $selected['value'] : null,
            isset($flag['experiment_id']) ? $flag['experiment_id'] : null,
            isset($flag['is_experiment_active']) ? $flag['is_experiment_active'] : null,
            null
        );
    }

    private function _runtimeRulesSatisfied(array $rollout, array $context) {
        if (isset($rollout['runtime_evaluation_rule']) && $rollout['runtime_evaluation_rule']) {
            $params = $this->_runtimeParameters($context);
            if ($params === null) {
                return false;
            }
            try {
                $rule = FeatureFlags_MixpanelFlagsUtils::lowercaseLeafNodes($rollout['runtime_evaluation_rule']);
                $result = JWadhams\JsonLogic::apply($rule, $params);
                return (bool) $result;
            } catch (Exception $e) {
                $this->_handleError($e->getCode(), 'Runtime rule evaluation error: ' . $e->getMessage());
                return false;
            } catch (Error $e) {
                // PHP 7+ throws Error (not Exception) for some failure modes.
                $this->_handleError($e->getCode(), 'Runtime rule evaluation error: ' . $e->getMessage());
                return false;
            }
        }

        if (isset($rollout['runtime_evaluation_definition']) && is_array($rollout['runtime_evaluation_definition'])) {
            return $this->_legacyRuntimeRuleSatisfied($rollout['runtime_evaluation_definition'], $context);
        }

        return true;
    }

    private function _legacyRuntimeRuleSatisfied(array $definition, array $context) {
        $params = $this->_runtimeParameters($context);
        if ($params === null) {
            return false;
        }
        foreach ($definition as $key => $expectedValue) {
            if (!array_key_exists($key, $params)) {
                return false;
            }
            // Legacy runtime rules only meaningfully compare scalars.
            // Casting an array via (string) yields "Array" and an
            // E_NOTICE — treat non-scalar operands as "no match"
            // instead of producing junk.
            if (!is_scalar($params[$key]) || !is_scalar($expectedValue)) {
                return false;
            }
            $actual = mb_strtolower((string) $params[$key], 'UTF-8');
            $expected = mb_strtolower((string) $expectedValue, 'UTF-8');
            if ($actual !== $expected) {
                return false;
            }
        }
        return true;
    }

    private function _runtimeParameters(array $context) {
        if (!isset($context['custom_properties']) || !is_array($context['custom_properties'])) {
            return null;
        }
        return FeatureFlags_MixpanelFlagsUtils::lowercaseKeysAndValues($context['custom_properties']);
    }
}
