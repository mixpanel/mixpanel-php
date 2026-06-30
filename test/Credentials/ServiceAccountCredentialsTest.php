<?php

require_once(dirname(__FILE__) . '/../../lib/Credentials/ServiceAccountCredentials.php');

class ServiceAccountCredentialsTest extends PHPUnit_Framework_TestCase {

    public function testConstructorWithValidCredentials() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "test-project-id",
            "test-username",
            "test-secret"
        );

        $this->assertInstanceOf('Credentials_ServiceAccountCredentials', $credentials);
        $this->assertEquals("test-project-id", $credentials->getProjectId());
        $this->assertEquals("test-username", $credentials->getUsername());
    }

    public function testConstructorThrowsExceptionForEmptyProjectId() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Project ID is required");

        new Credentials_ServiceAccountCredentials("", "username", "secret");
    }

    public function testConstructorThrowsExceptionForEmptyUsername() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Username is required");

        new Credentials_ServiceAccountCredentials("project-id", "", "secret");
    }

    public function testConstructorThrowsExceptionForEmptySecret() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Secret is required");

        new Credentials_ServiceAccountCredentials("project-id", "username", "");
    }

    public function testGetAuthHeadersReturnsCorrectFormat() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "test-project-id",
            "test-username",
            "test-secret"
        );

        $headers = $credentials->getAuthHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Basic ', $headers['Authorization']);

        // Verify the base64 encoding is correct
        $expected_auth = base64_encode('test-username:test-secret');
        $this->assertEquals('Basic ' . $expected_auth, $headers['Authorization']);
    }

    public function testIsDeprecatedReturnsFalse() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "test-project-id",
            "test-username",
            "test-secret"
        );

        $this->assertFalse($credentials->isDeprecated());
    }

    public function testGetProjectIdReturnsCorrectValue() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "my-project-123",
            "test-username",
            "test-secret"
        );

        $this->assertEquals("my-project-123", $credentials->getProjectId());
    }
}
