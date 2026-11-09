<?php

declare(strict_types=1);

/**
 * A feature-flag variant after evaluation.
 *
 * Matches the shape used by the Python, Ruby, Go, Java, and Node SDKs so
 * that downstream wrappers (the OpenFeature provider) and analytics tooling
 * can rely on the same field names across languages.
 *
 * The `experimentId`, `isExperimentActive`, and `isQaTester` fields are
 * kept available so a future OpenFeature wrapper can forward them as
 * `flag_metadata` — addressing finding "Design C" in the cross-SDK audit
 * (other wrappers throw this metadata away).
 *
 * Two fields describe the result's provenance:
 * - `variantSource` is always set: `local` (local rule evaluation),
 *   `remote` (server-side /flags response), or `fallback` (developer
 *   fallback returned because the SDK had no value to serve).
 * - `fallbackReason` is `null` on success; when `variantSource === 'fallback'`
 *   it's set to one of the REASON_* constants below so the OpenFeature
 *   wrapper can map each reason to the spec-correct error code instead of
 *   collapsing every fallback to FLAG_NOT_FOUND (audit finding #1).
 */
class FeatureFlags_MixpanelSelectedVariant {

    const SOURCE_LOCAL    = 'local';
    const SOURCE_REMOTE   = 'remote';
    const SOURCE_FALLBACK = 'fallback';

    const REASON_FLAG_NOT_FOUND      = 'FLAG_NOT_FOUND';
    const REASON_MISSING_CONTEXT_KEY = 'MISSING_CONTEXT_KEY';
    const REASON_NO_ROLLOUT_MATCH    = 'NO_ROLLOUT_MATCH';
    const REASON_BACKEND_ERROR       = 'BACKEND_ERROR';
    const REASON_NOT_READY           = 'NOT_READY';

    /** Variant key — null when this instance is a fallback. */
    public ?string $variantKey;

    /** The value the flag resolves to. Genuinely arbitrary — bool, string, number, array, object, or null. */
    public mixed $variantValue;

    public ?string $experimentId;

    public ?bool $isExperimentActive;

    public ?bool $isQaTester;

    /** One of SOURCE_*; set by the providers on every returned variant. */
    public ?string $variantSource;

    /** Null on success; one of the REASON_* constants when variantSource === SOURCE_FALLBACK. */
    public ?string $fallbackReason;

    public function __construct(
        ?string $variantKey = null,
        mixed $variantValue = null,
        ?string $experimentId = null,
        ?bool $isExperimentActive = null,
        ?bool $isQaTester = null,
        ?string $fallbackReason = null,
        ?string $variantSource = null
    ) {
        $this->variantKey = $variantKey;
        $this->variantValue = $variantValue;
        $this->experimentId = $experimentId;
        $this->isExperimentActive = $isExperimentActive;
        $this->isQaTester = $isQaTester;
        $this->fallbackReason = $fallbackReason;
        $this->variantSource = $variantSource;
    }

    /**
     * Build a SelectedVariant from the JSON shape returned by the
     * /flags remote endpoint or stored inside a flag definition.
     */
    public static function fromArray(array $data): self {
        return new self(
            isset($data['variant_key']) ? (string) $data['variant_key'] : null,
            isset($data['variant_value']) ? $data['variant_value'] : null,
            isset($data['experiment_id']) ? (string) $data['experiment_id'] : null,
            isset($data['is_experiment_active']) ? (bool) $data['is_experiment_active'] : null,
            isset($data['is_qa_tester']) ? (bool) $data['is_qa_tester'] : null
        );
    }

    /**
     * Return a copy of this variant with the given source. Clears
     * fallbackReason — use {@link withFallbackReason} when returning a
     * fallback.
     *
     * @param string $source one of the SOURCE_* constants
     */
    public function withSource(string $source): self {
        $clone = clone $this;
        $clone->variantSource = $source;
        $clone->fallbackReason = null;
        return $clone;
    }

    /**
     * Return a copy of this variant tagged as a fallback with the given
     * reason. Sets `variantSource` to SOURCE_FALLBACK and `fallbackReason`
     * to the supplied REASON_* constant. Used by the providers to tag the
     * caller's fallback without mutating their object.
     *
     * @param string $reason one of the REASON_* constants
     */
    public function withFallbackReason(string $reason): self {
        $clone = clone $this;
        $clone->variantSource = self::SOURCE_FALLBACK;
        $clone->fallbackReason = $reason;
        return $clone;
    }

    public function toArray(): array {
        return array(
            'variant_key'          => $this->variantKey,
            'variant_value'        => $this->variantValue,
            'experiment_id'        => $this->experimentId,
            'is_experiment_active' => $this->isExperimentActive,
            'is_qa_tester'         => $this->isQaTester,
            'variant_source'       => $this->variantSource,
            'fallback_reason'      => $this->fallbackReason,
        );
    }
}
