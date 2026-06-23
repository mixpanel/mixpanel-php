<?php

require_once(dirname(__FILE__) . "/../Base/MixpanelBase.php");
require_once(dirname(__FILE__) . "/MixpanelFlagsUtils.php");
require_once(dirname(__FILE__) . "/MixpanelSelectedVariant.php");

/**
 * Sentinel return codes for the most recent evaluation attempt. The
 * facade exposes these via lastFailureReason() so callers (including a
 * future OpenFeature wrapper) can distinguish a missing flag from a
 * missing context attribute from a no-match rollout. This addresses
 * finding #1 in the SDK audit — every existing SDK collapses all three
 * cases to "flag not found", which sends customers debugging the wrong
 * thing.
 */
abstract class FeatureFlags_MixpanelFlagsBase extends Base_MixpanelBase {

    const REASON_OK                  = 'OK';
    const REASON_FLAG_NOT_FOUND      = 'FLAG_NOT_FOUND';
    const REASON_MISSING_CONTEXT_KEY = 'MISSING_CONTEXT_KEY';
    const REASON_NO_ROLLOUT_MATCH    = 'NO_ROLLOUT_MATCH';
    const REASON_BACKEND_ERROR       = 'BACKEND_ERROR';
    // Local-only: getVariant called before loadDefinitions() completed
    // successfully. Distinguishes "we haven't fetched yet" from
    // "fetched but this flag isn't defined".
    const REASON_NOT_READY           = 'NOT_READY';

    /** @var string */
    protected $_token;

    /** @var string */
    protected $_version;

    /** @var callable a closure that calls $mp->track($eventName, $properties + ['distinct_id' => $distinctId]) */
    protected $_tracker;

    /** @var string */
    protected $_apiHost;

    /** @var int seconds */
    protected $_requestTimeout;

    /** @var int seconds */
    protected $_connectTimeout;

    /** @var bool */
    protected $_reportExposureDefault;

    /** @var string most recent evaluation outcome */
    protected $_lastFailureReason = self::REASON_OK;

    public function __construct($token, $version, $tracker, array $options) {
        parent::__construct($options);
        $this->_token = $token;
        $this->_version = $version;
        $this->_tracker = $tracker;

        $flagsOpts = isset($options['flags']) && is_array($options['flags']) ? $options['flags'] : array();
        // Precedence: flags.api_host (explicit override) > top-level
        // `host` (shared with the event/people consumers) > default.
        // This means EU/India endpoints or local mocks only need to be
        // configured once at the SDK level.
        if (isset($flagsOpts['api_host'])) {
            $this->_apiHost = $flagsOpts['api_host'];
        } elseif (isset($options['host'])) {
            $this->_apiHost = $options['host'];
        } else {
            $this->_apiHost = 'api.mixpanel.com';
        }
        $this->_requestTimeout = isset($flagsOpts['request_timeout_in_seconds']) ? (int) $flagsOpts['request_timeout_in_seconds'] : 10;
        $this->_connectTimeout = isset($flagsOpts['connect_timeout_in_seconds']) ? (int) $flagsOpts['connect_timeout_in_seconds'] : 5;
        $this->_reportExposureDefault = isset($flagsOpts['report_exposure']) ? (bool) $flagsOpts['report_exposure'] : true;
    }

    /** @return string one of the REASON_* constants */
    public function lastFailureReason() {
        return $this->_lastFailureReason;
    }

    /** Release any held resources. Subclasses override to close cURL handles. */
    public function shutdown() {
        // default: nothing held
    }

    /**
     * Perform an authenticated GET against the flags API and return the
     * decoded JSON body. Throws on HTTP error or transport failure —
     * the remote provider catches this and surfaces the failure to the
     * caller (audit finding #7: don't silently swallow backend errors).
     *
     * @param string $path e.g. "/flags" or "/flags/definitions"
     * @param array $query  query params (merged with token / mp_lib / lib_version)
     * @return array  decoded JSON
     * @throws Exception on HTTP non-2xx or cURL transport error
     */
    protected function _httpGet($path, array $query = array()) {
        $params = array_merge(
            FeatureFlags_MixpanelFlagsUtils::commonQueryParams($this->_token, $this->_version),
            $query
        );
        $url = 'https://' . $this->_apiHost . $path . '?' . http_build_query($params);

        $headers = array(
            'Content-Type: application/json',
            'X-Scheme: https',
            'X-Forwarded-Proto: https',
            'traceparent: ' . FeatureFlags_MixpanelFlagsUtils::generateTraceparent(),
            // Basic Auth with token as username, empty password.
            'Authorization: Basic ' . base64_encode($this->_token . ':'),
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->_connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->_requestTimeout);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Don't call curl_close($ch): PHP 8.0+ closes on unset and 8.5
        // marks the explicit call as deprecated.
        unset($ch);

        if ($errno !== 0) {
            // Carry the cURL errno as the exception code so it reaches
            // the user's error_callback intact.
            throw new Exception('Mixpanel flags HTTP transport error (' . $errno . '): ' . $errmsg, $errno);
        }
        if ($status < 200 || $status >= 300) {
            throw new Exception(
                'Mixpanel flags HTTP ' . $status . ': ' . substr((string) $body, 0, 500),
                (int) $status
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new Exception('Mixpanel flags response was not valid JSON: ' . substr((string) $body, 0, 200));
        }
        return $decoded;
    }

    /**
     * Build the standard $experiment_started property set.
     *
     * Local mode supplies $latencyMs (derived from microtime around the
     * in-process eval); remote mode supplies $startTime / $endTime
     * (microtime floats around the HTTP call) and we derive latency
     * here and emit ISO-8601 "Variant fetch start time" / "complete
     * time" strings to match the Python, Ruby, Go, Java, Node, and
     * Browser SDKs in remote mode.
     *
     * @param string $flagKey
     * @param FeatureFlags_MixpanelSelectedVariant $variant
     * @param string $evaluationMode 'local' or 'remote'
     * @param float|null $latencyMs   when supplied directly (local mode)
     * @param float|null $startTime   microtime(true) before the HTTP call (remote mode)
     * @param float|null $endTime     microtime(true) after the HTTP call (remote mode)
     * @return array
     */
    protected function _buildExposureProperties(
        $flagKey,
        FeatureFlags_MixpanelSelectedVariant $variant,
        $evaluationMode,
        $latencyMs = null,
        $startTime = null,
        $endTime = null
    ) {
        $properties = array(
            'Experiment name'        => $flagKey,
            'Variant name'           => $variant->variantKey,
            '$experiment_type'       => 'feature_flag',
            'Flag evaluation mode'   => $evaluationMode,
            '$experiment_id'         => $variant->experimentId,
            '$is_experiment_active'  => $variant->isExperimentActive,
            '$is_qa_tester'          => $variant->isQaTester,
        );
        if ($startTime !== null && $endTime !== null) {
            $properties['Variant fetch start time']    = self::_formatIsoMicrotime($startTime);
            $properties['Variant fetch complete time'] = self::_formatIsoMicrotime($endTime);
            if ($latencyMs === null) {
                $latencyMs = ($endTime - $startTime) * 1000.0;
            }
        }
        if ($latencyMs !== null) {
            $properties['Variant fetch latency (ms)'] = $latencyMs;
        }
        return $properties;
    }

    /**
     * Format a microtime(true) float as a local-time ISO-8601 string
     * with microsecond precision, matching Python's
     * `datetime.now().isoformat()` output shape so cross-SDK analytics
     * keyed on these properties parse consistently.
     */
    private static function _formatIsoMicrotime($microtime) {
        $seconds = (int) floor($microtime);
        $micros  = (int) round(($microtime - $seconds) * 1000000);
        if ($micros >= 1000000) {
            // round-up edge case at the second boundary
            $seconds += 1;
            $micros   = 0;
        }
        return date('Y-m-d\TH:i:s', $seconds) . '.' . sprintf('%06d', $micros);
    }

    /**
     * Dispatch the exposure event. Routes through the configured
     * tracker (normally Mixpanel::track), which means the event lands
     * in the same buffered queue as every other event — flushed at
     * end of request. That sidesteps audit finding #3 (synchronous
     * exposure blocks eval) on PHP without us needing a separate
     * async path.
     *
     * @param string $flagKey
     * @param FeatureFlags_MixpanelSelectedVariant $variant
     * @param array $context
     * @param string $evaluationMode
     * @param float|null $latencyMs
     */
    protected function _trackExposure(
        $flagKey,
        FeatureFlags_MixpanelSelectedVariant $variant,
        array $context,
        $evaluationMode,
        $latencyMs = null,
        $startTime = null,
        $endTime = null
    ) {
        if (!isset($context['distinct_id']) || $context['distinct_id'] === '' || $context['distinct_id'] === null) {
            // Don't drop silently — surface to the error_callback so the
            // caller learns why their exposure analytics are empty
            // (audit finding #8).
            $this->_handleError(
                'mixpanel-flags',
                "Cannot track exposure for flag '{$flagKey}': distinct_id missing from context"
            );
            return;
        }
        $distinctId = $context['distinct_id'];
        $properties = $this->_buildExposureProperties(
            $flagKey, $variant, $evaluationMode, $latencyMs, $startTime, $endTime
        );

        try {
            call_user_func($this->_tracker, $distinctId, FeatureFlags_MixpanelFlagsUtils::EXPOSURE_EVENT, $properties);
        } catch (Exception $e) {
            $this->_handleError($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Forward an error to the user-supplied error_callback if one was
     * configured (matches the existing AbstractConsumer convention).
     *
     * @param mixed $code
     * @param string $message
     */
    protected function _handleError($code, $message) {
        if (isset($this->_options['error_callback']) && is_callable($this->_options['error_callback'])) {
            call_user_func($this->_options['error_callback'], $code, $message);
        } elseif ($this->_debug()) {
            $this->_log('[flags] ' . $message);
        }
    }

    abstract public function getVariant($flagKey, FeatureFlags_MixpanelSelectedVariant $fallback, array $context, $reportExposure = null);

    public function getVariantValue($flagKey, $fallbackValue, array $context) {
        $fallback = new FeatureFlags_MixpanelSelectedVariant(null, $fallbackValue);
        $variant = $this->getVariant($flagKey, $fallback, $context);
        return $variant->variantValue;
    }

    public function isEnabled($flagKey, array $context) {
        return $this->getVariantValue($flagKey, false, $context) === true;
    }

    /**
     * Manually track exposure for a previously evaluated variant. Used
     * with getAllVariants() so callers can record exposure only for the
     * flags they actually consume.
     *
     * @param string $flagKey
     * @param FeatureFlags_MixpanelSelectedVariant $variant
     * @param array $context
     */
    public function trackExposure($flagKey, FeatureFlags_MixpanelSelectedVariant $variant, array $context) {
        $mode = $this->_evaluationMode();
        $this->_trackExposure($flagKey, $variant, $context, $mode);
    }

    /** @return string */
    abstract protected function _evaluationMode();
}
