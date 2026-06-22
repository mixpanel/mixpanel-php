<?php

/**
 * A feature-flag variant after evaluation.
 *
 * Matches the shape used by the Python, Ruby, Go, and Java SDKs so that
 * downstream wrappers (a future OpenFeature provider) and analytics
 * tooling can rely on the same field names across languages.
 *
 * The `experiment_id`, `is_experiment_active`, and `is_qa_tester`
 * fields are kept available so a future OpenFeature wrapper can
 * forward them as `flag_metadata` — addressing finding "Design C" in
 * the audit (other wrappers throw this metadata away).
 */
class FeatureFlags_MixpanelSelectedVariant {

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

    public function __construct(
        $variantKey = null,
        $variantValue = null,
        $experimentId = null,
        $isExperimentActive = null,
        $isQaTester = null
    ) {
        $this->variantKey = $variantKey;
        $this->variantValue = $variantValue;
        $this->experimentId = $experimentId;
        $this->isExperimentActive = $isExperimentActive;
        $this->isQaTester = $isQaTester;
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
     * @return array
     */
    public function toArray() {
        return array(
            'variant_key'          => $this->variantKey,
            'variant_value'        => $this->variantValue,
            'experiment_id'        => $this->experimentId,
            'is_experiment_active' => $this->isExperimentActive,
            'is_qa_tester'         => $this->isQaTester,
        );
    }
}
