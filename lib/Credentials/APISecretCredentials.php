<?php

require_once(dirname(__FILE__) . "/MixpanelCredentials.php");

/**
 * API Secret credentials for Mixpanel authentication.
 *
 * @deprecated This authentication method is deprecated. Use ServiceAccountCredentials instead.
 * API secrets will be phased out in favor of Service Accounts which provide better
 * security and access control.
 *
 * For migration guidance, see: https://docs.mixpanel.com/docs/tracking-methods/choosing-the-right-method
 *
 * Example usage (deprecated):
 * <code>
 * $credentials = new Credentials_APISecretCredentials(
 *     "your-project-id",
 *     "your-api-secret"
 * );
 * $mp = new Mixpanel($credentials);
 * </code>
 */
class Credentials_APISecretCredentials implements Credentials_MixpanelCredentials {

    /**
     * @var string The Mixpanel project ID
     */
    private $_project_id;

    /**
     * @var string The API secret
     */
    private $_api_secret;

    /**
     * Creates a new APISecretCredentials instance
     * @param string $project_id The Mixpanel project ID
     * @param string $api_secret The API secret
     * @throws Exception if any parameter is empty
     */
    public function __construct($project_id, $api_secret) {
        if (empty($project_id)) {
            throw new Exception("Project ID is required for API Secret credentials");
        }
        if (empty($api_secret)) {
            throw new Exception("API Secret is required");
        }

        $this->_project_id = $project_id;
        $this->_api_secret = $api_secret;

        // Log deprecation warning
        if (function_exists('error_log')) {
            error_log(
                'DEPRECATION WARNING: APISecretCredentials is deprecated and will be removed in a future version. ' .
                'Please migrate to ServiceAccountCredentials for better security. ' .
                'See: https://docs.mixpanel.com/docs/tracking-methods/choosing-the-right-method'
            );
        }
    }

    /**
     * Get the authentication headers for API Secret authentication
     * @return array Associative array of header name => header value
     */
    public function getAuthHeaders() {
        $auth = base64_encode($this->_api_secret . ':');
        return array(
            'Authorization' => 'Basic ' . $auth
        );
    }

    /**
     * API Secret credentials are deprecated
     * @return bool True - this method is deprecated
     */
    public function isDeprecated() {
        return true;
    }

    /**
     * Get the project ID
     * @return string The project ID
     */
    public function getProjectId() {
        return $this->_project_id;
    }

    /**
     * Get the API secret
     * @return string The API secret
     */
    public function getApiSecret() {
        return $this->_api_secret;
    }
}
