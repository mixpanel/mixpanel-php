<?php

/**
 * Hash determinism tests. The canonical FNV-1a 64 reference vectors
 * below verify that PHP's built-in `hash('fnv1a64', …)` (which we use
 * for bucketing) returns the same values every other Mixpanel SDK
 * computes via its own FNV-1a implementation. If this ever drifts, the
 * same user would land in different rollout buckets across languages.
 */
class MixpanelFlagsUtilsTest extends PHPUnit\Framework\TestCase {

    public function testBuiltinFnv1a64MatchesCanonicalVectors() {
        // FNV offset basis: the hash of the empty string.
        $this->assertSame('cbf29ce484222325', hash('fnv1a64', ''));
        // 0xaf63dc4c8601ec8c — canonical reference value for "a".
        $this->assertSame('af63dc4c8601ec8c', hash('fnv1a64', 'a'));
        // 0x85944171f73967e8 — canonical reference value for "foobar".
        $this->assertSame('85944171f73967e8', hash('fnv1a64', 'foobar'));
    }

    public function testNormalizedHashInRange() {
        $val = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'flag-key' . 'rollout');
        $this->assertGreaterThanOrEqual(0.0, $val);
        $this->assertLessThan(1.0, $val);
    }

    public function testNormalizedHashIsDeterministic() {
        $a = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'flag-key' . 'rollout');
        $b = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'flag-key' . 'rollout');
        $this->assertSame($a, $b);
    }

    public function testNormalizedHashDiffersByKey() {
        $a = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-123', 'salt');
        $b = FeatureFlags_MixpanelFlagsUtils::normalizedHash('user-124', 'salt');
        $this->assertNotEquals($a, $b);
    }

    public function testTraceparentShape() {
        $tp = FeatureFlags_MixpanelFlagsUtils::generateTraceparent();
        // Avoid assertMatchesRegularExpression so the suite runs across
        // PHPUnit 7.5–9.x without renaming the assertion.
        $this->assertEquals(1, preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $tp));
    }

    public function testCommonQueryParams() {
        $params = FeatureFlags_MixpanelFlagsUtils::commonQueryParams('TKN', '2.11.0');
        $this->assertEquals('php', $params['mp_lib']);
        $this->assertEquals('2.11.0', $params['lib_version']);
        $this->assertEquals('TKN', $params['token']);
    }

    public function testLowercaseLeafNodes() {
        $rule = array('==' => array(array('var' => 'Email'), 'Alice@Example.COM'));
        $out = FeatureFlags_MixpanelFlagsUtils::lowercaseLeafNodes($rule);
        // Operator/"var" keys are preserved; only the leaf string values
        // (the property name fetched by "var" and the literal compared
        // against) get casefolded.
        $this->assertArrayHasKey('==', $out);
        $this->assertEquals('email', $out['=='][0]['var']);
        $this->assertEquals('alice@example.com', $out['=='][1]);
    }

    public function testLowercaseKeysAndValues() {
        $data = array('Email' => 'Alice@Example.COM', 'Count' => 5);
        $out = FeatureFlags_MixpanelFlagsUtils::lowercaseKeysAndValues($data);
        $this->assertArrayHasKey('email', $out);
        $this->assertEquals('alice@example.com', $out['email']);
        // Non-string values pass through unchanged.
        $this->assertSame(5, $out['count']);
    }

    /**
     * Locks the invariant documented on {@link FeatureFlags_MixpanelFlagsUtils::lowercaseLeafNodes}:
     * the rule side ({@code lowercaseLeafNodes}) and the parameter side
     * ({@code lowercaseKeysAndValues}) must resolve the same var name to
     * the same casefolded key. If someone changes one function without the
     * other, JSON-Logic's `var` lookup silently misses and every
     * runtime-rule flag falls back — this test would catch that.
     */
    public function testLeafNodesAndKeysAndValuesUseCompatibleCasefolding() {
        $rule = array('==' => array(array('var' => 'Email'), 'Alice@Example.COM'));
        $params = array('Email' => 'Alice@Example.COM');

        $normalizedRule = FeatureFlags_MixpanelFlagsUtils::lowercaseLeafNodes($rule);
        $normalizedParams = FeatureFlags_MixpanelFlagsUtils::lowercaseKeysAndValues($params);

        $varName = $normalizedRule['=='][0]['var'];
        $this->assertArrayHasKey($varName, $normalizedParams);
        $this->assertSame($normalizedRule['=='][1], $normalizedParams[$varName]);
    }
}
