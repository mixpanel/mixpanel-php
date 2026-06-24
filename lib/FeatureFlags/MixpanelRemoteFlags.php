<?php

require_once(dirname(__FILE__) . "/MixpanelFlagsBase.php");

/**
 * Remote feature-flag evaluator. Each call to getVariant() makes a
 * GET to /flags?context=<JSON> on the Mixpanel API. The server runs
 * the full evaluation and returns the selected variant.
 *
 * Compared with local mode, remote eval requires no cached definitions
 * and works correctly even on serverless / short-lived PHP processes
 * where loading definitions for every request would be wasteful.
 */
class FeatureFlags_MixpanelRemoteFlags extends FeatureFlags_MixpanelFlagsBase {

    const FLAGS_PATH = '/flags';

    protected function _evaluationMode() {
        return 'remote';
    }

    public function getVariant($flagKey, FeatureFlags_MixpanelSelectedVariant $fallback, array $context, $reportExposure = true) {
        $reportExposure = (bool) $reportExposure;

        $startTime = microtime(true);
        try {
            $flags = $this->_fetchFlags($context, $flagKey);
        } catch (Exception $e) {
            // Audit finding #7: don't silently swallow backend errors.
            // Surface to error_callback, mark the failure reason, and
            // return fallback so a future OF wrapper can translate to
            // GENERAL instead of FLAG_NOT_FOUND.
            $this->_lastFailureReason = self::REASON_BACKEND_ERROR;
            $this->_handleError($e->getCode(), 'Remote flag fetch failed: ' . $e->getMessage());
            return $fallback;
        }
        $endTime = microtime(true);

        if (!isset($flags[$flagKey])) {
            $this->_lastFailureReason = self::REASON_FLAG_NOT_FOUND;
            return $fallback;
        }

        $selected = FeatureFlags_MixpanelSelectedVariant::fromArray($flags[$flagKey]);
        $this->_lastFailureReason = self::REASON_OK;

        if ($reportExposure) {
            // Pass start/end so the exposure event carries
            // "Variant fetch start time" / "Variant fetch complete
            // time" ISO strings, matching Python/Ruby/Go/Java/Node
            // remote-mode payloads. Latency is derived from the pair.
            $this->_trackExposure($flagKey, $selected, $context, 'remote', null, $startTime, $endTime);
        }

        return $selected;
    }

    public function getAllVariants(array $context) {
        try {
            $flags = $this->_fetchFlags($context, null);
        } catch (Exception $e) {
            $this->_lastFailureReason = self::REASON_BACKEND_ERROR;
            $this->_handleError($e->getCode(), 'Remote flag fetch failed: ' . $e->getMessage());
            return array();
        }

        $out = array();
        foreach ($flags as $key => $payload) {
            $out[$key] = FeatureFlags_MixpanelSelectedVariant::fromArray($payload);
        }
        $this->_lastFailureReason = self::REASON_OK;
        return $out;
    }

    /**
     * @param array $context
     * @param string|null $flagKey when set, the server scopes the response to that one flag
     * @return array map of flag_key => variant payload
     */
    private function _fetchFlags(array $context, $flagKey) {
        $query = array(
            // The Python and Ruby SDKs URL-encode the context JSON
            // before placing it in the query string. http_build_query
            // would do that for us, but we pre-encode to JSON first so
            // the server sees the expected JSON shape.
            'context' => json_encode($context),
        );
        if ($flagKey !== null) {
            $query['flag_key'] = $flagKey;
        }
        $response = $this->_httpGet(self::FLAGS_PATH, $query);
        if (isset($response['flags']) && is_array($response['flags'])) {
            return $response['flags'];
        }
        return array();
    }
}
