<?php

require_once(dirname(__FILE__) . "/MixpanelCredentials.php");

/**
 * Service Account credentials for secure server-to-server integration with Mixpanel.
 * This is the recommended authentication method for server-side applications.
 *
 * Service accounts provide enhanced security over API secrets and support
 * fine-grained access control.
 *
 * Example usage:
 * <code>
 * $credentials = new Credentials_ServiceAccountCredentials(
 *     "your-project-id",
 *     "your-service-account-username",
 *     "your-service-account-secret"
 * );
 * $mp = new Mixpanel($credentials);
 * </code>
 */
class Credentials_ServiceAccountCredentials implements Credentials_MixpanelCredentials {

    /**
     * @var string The Mixpanel project ID
     */
    private $_project_id;

    /**
     * @var string The service account username
     */
    private $_username;

    /**
     * @var string The service account secret
     */
    private $_secret;

    /**
     * Creates a new ServiceAccountCredentials instance
     * @param string $project_id The Mixpanel project ID
     * @param string $username The service account username
     * @param string $secret The service account secret
     * @throws Exception if any parameter is empty
     */
    public function __construct($project_id, $username, $secret) {
        if (empty($project_id)) {
            throw new Exception("Project ID is required for Service Account credentials");
        }
        if (empty($username)) {
            throw new Exception("Username is required for Service Account credentials");
        }
        if (empty($secret)) {
            throw new Exception("Secret is required for Service Account credentials");
        }

        $this->_project_id = $project_id;
        $this->_username = $username;
        $this->_secret = $secret;
    }

    /**
     * Get the authentication headers for Service Account authentication
     * @return array Associative array of header name => header value
     */
    public function getAuthHeaders() {
        $auth = base64_encode($this->_username . ':' . $this->_secret);
        return array(
            'Authorization' => 'Basic ' . $auth
        );
    }

    /**
     * Service Account credentials are not deprecated
     * @return bool False - this is the current recommended method
     */
    public function isDeprecated() {
        return false;
    }

    /**
     * Get the project ID
     * @return string The project ID
     */
    public function getProjectId() {
        return $this->_project_id;
    }

    /**
     * Get the service account username
     * @return string The username
     */
    public function getUsername() {
        return $this->_username;
    }
}
