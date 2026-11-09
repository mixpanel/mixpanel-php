<?php

declare(strict_types=1);

require_once(dirname(__FILE__) . "/MixpanelFlagsBase.php");

/**
 * In-process feature-flag evaluator. Pulls definitions from
 * `/flags/definitions` on demand via loadDefinitions(); evaluates each
 * call against the cached definitions using the same FNV-1a + JSON
 * Logic algorithms as every other Mixpanel SDK.
 *
 * PHP's request-per-process model means we deliberately do NOT run a
 * background polling thread (the Ruby SDK's poller was the source of
 * the daemon-thread bug — audit finding #2). Long-lived CLI workers
 * can call loadDefinitions() on whatever schedule they like.
 */
class FeatureFlags_MixpanelLocalFlags extends FeatureFlags_MixpanelFlagsBase {

    const DEFINITIONS_PATH = '/flags/definitions';

    /** @var array<string, array> map of flag_key => flag definition (decoded JSON) */
    private array $_definitions = array();

    private bool $_ready = false;

    /** Unix timestamp of last successful loadDefinitions. */
    private ?int $_lastSyncedAt = null;

    /**
     * Fetch the latest flag definitions from Mixpanel. Throws nothing
     * on transport failure — the error is routed to error_callback so
     * the existing definitions (if any) keep working until the next
     * successful call. Returns true on success.
     */
    public function loadDefinitions(): bool {
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
                usort($flag['ruleset']['variants'], function ($a, $b) {
                    $ak = isset($a['key']) ? (string) $a['key'] : '';
                    $bk = isset($b['key']) ? (string) $b['key'] : '';
                    return strcmp($ak, $bk);
                });
            }
            $byKey[$flag['key']] = $flag;
        }
        $this->_definitions = $byKey;
        $this->_ready = true;
        $this->_lastSyncedAt = time();
        return true;
    }

    /** True once loadDefinitions has succeeded at least once. */
    public function areFlagsReady(): bool {
        return $this->_ready;
    }

    /** Unix timestamp of most recent successful sync. */
    public function lastSyncedAt(): ?int {
        return $this->_lastSyncedAt;
    }

    protected function _evaluationMode(): string {
        return 'local';
    }

    public function getVariant(
        string $flagKey,
        FeatureFlags_MixpanelSelectedVariant $fallback,
        array $context,
        bool $reportExposure = true
    ): FeatureFlags_MixpanelSelectedVariant {
        $startTime = microtime(true);

        if (!$this->_ready) {
            // Distinguish "definitions never loaded" from "definitions
            // loaded but flag not present" — the per-variant reason is
            // the seam a future OpenFeature wrapper uses.
            $this->_handleError(
                'mixpanel-flags',
                "getVariant called before loadDefinitions() succeeded; call loadDefinitions() first."
            );
            return $fallback->withFallbackReason(FeatureFlags_MixpanelSelectedVariant::REASON_NOT_READY);
        }

        if (!isset($this->_definitions[$flagKey])) {
            return $fallback->withFallbackReason(FeatureFlags_MixpanelSelectedVariant::REASON_FLAG_NOT_FOUND);
        }

        $flag = $this->_definitions[$flagKey];
        $bucketingKey = isset($flag['context']) ? $flag['context'] : 'distinct_id';
        if (!isset($context[$bucketingKey]) || $context[$bucketingKey] === '' || $context[$bucketingKey] === null) {
            $this->_handleError(
                'mixpanel-flags',
                "Flag '{$flagKey}' requires context key '{$bucketingKey}' which was not supplied"
            );
            return $fallback->withFallbackReason(FeatureFlags_MixpanelSelectedVariant::REASON_MISSING_CONTEXT_KEY);
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
            return $fallback->withFallbackReason(FeatureFlags_MixpanelSelectedVariant::REASON_NO_ROLLOUT_MATCH);
        }

        if ($reportExposure) {
            $latencyMs = (microtime(true) - $startTime) * 1000.0;
            $this->_trackExposure($flagKey, $selected, $context, 'local', $latencyMs);
        }

        return $selected->withSource(FeatureFlags_MixpanelSelectedVariant::SOURCE_LOCAL);
    }

    public function getAllVariants(array $context): array {
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

    private function _overrideForTestUser(array $flag, array $context): ?FeatureFlags_MixpanelSelectedVariant {
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

    private function _matchingVariant(string $variantKey, array $flag, bool $isQaTester = false): ?FeatureFlags_MixpanelSelectedVariant {
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

    private function _assignedRollout(array $flag, string $contextValue, array $context): ?array {
        if (!isset($flag['ruleset']['rollout']) || !is_array($flag['ruleset']['rollout'])) {
            return null;
        }
        $flagKey = isset($flag['key']) ? $flag['key'] : '';
        // Default to null (not '') so the branch below can distinguish
        // "flag declared a salt" from "flag has no salt at all" — the two
        // pick completely different salt formulas.
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

    private function _assignedVariant(array $flag, string $contextValue, string $flagKey, array $rollout): ?FeatureFlags_MixpanelSelectedVariant {
        if (isset($rollout['variant_override']['key'])) {
            $override = $this->_matchingVariant($rollout['variant_override']['key'], $flag);
            if ($override !== null) {
                return $override;
            }
        }

        // Default to '' (not null) — this codepath just concatenates and never
        // branches on presence, so the empty string collapses cleanly.
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

    private function _runtimeRulesSatisfied(array $rollout, array $context): bool {
        if (isset($rollout['runtime_evaluation_rule']) && $rollout['runtime_evaluation_rule']) {
            $params = $this->_runtimeParameters($context);
            if ($params === null) {
                // Not an error — the rollout just doesn't match — but log so
                // callers debugging "why did I fall through to REASON_NO_ROLLOUT_MATCH"
                // can see the missing custom_properties is why.
                $this->_handleError(
                    0,
                    'Runtime rule present but custom_properties missing from context; rollout skipped.'
                );
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

    private function _legacyRuntimeRuleSatisfied(array $definition, array $context): bool {
        $params = $this->_runtimeParameters($context);
        if ($params === null) {
            $this->_handleError(
                0,
                'Legacy runtime rule present but custom_properties missing from context; rollout skipped.'
            );
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

    private function _runtimeParameters(array $context): ?array {
        if (!isset($context['custom_properties']) || !is_array($context['custom_properties'])) {
            return null;
        }
        return FeatureFlags_MixpanelFlagsUtils::lowercaseKeysAndValues($context['custom_properties']);
    }
}
