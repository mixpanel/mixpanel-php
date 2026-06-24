<?php

/**
 * A feature-flag variant after evaluation.
 *
 * Matches the shape used by the Python, Ruby, Go, and Java SDKs so that
 * downstream wrappers (a future OpenFeature provider) and analytics
 * tooling can rely on the same field names across languages.
 *
 * The `experimentId`, `isExperimentActive`, and `isQaTester` fields are
 * kept available so a future OpenFeature wrapper can forward them as
 * `flag_metadata` — addressing finding "Design C" in the cross-SDK
 * audit (other wrappers throw this metadata away).
 *
 * `fallbackReason` is `null` when evaluation succeeded; when the SDK
 * returns the fallback you passed in, it's set to one of the REASON_*
 * constants below so the caller (or a future OpenFeature wrapper) can
 * distinguish flag-not-found from missing-context-key from no-rollout-
 * match etc. — addressing audit finding #1.
 */
class FeatureFlags_MixpanelSelectedVariant {

    const REASON_FLAG_NOT_FOUND      = 'FLAG_NOT_FOUND';
    const REASON_MISSING_CONTEXT_KEY = 'MISSING_CONTEXT_KEY';
    const REASON_NO_ROLLOUT_MATCH    = 'NO_ROLLOUT_MATCH';
    const REASON_BACKEND_ERROR       = 'BACKEND_ERROR';
    const REASON_NOT_READY           = 'NOT_READY';

    /** @var string|null variant key — null when this instance is a fallback */
    public $variantKey;

    /** @var mixed the value the flag resolves to (bool, string, number, array, ...) */
    public $variantValue;

    /** @var string|null */
    public $experimentId;

    /** @var bool|null */
    public $isExperimentActive;

    /** @var bool|null */
    public $isQaTester;

    /** @var string|null null on success; one of the REASON_* constants when the fallback was returned */
    public $fallbackReason;

    public function __construct(
        $variantKey = null,
        $variantValue = null,
        $experimentId = null,
        $isExperimentActive = null,
        $isQaTester = null,
        $fallbackReason = null
    ) {
        $this->variantKey = $variantKey;
        $this->variantValue = $variantValue;
        $this->experimentId = $experimentId;
        $this->isExperimentActive = $isExperimentActive;
        $this->isQaTester = $isQaTester;
        $this->fallbackReason = $fallbackReason;
    }

    /**
     * Build a SelectedVariant from the JSON shape returned by the
     * /flags remote endpoint or stored inside a flag definition.
     *
     * @param array $data
     * @return FeatureFlags_MixpanelSelectedVariant
     */
    public static function fromArray(array $data) {
        return new self(
            isset($data['variant_key']) ? $data['variant_key'] : null,
            isset($data['variant_value']) ? $data['variant_value'] : null,
            isset($data['experiment_id']) ? $data['experiment_id'] : null,
            isset($data['is_experiment_active']) ? $data['is_experiment_active'] : null,
            isset($data['is_qa_tester']) ? $data['is_qa_tester'] : null
        );
    }

    /**
     * Return a copy of this variant with the supplied fallbackReason
     * set. Used by the providers to tag the caller's fallback without
     * mutating their object.
     *
     * @param string $reason one of the REASON_* constants
     * @return FeatureFlags_MixpanelSelectedVariant
     */
    public function withFallbackReason($reason) {
        $clone = clone $this;
        $clone->fallbackReason = $reason;
        return $clone;
    }

    /**
     * @return array
     */
    public function toArray() {
        return array(
            'variant_key'          => $this->variantKey,
            'variant_value'        => $this->variantValue,
            'experiment_id'        => $this->experimentId,
            'is_experiment_active' => $this->isExperimentActive,
            'is_qa_tester'         => $this->isQaTester,
            'fallback_reason'      => $this->fallbackReason,
        );
    }
}
