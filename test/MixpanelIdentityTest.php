<?php

class MixpanelIdentityTest extends \PHPUnit\Framework\TestCase {

    /**
     * Runs $call and returns the exception it threw, failing unless it is exactly $class
     */
    private function assertThrows($class, $call) {
        try {
            call_user_func($call);
        } catch (Exception $e) {
            $this->assertEquals($class, get_class($e), $e->getMessage());
            return $e;
        }
        $this->fail("Expected $class");
    }

    private function assertMessageContains($needle, $e) {
        $this->assertTrue(strpos($e->getMessage(), $needle) !== false, $e->getMessage());
    }

    public function testIdentityRejectsEmptyValues() {
        // An empty string is a caller error; null simply leaves the value unset.
        foreach (array("distinct_id", "user_id", "company_id") as $key) {
            $this->assertThrows("InvalidArgumentException", function () use ($key) {
                new MixpanelIdentity(array($key => ""));
            });
        }
    }

    public function testIdentityNeedsAtLeastOneValue() {
        $this->assertThrows("InvalidArgumentException", function () {
            new MixpanelIdentity(array());
        });
        $this->assertThrows("InvalidArgumentException", function () {
            new MixpanelIdentity(array("user_id" => null, "company_id" => null));
        });
    }

    public function testIdentityRejectsUnknownKeysAndNonScalarValues() {
        $this->assertThrows("InvalidArgumentException", function () {
            new MixpanelIdentity(array('$user_id' => "user_123"));
        });
        $this->assertThrows("InvalidArgumentException", function () {
            new MixpanelIdentity(array("user_id" => array("user_123")));
        });
        $this->assertThrows("InvalidArgumentException", function () {
            new MixpanelIdentity("user_123");
        });
        $e = $this->assertThrows("InvalidArgumentException", function () {
            new MixpanelIdentity(array("user_id" => "user_123", "device_id" => "device_abc"));
        });
        $this->assertMessageContains("Unknown MixpanelIdentity key 'device_id'", $e);
    }

    public function testNullLeavesAValueUnset() {
        $identity = new MixpanelIdentity(array("user_id" => "user_123", "company_id" => null));

        $this->assertEquals("user_123", $identity->getUserId());
        $this->assertNull($identity->getCompanyId());
        $this->assertEquals(array('$user_id' => "user_123"), $identity->toProperties());
        $this->assertFalse($identity->isHierarchical());
    }

    public function testIdentityStringNamesItsValues() {
        $this->assertEquals("MixpanelIdentity{user_id=user_123, company_id=acme}", (string) new MixpanelIdentity(array("user_id" => "user_123", "company_id" => "acme")));
        $this->assertTrue((new MixpanelIdentity(array("user_id" => "user_123", "company_id" => "acme")))->isHierarchical());
    }

    public function testIdentityRejectsWhitespaceOnlyUserAndCompany() {
        foreach (array("user_id", "company_id") as $key) {
            foreach (array(" ", "  ", "\t\n") as $blank) {
                $e = $this->assertThrows("InvalidArgumentException", function () use ($key, $blank) {
                    new MixpanelIdentity(array($key => $blank));
                });
                $this->assertMessageContains("$key must not be only whitespace", $e);
            }
        }
        // other ids are not trimmed or otherwise changed
        $identity = new MixpanelIdentity(array("user_id" => " user_123 ", "company_id" => "acme "));
        $this->assertSame(" user_123 ", $identity->getUserId());
        $this->assertSame("acme ", $identity->getCompanyId());
    }
}
