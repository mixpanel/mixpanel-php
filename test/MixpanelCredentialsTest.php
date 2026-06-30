<?php

require_once(dirname(__FILE__) . '/../lib/Mixpanel.php');

class MixpanelCredentialsTest extends PHPUnit_Framework_TestCase {

    protected function tearDown() {
        parent::tearDown();
        // Clear all instances to avoid test pollution
        $reflection = new ReflectionClass('Mixpanel');
        $property = $reflection->getProperty('_instances');
        $property->setAccessible(true);
        $property->setValue(array());
    }

    public function testConstructorWithServiceAccountCredentials() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "test-project",
            "test-user",
            "test-secret"
        );

        // Suppress deprecation warnings during instantiation
        $mp = @new Mixpanel($credentials);

        $this->assertInstanceOf('Mixpanel', $mp);
        $this->assertInstanceOf('Producers_MixpanelPeople', $mp->people);
    }

    public function testConstructorWithAPISecretCredentials() {
        $credentials = @new Credentials_APISecretCredentials(
            "test-project",
            "test-secret"
        );

        $mp = @new Mixpanel($credentials);

        $this->assertInstanceOf('Mixpanel', $mp);
        $this->assertInstanceOf('Producers_MixpanelPeople', $mp->people);
    }

    public function testConstructorWithLegacyToken() {
        // Legacy token-based authentication should still work but log deprecation
        $mp = @new Mixpanel("test-token");

        $this->assertInstanceOf('Mixpanel', $mp);
        $this->assertInstanceOf('Producers_MixpanelPeople', $mp->people);
    }

    public function testGetInstanceWithServiceAccountCredentials() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "test-project",
            "test-user",
            "test-secret"
        );

        $mp1 = @Mixpanel::getInstance($credentials);
        $mp2 = @Mixpanel::getInstance($credentials);

        // Should return the same instance for the same project
        $this->assertSame($mp1, $mp2);
    }

    public function testGetInstanceWithLegacyToken() {
        $mp1 = @Mixpanel::getInstance("token1");
        $mp2 = @Mixpanel::getInstance("token1");
        $mp3 = @Mixpanel::getInstance("token2");

        // Same token should return same instance
        $this->assertSame($mp1, $mp2);

        // Different token should return different instance
        $this->assertNotSame($mp1, $mp3);
    }

    public function testGetInstanceWithDifferentProjects() {
        $credentials1 = new Credentials_ServiceAccountCredentials(
            "project-1",
            "user",
            "secret"
        );

        $credentials2 = new Credentials_ServiceAccountCredentials(
            "project-2",
            "user",
            "secret"
        );

        $mp1 = @Mixpanel::getInstance($credentials1);
        $mp2 = @Mixpanel::getInstance($credentials2);

        // Different projects should return different instances
        $this->assertNotSame($mp1, $mp2);
    }

    public function testCredentialsArePassedToProducers() {
        $credentials = new Credentials_ServiceAccountCredentials(
            "test-project",
            "test-user",
            "test-secret"
        );

        $mp = @new Mixpanel($credentials);

        // Access the protected _events property to check credentials
        $reflection = new ReflectionClass('Mixpanel');
        $eventsProperty = $reflection->getProperty('_events');
        $eventsProperty->setAccessible(true);
        $events = $eventsProperty->getValue($mp);

        // Check that credentials are accessible through the producer
        $producerReflection = new ReflectionClass('Producers_MixpanelBaseProducer');
        $credentialsProperty = $producerReflection->getProperty('_credentials');
        $credentialsProperty->setAccessible(true);
        $producerCredentials = $credentialsProperty->getValue($events);

        $this->assertSame($credentials, $producerCredentials);
    }
}
