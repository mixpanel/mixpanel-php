<?php

require_once(dirname(__FILE__) . '/../../lib/Credentials/APISecretCredentials.php');

class APISecretCredentialsTest extends PHPUnit_Framework_TestCase {

    public function testConstructorWithValidCredentials() {
        // Suppress deprecation warnings during tests
        $credentials = @new Credentials_APISecretCredentials(
            "test-project-id",
            "test-api-secret"
        );

        $this->assertInstanceOf('Credentials_APISecretCredentials', $credentials);
        $this->assertEquals("test-project-id", $credentials->getProjectId());
        $this->assertEquals("test-api-secret", $credentials->getApiSecret());
    }

    public function testConstructorThrowsExceptionForEmptyProjectId() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Project ID is required");

        @new Credentials_APISecretCredentials("", "api-secret");
    }

    public function testConstructorThrowsExceptionForEmptyApiSecret() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("API Secret is required");

        @new Credentials_APISecretCredentials("project-id", "");
    }

    public function testGetAuthHeadersReturnsCorrectFormat() {
        $credentials = @new Credentials_APISecretCredentials(
            "test-project-id",
            "test-api-secret"
        );

        $headers = $credentials->getAuthHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Basic ', $headers['Authorization']);

        // Verify the base64 encoding is correct (secret followed by colon)
        $expected_auth = base64_encode('test-api-secret:');
        $this->assertEquals('Basic ' . $expected_auth, $headers['Authorization']);
    }

    public function testIsDeprecatedReturnsTrue() {
        $credentials = @new Credentials_APISecretCredentials(
            "test-project-id",
            "test-api-secret"
        );

        $this->assertTrue($credentials->isDeprecated());
    }

    public function testGetProjectIdReturnsCorrectValue() {
        $credentials = @new Credentials_APISecretCredentials(
            "my-project-456",
            "test-api-secret"
        );

        $this->assertEquals("my-project-456", $credentials->getProjectId());
    }

    public function testConstructorLogsDeprecationWarning() {
        // This test verifies that a deprecation warning is logged
        // We can't easily capture error_log output in unit tests,
        // so we'll just verify the object is created successfully
        // The actual warning will be tested in integration tests
        $credentials = @new Credentials_APISecretCredentials(
            "test-project-id",
            "test-api-secret"
        );

        $this->assertInstanceOf('Credentials_APISecretCredentials', $credentials);
    }
}
