<?php

class HierarchicalGroupsTest extends PHPUnit_Framework_TestCase {

    /**
     * @var Mixpanel
     */
    protected $_hg = null;

    /**
     * @var Mixpanel
     */
    protected $_legacy = null;

    /**
     * @var Producers_MixpanelEvents
     */
    protected $_hg_events = null;

    /**
     * @var Producers_MixpanelEvents
     */
    protected $_legacy_events = null;

    protected function setUp() {
        parent::setUp();
        $this->_hg = new Mixpanel("token", array("hierarchical_groups" => true));
        $this->_legacy = new Mixpanel("token");
        $this->_hg_events = new Producers_MixpanelEvents("token", array("hierarchical_groups" => true));
        $this->_legacy_events = new Producers_MixpanelEvents("token");
    }

    protected function tearDown() {
        parent::tearDown();
        foreach (array($this->_hg, $this->_legacy) as $mp) {
            $mp->reset();
            $mp->people->reset();
            $mp->group->reset();
        }
        $this->_hg_events->reset();
        $this->_legacy_events->reset();
        $this->_hg = $this->_legacy = $this->_hg_events = $this->_legacy_events = null;
    }

    private static function identity($ids) {
        return new MixpanelIdentity($ids);
    }

    private static function hgUser() {
        return new MixpanelIdentity(array("user_id" => "user_123", "company_id" => "acme"));
    }

    private static function lastMessage($producer) {
        $queue = $producer->getQueue();
        return $queue[count($queue) - 1];
    }

    private static function lastEventProperties($events) {
        $msg = self::lastMessage($events);
        return $msg['properties'];
    }

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

    // #region Identity

    public function testIdentityRejectsEmptyValues() {
        // An empty string is a caller error; null simply leaves the value unset.
        foreach (array("distinct_id", "user_id", "company_id", "device_id") as $key) {
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
    }

    public function testNullLeavesAValueUnset() {
        $identity = self::identity(array("user_id" => "user_123", "device_id" => null));

        $this->assertEquals("user_123", $identity->getUserId());
        $this->assertNull($identity->getDeviceId());
        $this->assertEquals(array('$user_id' => "user_123"), $identity->toProperties());
        $this->assertFalse($identity->isHierarchical());
    }

    public function testIdentityStringNamesItsValues() {
        $this->assertEquals("MixpanelIdentity{user_id=user_123, company_id=acme}", (string) self::hgUser());
        $this->assertTrue(self::hgUser()->isHierarchical());
    }

    // #endregion
    // #region Events

    public function testHierarchicalEventSendsUserAndCompanyWithoutDistinctId() {
        $this->_hg_events->track("Signup", array(), self::hgUser());
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertEquals("user_123", $props['$user_id']);
        $this->assertEquals("acme", $props['$company_id']);
        $this->assertArrayNotHasKey("distinct_id", $props);
        $this->assertArrayNotHasKey('$device_id', $props);
        $this->assertEquals("token", $props['token']);
    }

    public function testHierarchicalEventWithDeviceId() {
        $this->_hg_events->track("Signup", array(),
            self::identity(array("user_id" => "user_123", "company_id" => "acme", "device_id" => "device_abc")));
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertEquals("device_abc", $props['$device_id']);
        $this->assertArrayNotHasKey("distinct_id", $props);
    }

    public function testCompanyOnlyEvent() {
        $this->_hg_events->track("Renewed", array(), self::identity(array("company_id" => "acme")));
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertEquals("acme", $props['$company_id']);
        $this->assertArrayNotHasKey('$user_id', $props);
        $this->assertArrayNotHasKey("distinct_id", $props);
    }

    public function testLegacyIdentitySendsDistinctId() {
        $this->_legacy_events->track("Signup", array(), self::identity(array("distinct_id" => "user_123")));
        $props = self::lastEventProperties($this->_legacy_events);

        $this->assertEquals("user_123", $props['distinct_id']);
        $this->assertArrayNotHasKey('$user_id', $props);
        $this->assertArrayNotHasKey('$company_id', $props);
    }

    public function testIdentityOverridesCallerSuppliedKeysAndKeepsOtherProperties() {
        $caller = array('$company_id' => "wrong", "plan" => "enterprise");
        $this->_hg_events->track("Signup", $caller, self::hgUser());
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertEquals("acme", $props['$company_id']);
        $this->assertEquals("enterprise", $props['plan']);
        $this->assertEquals("wrong", $caller['$company_id']);
    }

    public function testUserAndDeviceWithoutCompanyIsValidForSimplifiedIdMerge() {
        // $user_id with $device_id is the standard non-hierarchical server-side identity.
        $this->_legacy_events->track("Signup", array(),
            self::identity(array("user_id" => "user_123", "device_id" => "device_abc")));
        $props = self::lastEventProperties($this->_legacy_events);

        $this->assertEquals("user_123", $props['$user_id']);
        $this->assertEquals("device_abc", $props['$device_id']);
        $this->assertArrayNotHasKey("distinct_id", $props);
        $this->assertArrayNotHasKey('$company_id', $props);
    }

    public function testUserWithoutCompanyOrDeviceIsRejectedForHierarchicalGroups() {
        $events = $this->_hg_events;
        $identity = self::identity(array("user_id" => "user_123"));

        $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $identity) {
            $events->track("Signup", array(), $identity);
        });
        $this->assertMessageContains("cannot be merged", $e);
        $this->assertThrows("InvalidArgumentException", function () use ($events, $identity) {
            $events->identify($identity);
        });
        $this->assertCount(0, $events->getQueue());
    }

    public function testUserWithDeviceButNoCompanyIsAllowedForHierarchicalGroups() {
        // Mixpanel ignores the user id and keeps the event under the device, which later links to
        // the company-scoped user. Recoverable, so the library does not refuse it.
        $this->_hg_events->track("Signup", array(),
            self::identity(array("user_id" => "user_123", "device_id" => "device_abc")));
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertEquals("device_abc", $props['$device_id']);
        $this->assertArrayNotHasKey('$company_id', $props);
    }

    public function testAnonymousDeviceEvent() {
        foreach (array($this->_hg_events, $this->_legacy_events) as $events) {
            $events->track("Page View", array(), self::identity(array("device_id" => "device_abc")));
            $props = self::lastEventProperties($events);

            $this->assertEquals("device_abc", $props['$device_id']);
            $this->assertArrayNotHasKey("distinct_id", $props);
            $this->assertArrayNotHasKey('$user_id', $props);
            $this->assertArrayNotHasKey('$company_id', $props);
        }
    }

    public function testCombinedDistinctIdEventPassesThrough() {
        // Ingestion parses $company:{company}|$user:{user} back into $company_id and $user_id,
        // so a pipeline that can only carry one id resolves to the same user as the identity form.
        $combined = '$company:acme|$user:user_123';

        $this->_hg_events->track("Signup", array("distinct_id" => $combined));
        $this->_hg_events->track("Signup", array(), self::identity(array("distinct_id" => $combined)));
        $this->_hg_events->identify($combined);
        $this->_hg_events->track("Signup");
        foreach ($this->_hg_events->getQueue() as $msg) {
            $this->assertEquals($combined, $msg['properties']['distinct_id']);
            $this->assertArrayNotHasKey('$user_id', $msg['properties']);
        }
        $this->assertCount(3, $this->_hg_events->getQueue());
    }

    public function testRawDistinctIdEventsAreNotRefused() {
        // Not the recommended form, but it is valid data that Mixpanel accepts, so the library
        // documents the consequence rather than refusing to build the message.
        foreach (array($this->_hg_events, $this->_legacy_events) as $events) {
            $events->track("Signup", array("distinct_id" => "user_123"));
            $props = self::lastEventProperties($events);
            $this->assertEquals("user_123", $props['distinct_id']);

            $events->identify("user_456");
            $events->track("Signup");
            $props = self::lastEventProperties($events);
            $this->assertEquals("user_456", $props['distinct_id']);
        }
    }

    public function testHierarchicalIdentityRequiresHierarchicalClient() {
        $events = $this->_legacy_events;

        foreach (array(self::hgUser(), self::identity(array("company_id" => "acme"))) as $identity) {
            $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $identity) {
                $events->track("Signup", array(), $identity);
            });
            $this->assertMessageContains("hierarchical_groups", $e);
            $this->assertThrows("InvalidArgumentException", function () use ($events, $identity) {
                $events->identify($identity);
            });
        }
        $this->assertNull($events->getIdentity());
        $this->assertCount(0, $events->getQueue());
    }

    public function testTrackRejectsANonIdentity() {
        $events = $this->_hg_events;
        $this->assertThrows("InvalidArgumentException", function () use ($events) {
            $events->track("Signup", array(), "user_123");
        });
    }

    public function testIdentifyWithIdentityAppliesToLaterEvents() {
        $this->_hg_events->identify(self::hgUser());
        $this->_hg_events->track("Signup", array("plan" => "pro", '$user_id' => "wrong"));
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertSame(self::hgUser()->toProperties(), array_intersect_key($props, self::hgUser()->toProperties()));
        $this->assertEquals("pro", $props['plan']);
        $this->assertArrayNotHasKey("distinct_id", $props);
        $this->assertEquals("php", $props['mp_lib']);
    }

    public function testIdentifySwitchDoesNotLeakPreviousIdentityKeys() {
        $events = $this->_hg_events;

        // a stale distinct id from a plain identify, then a device and company from an identity
        $events->identify("old_user");
        $events->identify(self::identity(array("user_id" => "user_123", "company_id" => "acme",
            "device_id" => "device_abc")));
        $events->track("First");
        $first = self::lastEventProperties($events);
        $this->assertArrayNotHasKey("distinct_id", $first);
        $this->assertEquals("device_abc", $first['$device_id']);

        $events->identify(self::identity(array("user_id" => "user_456", "device_id" => "device_def")));
        $events->track("Second");
        $second = self::lastEventProperties($events);
        $this->assertArrayNotHasKey('$company_id', $second);
        $this->assertEquals("user_456", $second['$user_id']);
        $this->assertEquals("device_def", $second['$device_id']);

        $events->identify("user_789");
        $events->track("Third");
        $third = self::lastEventProperties($events);
        $this->assertEquals("user_789", $third['distinct_id']);
        foreach (array('$user_id', '$company_id', '$device_id') as $key) {
            $this->assertArrayNotHasKey($key, $third);
        }
        $this->assertNull($events->getIdentity());
    }

    public function testPerEventIdentityOverridesIdentify() {
        $this->_hg_events->identify("old_user");
        $this->_hg_events->track("Signup", array(), self::hgUser());
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertArrayNotHasKey("distinct_id", $props);
        $this->assertEquals("user_123", $props['$user_id']);
    }

    public function testIdentifyWithIdentityRejectsAnonId() {
        $events = $this->_hg_events;
        $this->assertThrows("InvalidArgumentException", function () use ($events) {
            $events->identify(HierarchicalGroupsTest::hgUserForClosure(), "0b54b29e-7b1f-4c4a-9d4f-1a6e0e2f3a4b");
        });
        $this->assertNull($events->getIdentity());
    }

    public static function hgUserForClosure() {
        return self::hgUser();
    }

    public function testFacadeTrackAndIdentifyAcceptIdentity() {
        $this->_hg->identify(self::hgUser());
        $this->assertEquals(self::hgUser(), $this->_hg->getIdentity());

        $mp = $this->_legacy;
        $this->assertThrows("InvalidArgumentException", function () use ($mp) {
            $mp->track("Signup", array(), HierarchicalGroupsTest::hgUserForClosure());
        });
    }

    public function testEventsUnchangedForLegacy() {
        $this->_legacy_events->identify("user_123");
        $this->_legacy_events->register('$company_id', "acme");
        $this->_legacy_events->track("Signup", array("plan" => "pro", '$user_id' => "u"));
        $props = self::lastEventProperties($this->_legacy_events);

        $this->assertEquals("user_123", $props['distinct_id']);
        $this->assertEquals("acme", $props['$company_id']);
        $this->assertEquals("u", $props['$user_id']);
        $this->assertEquals("pro", $props['plan']);
    }

    // #endregion
    // #region User profiles

    public function testAddAndAppendThrowForHierarchicalGroups() {
        // A hierarchical groups user profile is a group profile, and /groups has no $add or $append,
        // so these have no valid path at all.
        $people = $this->_hg->people;

        $this->assertThrows("BadMethodCallException", function () use ($people) {
            $people->increment("user_123", "logins", 1);
        });
        $this->assertThrows("BadMethodCallException", function () use ($people) {
            $people->append("user_123", "logins", "today");
        });
        $this->assertThrows("BadMethodCallException", function () use ($people) {
            $people->trackCharge("user_123", "9.99");
        });
        $this->assertCount(0, $people->getQueue());
    }

    public function testSupportedPeopleMethodsWorkForHierarchicalGroups() {
        // A combined distinct id is split into its company and user and sent to /groups, never /engage.
        $people = $this->_hg->people;
        $id = '$company:acme|$user:user_123';

        $people->set($id, array("plan" => "pro"));
        $people->setOnce($id, array("plan" => "pro"));
        $people->remove($id, array("plan"));
        $people->deleteUser($id);
        // an array value is sent as $union, which a group profile supports
        $people->append($id, "tags", array("a"));

        $operations = array('$set', '$set_once', '$unset', '$delete', '$union');
        $queue = $this->_hg->group->getQueue();
        $this->assertCount(count($operations), $queue);
        foreach ($queue as $i => $msg) {
            $this->assertArrayHasKey($operations[$i], $msg);
            $this->assertEquals('$user_id', $msg['$group_key']);
            $this->assertEquals("user_123", $msg['$group_id']);
            $this->assertEquals("acme", $msg['$company_id']);
            $this->assertArrayNotHasKey('$distinct_id', $msg);
        }
        $this->assertCount(0, $people->getQueue());
    }

    public function testClearChargesThrowsForHierarchicalGroups() {
        $people = $this->_hg->people;
        $this->assertThrows("BadMethodCallException", function () use ($people) {
            $people->clearCharges('$company:acme|$user:user_123');
        });
        $this->assertCount(0, $people->getQueue());
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testUnsupportedOperationThrowsBeforeDistinctIdIsChecked() {
        $people = $this->_hg->people;
        $this->assertThrows("BadMethodCallException", function () use ($people) {
            $people->increment("not_combined", "logins", 1);
        });
    }

    public function testRawDistinctIdProfileUpdateIsRejectedForHierarchicalGroups() {
        $people = $this->_hg->people;
        $e = $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set("user_123", array("plan" => "pro"));
        });

        $this->assertMessageContains("need a user and a company", $e);
        $this->assertMessageContains("MixpanelIdentity(array('user_id' => \$user_id, 'company_id' => \$company_id))", $e);
        $this->assertMessageContains('$company:{company}|$user:{user}', $e);
        $this->assertCount(0, $people->getQueue());
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testMalformedCombinedDistinctIdIsRejected() {
        $people = $this->_hg->people;
        $malformed = array(
            '$company:acme|$user:',     // missing user
            '$company:|$user:user_123', // missing company
            '$company:acme',            // no user part
            'company:acme|$user:user_123', // wrong prefix
            '$user:user_123|$company:acme', // parts reversed
            ' $company:acme|$user:user_123', // leading space
            123,
        );
        foreach ($malformed as $id) {
            $this->assertThrows("InvalidArgumentException", function () use ($people, $id) {
                $people->set($id, array("plan" => "pro"));
            });
            if (is_string($id)) {
                $this->assertThrows("InvalidArgumentException", function () use ($people, $id) {
                    $people->deleteUser(new MixpanelIdentity(array("distinct_id" => $id)));
                });
            }
        }
        $this->assertCount(0, $people->getQueue());
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testCombinedDistinctIdSplitsAtTheFirstUserSeparator() {
        $this->_hg->people->set('$company:acme|$user:a|$user:b', array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals("acme", $msg['$company_id']);
        $this->assertEquals('a|$user:b', $msg['$group_id']);
    }

    public function testCombinedDistinctIdKeepsIpAndIgnoreTime() {
        $this->_hg->people->set('$company:acme|$user:user_123', array("plan" => "pro"), "1.2.3.4", true);
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals("1.2.3.4", $msg['$ip']);
        $this->assertTrue($msg['$ignore_time']);
        $this->assertEquals(array("plan" => "pro"), $msg['$set']);
    }

    public function testAddAndAppendErrorNamesWhatIsSupported() {
        $people = $this->_hg->people;
        $e = $this->assertThrows("BadMethodCallException", function () use ($people) {
            $people->increment("user_123", "logins", 1);
        });

        // The whole point of throwing is that the message reaches whoever wrote the call.
        $this->assertMessageContains('$add', $e);
        $this->assertMessageContains('$set, $set_once, $unset, $union, $remove, $delete', $e);
        $this->assertMessageContains("increment, append (of a single value) and trackCharge", $e);
    }

    public function testPeopleMethodsUnchangedForLegacy() {
        $people = $this->_legacy->people;

        $people->set("user_123", array("plan" => "pro"));
        $people->increment("user_123", "logins", 1);
        $people->append("user_123", "logins", "today");
        $people->trackCharge("user_123", "9.99");
        $people->set("user_123", array('$company_id' => "acme"));

        $operations = array('$set', '$add', '$append', '$append', '$set');
        foreach ($people->getQueue() as $i => $msg) {
            $this->assertArrayHasKey($operations[$i], $msg);
            $this->assertEquals("user_123", $msg['$distinct_id']);
            $this->assertArrayNotHasKey('$company_id', $msg);
        }
        $this->assertCount(0, $this->_legacy->group->getQueue());
    }

    public function testIdentitySetReroutesToUserGroupProfile() {
        $this->_hg->people->set(self::hgUser(), array("role" => "admin"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals("token", $msg['$token']);
        $this->assertEquals('$user_id', $msg['$group_key']);
        $this->assertEquals("user_123", $msg['$group_id']);
        $this->assertEquals("acme", $msg['$company_id']);
        $this->assertEquals("admin", $msg['$set']['role']);
        $this->assertArrayNotHasKey('$distinct_id', $msg);
        $this->assertArrayNotHasKey('$company_id', $msg['$set']);
        $this->assertCount(0, $this->_hg->people->getQueue());
    }

    public function testEveryIdentityProfileOperationReroutes() {
        $people = $this->_hg->people;
        $identity = self::hgUser();

        $people->set($identity, array("plan" => "pro"));
        $people->setOnce($identity, array("plan" => "pro"));
        $people->remove($identity, array("plan"));
        $people->deleteUser($identity, null, true);

        $operations = array('$set', '$set_once', '$unset', '$delete');
        $queue = $this->_hg->group->getQueue();
        $this->assertCount(count($operations), $queue);
        foreach ($queue as $i => $msg) {
            $this->assertArrayHasKey($operations[$i], $msg);
            $this->assertEquals("acme", $msg['$company_id']);
            $this->assertEquals("user_123", $msg['$group_id']);
        }
        $this->assertTrue($queue[3]['$ignore_time']);
    }

    public function testIdentityRejectedByIncrementAppendAndTrackCharge() {
        foreach (array($this->_hg->people, $this->_legacy->people) as $people) {
            $identity = new MixpanelIdentity(array("distinct_id" => "user_123"));
            $calls = array(
                function () use ($people, $identity) { $people->increment($identity, "logins", 1); },
                function () use ($people, $identity) { $people->append($identity, "tags", "a"); },
                function () use ($people, $identity) { $people->trackCharge($identity, "9.99"); },
                function () use ($people, $identity) { $people->clearCharges($identity); },
            );
            foreach ($calls as $call) {
                $this->assertThrows("InvalidArgumentException", $call);
            }
        }
    }

    public function testHierarchicalIdentityProfileUpdateRequiresHierarchicalClient() {
        $people = $this->_legacy->people;

        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(HierarchicalGroupsTest::hgUserForClosure(), array("role" => "admin"));
        });
        $this->assertCount(0, $people->getQueue());
        $this->assertCount(0, $this->_legacy->group->getQueue());
    }

    public function testIdentityCompanyWinsOverForCompany() {
        $this->_hg->forCompany("other")->people->set(self::hgUser(), array("role" => "admin"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals("acme", $msg['$company_id']);
    }

    public function testLegacyIdentityUsesEngage() {
        $this->_legacy->people->set(self::identity(array("distinct_id" => "user_123")), array("role" => "admin"),
            "1.2.3.4");
        $msg = self::lastMessage($this->_legacy->people);

        $this->assertEquals("user_123", $msg['$distinct_id']);
        $this->assertEquals("1.2.3.4", $msg['$ip']);
        $this->assertEquals("admin", $msg['$set']['role']);
    }

    public function testDistinctIdIdentityProfileUpdateGoesToGroups() {
        $id = '$company:acme|$user:user_123';
        $this->_hg->people->set(self::identity(array("distinct_id" => $id)), array("role" => "admin"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals('$user_id', $msg['$group_key']);
        $this->assertEquals("user_123", $msg['$group_id']);
        $this->assertEquals("acme", $msg['$company_id']);
        $this->assertArrayNotHasKey('$distinct_id', $msg);
        $this->assertCount(0, $this->_hg->people->getQueue());
    }

    public function testRawDistinctIdIdentityProfileUpdateIsRejectedForHierarchicalGroups() {
        $people = $this->_hg->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("distinct_id" => "user_123")), array("role" => "admin"));
        });
        $this->assertCount(0, $people->getQueue());
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testCompanyOnlyIdentityProfileUpdateIsRejected() {
        $people = $this->_hg->people;
        $e = $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("company_id" => "acme")), array("tier" => "ent"));
        });

        $this->assertMessageContains("group->set('\$company_id'", $e);
    }

    public function testDeviceIdentityCannotIdentifyAProfile() {
        foreach (array($this->_hg->people, $this->_legacy->people) as $people) {
            $this->assertThrows("InvalidArgumentException", function () use ($people) {
                $people->set(new MixpanelIdentity(array("device_id" => "device_abc")), array("plan" => "pro"));
            });
        }
    }

    public function testUserIdentityWithoutDistinctIdIsRejectedForLegacy() {
        $people = $this->_legacy->people;
        $e = $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("user_id" => "user_123", "device_id" => "device_abc")),
                array("plan" => "pro"));
        });

        $this->assertMessageContains("addressed by distinct id", $e);
    }

    public function testUserAndDeviceIdentityProfileUpdateRequiresCompany() {
        $people = $this->_hg->people;
        $e = $this->assertThrows("LogicException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("user_id" => "user_123", "device_id" => "device_abc")),
                array("plan" => "pro"));
        });

        $this->assertMessageContains("company_id", $e);
    }

    public function testIdentityProfileUpdateRejectsUserWithoutCompanyOrDevice() {
        $people = $this->_hg->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("user_id" => "user_123")), array("plan" => "pro"));
        });
    }

    public function testStandalonePeopleProducerQueuesIdentityUpdatesOnItsOwnGroupsProducer() {
        $people = new Producers_MixpanelPeople("token", array("hierarchical_groups" => true));
        $people->set(self::hgUser(), array("role" => "admin"));
        $msg = self::lastMessage($people->getGroupsProducer());

        $this->assertEquals('$user_id', $msg['$group_key']);
        $this->assertEquals("acme", $msg['$company_id']);
        $people->getGroupsProducer()->reset();
    }

    // #endregion
    // #region Group profiles

    public function testForCompanyAddsTopLevelCompanyId() {
        $this->_hg->forCompany("acme")->group->set("department", "finance", array("plan" => "enterprise"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals("acme", $msg['$company_id']);
        $this->assertEquals("department", $msg['$group_key']);
        $this->assertEquals("finance", $msg['$group_id']);
        $this->assertArrayNotHasKey('$company_id', $msg['$set']);
    }

    public function testUserAndCompanyProfilesHaveBareGroupIds() {
        $acme = $this->_hg->forCompany("acme");

        $acme->group->set('$user_id', "user_123", array("role" => "admin"));
        $user = self::lastMessage($this->_hg->group);
        $this->assertEquals('$user_id', $user['$group_key']);
        $this->assertEquals("user_123", $user['$group_id']);
        $this->assertEquals("acme", $user['$company_id']);

        $acme->group->set('$company_id', "acme", array("tier" => "ent"));
        $company = self::lastMessage($this->_hg->group);
        $this->assertEquals('$company_id', $company['$group_key']);
        $this->assertEquals("acme", $company['$group_id']);
        $this->assertEquals("acme", $company['$company_id']);
    }

    public function testEveryGroupOperationCarriesCompany() {
        $acme = $this->_hg->group->forCompany("acme");

        $acme->set("department", "finance", array("plan" => "enterprise"));
        $acme->setOnce("department", "finance", array("plan" => "enterprise"));
        $acme->remove("department", "finance", array("plan" => "enterprise"));
        $acme->union("department", "finance", "tags", array("a"));
        $acme->deleteGroup("department", "finance");

        $queue = $this->_hg->group->getQueue();
        $this->assertCount(5, $queue);
        foreach ($queue as $msg) {
            $this->assertEquals("acme", $msg['$company_id']);
        }
    }

    public function testCompanyProfileTakesItsCompanyFromItsGroupIdForEveryOperation() {
        $group = $this->_hg->group;

        $group->set('$company_id', "acme", array("tier" => "enterprise"));
        $group->setOnce('$company_id', "acme", array("tier" => "enterprise"));
        $group->remove('$company_id', "acme", array("tags" => "a"));
        $group->union('$company_id', "acme", "tags", array("a"));
        $group->deleteGroup('$company_id', "acme");

        $queue = $group->getQueue();
        $this->assertCount(5, $queue);
        foreach ($queue as $msg) {
            $this->assertEquals('$company_id', $msg['$group_key']);
            $this->assertEquals("acme", $msg['$group_id']);
            $this->assertSame("acme", $msg['$company_id']);
        }
        $this->assertEquals(array("tier" => "enterprise"), $queue[0]['$set']);
    }

    public function testCompanyProfileAcceptsAMatchingBoundCompany() {
        $this->_hg->forCompany("acme")->group->set('$company_id', "acme", array("tier" => "ent"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertSame("acme", $msg['$company_id']);
    }

    public function testCompanyProfileRejectsADifferentBoundCompany() {
        $other = $this->_hg->forCompany("other")->group;
        $calls = array(
            function () use ($other) { $other->set('$company_id', "acme", array("tier" => "ent")); },
            function () use ($other) { $other->setOnce('$company_id', "acme", array("tier" => "ent")); },
            function () use ($other) { $other->remove('$company_id', "acme", array("tags" => "a")); },
            function () use ($other) { $other->union('$company_id', "acme", "tags", array("a")); },
            function () use ($other) { $other->deleteGroup('$company_id', "acme"); },
        );
        foreach ($calls as $call) {
            $e = $this->assertThrows("InvalidArgumentException", $call);
            $this->assertMessageContains("company is its own id", $e);
        }
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testCustomGroupAndUserProfileStillRequireACompany() {
        $group = $this->_hg->group;
        $this->assertThrows("LogicException", function () use ($group) {
            $group->set("team_id", "acme", array("name" => "x"));
        });
        $this->assertThrows("LogicException", function () use ($group) {
            $group->set('$user_id', "acme", array("name" => "x"));
        });
        $this->assertCount(0, $group->getQueue());
    }

    public function testLegacyCompanyIdGroupUnchanged() {
        $this->_legacy->group->set('$company_id', "acme", array("tier" => "ent"));
        $this->assertArrayNotHasKey('$company_id', self::lastMessage($this->_legacy->group));

        $this->_legacy->forCompany("other")->group->set('$company_id', "acme", array("tier" => "ent"));
        $this->assertEquals("other", self::lastMessage($this->_legacy->group)['$company_id']);
    }

    public function testHierarchicalGroupWithoutCompanyIsRejected() {
        $group = $this->_hg->group;
        $e = $this->assertThrows("LogicException", function () use ($group) {
            $group->set("department", "finance", array("plan" => "enterprise"));
        });

        $this->assertMessageContains("forCompany", $e);
        $this->assertCount(0, $group->getQueue());
    }

    public function testLegacyGroupUnchanged() {
        $this->_legacy->group->set("company", "Acme Inc.", array("plan" => "x"));
        $msg = self::lastMessage($this->_legacy->group);

        $this->assertArrayNotHasKey('$company_id', $msg);
        $this->assertEquals("company", $msg['$group_key']);
    }

    public function testForCompanyOnLegacyClientAddsCompanyId() {
        $this->_legacy->forCompany("acme")->group->set("company", "Acme Inc.", array("plan" => "x"));
        $msg = self::lastMessage($this->_legacy->group);

        $this->assertEquals("acme", $msg['$company_id']);
    }

    public function testForCompanyRejectsEmpty() {
        $mp = $this->_hg;
        foreach (array("", null) as $company_id) {
            $this->assertThrows("InvalidArgumentException", function () use ($mp, $company_id) {
                $mp->forCompany($company_id);
            });
            $this->assertThrows("InvalidArgumentException", function () use ($mp, $company_id) {
                $mp->group->forCompany($company_id);
            });
        }
    }

    public function testForCompanyKeepsAddAndAppendRejected() {
        $acme = $this->_hg->forCompany("acme");
        $this->assertThrows("BadMethodCallException", function () use ($acme) {
            $acme->people->increment("user_123", "logins", 1);
        });
    }

    public function testForCompanyLeavesTheReceiverUnchangedAndSharesItsQueue() {
        $acme = $this->_hg->forCompany("acme");
        $other = $acme->forCompany("other");

        $this->assertNotSame($this->_hg, $acme);
        $this->assertNull($this->_hg->group->getCompanyId());
        $this->assertEquals("acme", $acme->group->getCompanyId());
        $this->assertEquals("other", $other->group->getCompanyId());
        $this->assertSame($this->_hg->people, $acme->people);

        $acme->group->set("department", "finance", array("plan" => "x"));
        $other->group->set("department", "finance", array("plan" => "x"));
        $this->assertCount(2, $this->_hg->group->getQueue());
        $this->assertEquals($this->_hg->group->getQueue(), $acme->group->getQueue());

        // a view going away leaves the shared queue to the client that owns it
        $acme = $other = null;
        $this->assertCount(2, $this->_hg->group->getQueue());
    }

    public function testForCompanyViewResetsTheSharedQueue() {
        $this->_hg->forCompany("acme")->group->set("department", "finance", array("plan" => "x"));
        $view = $this->_hg->group->forCompany("acme");

        $view->reset();
        $this->assertCount(0, $this->_hg->group->getQueue());
        $this->assertCount(0, $view->getQueue());
    }

    // #endregion
}
