<?php

/**
 * Records every message a producer flushes, with the endpoint it is sent to
 */
class HierarchicalGroupsTest_CaptureConsumer extends ConsumerStrategies_AbstractConsumer {

    /**
     * Each sent message as array(endpoint, message)
     * @var array
     */
    public static $sent = array();

    public function persist($batch) {
        foreach ($batch as $message) {
            self::$sent[] = array($this->_options['endpoint'], $message);
        }
        return true;
    }
}

/**
 * Records the options each consumer is created with
 */
class HierarchicalGroupsTest_OptionsConsumer extends ConsumerStrategies_AbstractConsumer {

    /**
     * The options the last consumer was created with
     * @var array|null
     */
    public static $options = null;

    public function __construct($options = array()) {
        parent::__construct($options);
        self::$options = $this->_options;
    }

    public function persist($batch) {
        return true;
    }
}

class HierarchicalGroupsTest extends \PHPUnit\Framework\TestCase {

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

    protected function setUp(): void {
        parent::setUp();
        $this->_hg = new Mixpanel("token", array("hierarchical_groups" => true));
        $this->_legacy = new Mixpanel("token");
        $this->_hg_events = new Producers_MixpanelEvents("token", array("hierarchical_groups" => true));
        $this->_legacy_events = new Producers_MixpanelEvents("token");
    }

    protected function tearDown(): void {
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

    public function testUserWithoutCompanyIsSentAsGivenForHierarchicalGroups() {
        $this->_hg_events->track("Signup", array(), self::identity(array("user_id" => "user_123")));
        $props = self::lastEventProperties($this->_hg_events);

        $this->assertEquals("user_123", $props['$user_id']);
        $this->assertArrayNotHasKey('$company_id', $props);
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

    public function testLegacyTrackIgnoresANonIdentityThirdArgument() {
        foreach (array("user_123", 42, array("user_id" => "user_123"), new stdClass()) as $extra) {
            $events = new Producers_MixpanelEvents("token");
            $events->identify("user_456");
            $events->track("Signup", array("plan" => "pro", "time" => 1), $extra);
            $plain = new Producers_MixpanelEvents("token");
            $plain->identify("user_456");
            $plain->track("Signup", array("plan" => "pro", "time" => 1));

            $this->assertEquals($plain->getQueue(), $events->getQueue());
            $events->reset();
            $plain->reset();
        }

        $this->_legacy->track("Signup", array("plan" => "pro"), "user_123");
        $property = new ReflectionProperty("Mixpanel", "_events");
        // Needed before PHP 8.1, where reflection is otherwise limited to public members; deprecated in 8.5
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $props = self::lastEventProperties($property->getValue($this->_legacy));
        $this->assertEquals("pro", $props['plan']);
        $this->assertArrayNotHasKey('$user_id', $props);
    }

    public function testFacadeTrackRejectsANonIdentityForHierarchicalGroups() {
        $mp = $this->_hg;
        $this->assertThrows("InvalidArgumentException", function () use ($mp) {
            $mp->track("Signup", array(), "user_123");
        });
    }

    public function testLegacyOptionsDoNotGainAHierarchicalGroupsKey() {
        $consumers = array("capture" => "HierarchicalGroupsTest_OptionsConsumer");
        new Producers_MixpanelEvents("token", array("consumer" => "capture", "consumers" => $consumers));
        $this->assertArrayNotHasKey("hierarchical_groups", HierarchicalGroupsTest_OptionsConsumer::$options);

        $curl = new ConsumerStrategies_CurlConsumer(array("host" => "api.mixpanel.com", "endpoint" => "/track"));
        $this->assertArrayNotHasKey("hierarchical_groups", $curl->getOptions());
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
        // the people producer's queue is the groups queue its updates are on
        $this->assertEquals($queue, $people->getQueue());
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
        $this->assertEquals($this->_hg->group->getQueue(), $this->_hg->people->getQueue());
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

    public function testForCompanyAcceptsAUserFromTheBoundCompany() {
        $this->_hg->forCompany("acme")->people->set(self::hgUser(), array("role" => "admin"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertEquals("acme", $msg['$company_id']);
    }

    public function testForCompanyRefusesAUserFromAnotherCompany() {
        $people = $this->_hg->forCompany("other")->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(HierarchicalGroupsTest::hgUserForClosure(), array("role" => "admin"));
        });
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set('$company:acme|$user:user_123', array("role" => "admin"));
        });
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testForCompanyUsesTheBoundCompanyForAUserOnlyIdentity() {
        $people = $this->_hg->forCompany("acme")->people;
        $identity = self::identity(array("user_id" => "user_123"));

        $people->set($identity, array("plan" => "pro"));
        $people->setOnce($identity, array("plan" => "pro"));
        $people->append($identity, "tags", array("a"));
        $people->remove($identity, array("plan"));
        $people->deleteUser($identity);

        $operations = array('$set', '$set_once', '$union', '$unset', '$delete');
        $queue = $this->_hg->group->getQueue();
        $this->assertCount(count($operations), $queue);
        foreach ($queue as $i => $msg) {
            $this->assertArrayHasKey($operations[$i], $msg);
            $this->assertEquals('$user_id', $msg['$group_key']);
            $this->assertEquals("user_123", $msg['$group_id']);
            $this->assertSame("acme", $msg['$company_id']);
            $this->assertArrayNotHasKey('$distinct_id', $msg);
        }
    }

    public function testForCompanyUsesTheBoundCompanyForAUserAndDeviceIdentity() {
        $this->_hg->forCompany("acme")->people->set(
            self::identity(array("user_id" => "user_123", "device_id" => "device_abc")), array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);

        $this->assertSame("acme", $msg['$company_id']);
        $this->assertEquals("user_123", $msg['$group_id']);
    }

    public function testUnboundClientStillRefusesAUserOnlyIdentity() {
        $people = $this->_hg->people;
        $this->assertThrows("LogicException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("user_id" => "user_123")), array("plan" => "pro"));
        });
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testForCompanyComparesIntegerAndStringCompaniesAsStrings() {
        $this->_hg->forCompany(42)->people->set(
            self::identity(array("user_id" => "user_123", "company_id" => "42")), array("plan" => "pro"));
        $this->assertSame("42", self::lastMessage($this->_hg->group)['$company_id']);

        $this->_hg->forCompany("42")->people->set(
            self::identity(array("user_id" => "user_123", "company_id" => 42)), array("plan" => "pro"));
        $this->assertSame("42", self::lastMessage($this->_hg->group)['$company_id']);

        $this->_hg->forCompany(42)->people->set(self::identity(array("user_id" => 7)), array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame("42", $msg['$company_id']);
        $this->assertSame("7", $msg['$group_id']);

        $people = $this->_hg->forCompany(42)->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(new MixpanelIdentity(array("user_id" => 7, "company_id" => 43)), array("plan" => "pro"));
        });
        $this->assertCount(3, $this->_hg->group->getQueue());
    }

    public function testEmptyPropertiesStillValidateAnIdentity() {
        $hg = $this->_hg->people;
        $legacy = $this->_legacy->people;
        $empties = array(array(), null);
        foreach (array('set', 'setOnce', 'remove') as $method) {
            foreach ($empties as $empty) {
                // the same exceptions a non-empty call throws
                $this->assertThrows("LogicException", function () use ($hg, $method, $empty) {
                    $hg->$method(new MixpanelIdentity(array("user_id" => "user_123")), $empty);
                });
                $this->assertThrows("InvalidArgumentException", function () use ($hg, $method, $empty) {
                    $hg->$method(new MixpanelIdentity(array("company_id" => "acme")), $empty);
                });
                $this->assertThrows("InvalidArgumentException", function () use ($hg, $method, $empty) {
                    $hg->$method("user_123", $empty);
                });
                $other = $this->_hg->forCompany("other")->people;
                $this->assertThrows("InvalidArgumentException", function () use ($other, $method, $empty) {
                    $other->$method(HierarchicalGroupsTest::hgUserForClosure(), $empty);
                });
                $this->assertThrows("InvalidArgumentException", function () use ($legacy, $method, $empty) {
                    $legacy->$method(HierarchicalGroupsTest::hgUserForClosure(), $empty);
                });
                $this->assertThrows("InvalidArgumentException", function () use ($legacy, $method, $empty) {
                    $legacy->$method(new MixpanelIdentity(array("user_id" => "user_123")), $empty);
                });

                // valid ids still send nothing
                $hg->$method(self::hgUser(), $empty);
                $hg->$method('$company:acme|$user:user_123', $empty);
                $this->_hg->forCompany("acme")->people->$method(self::identity(array("user_id" => "u")), $empty);
                $legacy->$method(self::identity(array("distinct_id" => "user_123")), $empty);
                // a plain distinct id without hierarchical groups returns silently, as on master
                $legacy->$method("user_123", $empty);
                $legacy->$method(null, $empty);
            }
        }
        $this->assertCount(0, $this->_hg->group->getQueue());
        $this->assertCount(0, $this->_legacy->people->getQueue());
        $this->assertCount(0, $this->_legacy->group->getQueue());
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
        $this->assertEquals($this->_hg->group->getQueue(), $this->_hg->people->getQueue());
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

        $this->assertMessageContains("forCompany(\$company_id)->company->set(\$props)", $e);
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

    public function testIdentityProfileUpdateRequiresACompany() {
        $people = $this->_hg->people;
        $this->assertThrows("LogicException", function () use ($people) {
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

    public function testPeopleQueueIsTheGroupQueueForHierarchicalGroups() {
        $people = $this->_hg->people;
        $people->set(self::hgUser(), array("role" => "admin"));
        $people->deleteUser(self::hgUser());

        $this->assertCount(2, $people->getQueue());
        $this->assertEquals($this->_hg->group->getQueue(), $people->getQueue());
    }

    public function testPeopleResetCancelsQueuedUserProfileUpdatesForHierarchicalGroups() {
        $this->_hg->people->deleteUser(self::hgUser());
        $this->_hg->people->reset();

        $this->assertCount(0, $this->_hg->group->getQueue());
        $this->assertCount(0, $this->_hg->people->getQueue());
    }

    public function testPeopleResetKeepsCompanyAndGroupUpdatesForHierarchicalGroups() {
        $acme = $this->_hg->forCompany("acme");
        $acme->company->set(array("tier" => "enterprise"));
        $this->_hg->people->set(self::hgUser(), array("role" => "admin"));
        $acme->group->set("team", "growth", array("size" => 5));
        $this->_hg->people->deleteUser(self::hgUser());

        $this->_hg->people->reset();

        $queue = $this->_hg->group->getQueue();
        $this->assertCount(2, $queue);
        $this->assertEquals('$company_id', $queue[0]['$group_key']);
        $this->assertEquals(array("tier" => "enterprise"), $queue[0]['$set']);
        $this->assertEquals("team", $queue[1]['$group_key']);
        $this->assertEquals("growth", $queue[1]['$group_id']);
    }

    public function testPeopleResetThroughForCompanyViewKeepsCompanyUpdatesForHierarchicalGroups() {
        $acme = $this->_hg->forCompany("acme");
        $acme->company->set(array("tier" => "enterprise"));
        $acme->people->set(self::identity(array("user_id" => "user_123")), array("role" => "admin"));

        $acme->people->reset();

        $queue = $this->_hg->group->getQueue();
        $this->assertCount(1, $queue);
        $this->assertEquals('$company_id', $queue[0]['$group_key']);
    }

    public function testPeopleFlushSendsUserProfileUpdatesToGroupsOnceForHierarchicalGroups() {
        $mp = new Mixpanel("token", array(
            "hierarchical_groups" => true,
            "consumers" => array("capture" => "HierarchicalGroupsTest_CaptureConsumer"),
            "consumer" => "capture"
        ));
        HierarchicalGroupsTest_CaptureConsumer::$sent = array();
        $mp->people->set(self::hgUser(), array("role" => "admin"));

        $this->assertTrue($mp->people->flush());
        $this->assertCount(0, $mp->group->getQueue());
        // the group producer has nothing left to send, so the update is sent once
        $mp->group->flush();
        $mp = null;
        $sent = HierarchicalGroupsTest_CaptureConsumer::$sent;
        HierarchicalGroupsTest_CaptureConsumer::$sent = array();

        $this->assertCount(1, $sent);
        $this->assertEquals("/groups", $sent[0][0]);
        $this->assertEquals("user_123", $sent[0][1]['$group_id']);
    }

    public function testPeopleEnqueueIsSentAsGivenForHierarchicalGroups() {
        $mp = new Mixpanel("token", array(
            "hierarchical_groups" => true,
            "consumers" => array("capture" => "HierarchicalGroupsTest_CaptureConsumer"),
            "consumer" => "capture"
        ));
        HierarchicalGroupsTest_CaptureConsumer::$sent = array();
        $raw = array('$token' => "token", '$distinct_id' => "user_123", '$set' => array("role" => "admin"));
        $mp->people->enqueue($raw);
        $mp->people->set(self::hgUser(), array("plan" => "enterprise"));

        $this->assertCount(2, $mp->people->getQueue());
        $this->assertEquals($raw, $mp->people->getQueue()[0]);
        $this->assertTrue($mp->people->flush());
        $this->assertCount(0, $mp->people->getQueue());
        $sent = HierarchicalGroupsTest_CaptureConsumer::$sent;
        HierarchicalGroupsTest_CaptureConsumer::$sent = array();

        $this->assertCount(2, $sent);
        $this->assertEquals(array("/engage", $raw), $sent[0]);
        $this->assertEquals("/groups", $sent[1][0]);
    }

    public function testPeopleResetClearsEnqueuedMessagesForHierarchicalGroups() {
        $this->_hg->people->enqueue(array('$distinct_id' => "user_123", '$set' => array("role" => "admin")));
        $this->_hg->people->reset();

        $this->assertCount(0, $this->_hg->people->getQueue());
    }

    public function testPeopleEnqueueIsSentOnDestructForHierarchicalGroups() {
        $mp = new Mixpanel("token", array(
            "hierarchical_groups" => true,
            "consumers" => array("capture" => "HierarchicalGroupsTest_CaptureConsumer"),
            "consumer" => "capture"
        ));
        HierarchicalGroupsTest_CaptureConsumer::$sent = array();
        $mp->people->enqueue(array('$distinct_id' => "user_123", '$set' => array("role" => "admin")));
        $mp = null;
        $sent = HierarchicalGroupsTest_CaptureConsumer::$sent;
        HierarchicalGroupsTest_CaptureConsumer::$sent = array();

        $this->assertCount(1, $sent);
        $this->assertEquals("/engage", $sent[0][0]);
    }

    public function testForCompanyPeopleViewLeavesTheSharedQueueToTheClient() {
        $acme = $this->_hg->forCompany("acme");
        $acme->people->set(self::hgUser(), array("role" => "admin"));

        // binding a view does not reset, and a view going away does not flush, the client's queue
        $other = $this->_hg->forCompany("other");
        $acme = $other = null;
        $this->assertCount(1, $this->_hg->group->getQueue());
        $this->assertEquals($this->_hg->group->getQueue(), $this->_hg->people->getQueue());
    }

    public function testPeopleQueueIsItsOwnForLegacy() {
        $this->_legacy->people->set("user_123", array("role" => "admin"));
        $this->_legacy->group->set("department", "finance", array("plan" => "x"));
        $this->_legacy->people->reset();

        $this->assertCount(0, $this->_legacy->people->getQueue());
        $this->assertCount(1, $this->_legacy->group->getQueue());
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
        $this->assertSame($this->_hg->people->getGroupsProducer(), $acme->people->getGroupsProducer());

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

    /**
     * Every group call with group key '$company_id' and group id "globex", each taking a group producer
     */
    private static function globexCompanyGroupCalls() {
        return array(
            function ($group) { $group->set('$company_id', "globex", array("plan" => "pro")); },
            function ($group) { $group->setOnce('$company_id', "globex", array("plan" => "pro")); },
            function ($group) { $group->remove('$company_id', "globex", array("products" => "legacy")); },
            function ($group) { $group->union('$company_id', "globex", "products", array("analytics")); },
            function ($group) { $group->deleteGroup('$company_id', "globex"); },
        );
    }

    public function testCompanyGroupForAnotherCompanyIsRejected() {
        // the service applies the top-level $company_id, so this would update acme's profile
        foreach (array($this->_hg->forCompany("acme")->group, $this->_hg->group->forCompany("acme")) as $acme) {
            foreach (self::globexCompanyGroupCalls() as $call) {
                $e = $this->assertThrows("InvalidArgumentException", function () use ($call, $acme) {
                    call_user_func($call, $acme);
                });
                $this->assertMessageContains('Group $company_id=globex does not match company acme', $e);
                $this->assertMessageContains("forCompany(globex)->company", $e);
            }
        }
        $this->assertCount(0, $this->_hg->group->getQueue());
    }

    public function testCompanyGroupForTheBoundCompanyIsSentUnchanged() {
        $this->_hg->forCompany("acme")->group->set('$company_id', "acme", array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        unset($msg['$time']);

        $this->assertSame(array(
            '$token' => "token",
            '$group_key' => '$company_id',
            '$group_id' => "acme",
            '$set' => array("plan" => "pro"),
            '$company_id' => "acme",
        ), $msg);
    }

    public function testCompanyGroupComparesIdsAsStrings() {
        $this->_hg->forCompany(7)->group->set('$company_id', "7", array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame("7", $msg['$group_id']);
        $this->assertSame("7", $msg['$company_id']);

        $this->_hg->forCompany("7")->group->set('$company_id', 7, array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame(7, $msg['$group_id']);
        $this->assertSame("7", $msg['$company_id']);

        $acme = $this->_hg->forCompany(7)->group;
        $this->assertThrows("InvalidArgumentException", function () use ($acme) {
            $acme->set('$company_id', 8, array("plan" => "pro"));
        });
        $this->assertCount(2, $this->_hg->group->getQueue());
    }

    public function testCompanyGroupForAnotherCompanyIsUnchangedForLegacy() {
        $acme = $this->_legacy->forCompany("acme")->group;
        foreach (self::globexCompanyGroupCalls() as $call) {
            call_user_func($call, $acme);
        }

        $queue = $this->_legacy->group->getQueue();
        $this->assertCount(5, $queue);
        foreach ($queue as $msg) {
            $this->assertEquals('$company_id', $msg['$group_key']);
            $this->assertEquals("globex", $msg['$group_id']);
            $this->assertEquals("acme", $msg['$company_id']);
        }
    }

    public function testIdentifyWithAnonIdIsRefusedForHierarchicalGroups() {
        $events = $this->_hg_events;
        $anon = '$device:' . "0f8fad5b-d9cb-469f-a165-70867728950e";
        try {
            $events->identify("user_456", $anon);
            $this->fail("expected BadMethodCallException");
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString("merges users", $e->getMessage());
        }
        $this->assertCount(0, $events->getQueue());
        // nothing changed: a later event does not carry the refused user
        $events->track("Signup");
        $props = self::lastEventProperties($events);
        $this->assertFalse(isset($props['distinct_id']) && $props['distinct_id'] === "user_456");
    }

    public function testIdentifyWithoutAnonIdIsUnchangedForHierarchicalGroups() {
        $this->_hg_events->identify("user_456");
        $this->_hg_events->track("Signup");

        $this->assertCount(1, $this->_hg_events->getQueue());
        $this->assertEquals("user_456", self::lastEventProperties($this->_hg_events)['distinct_id']);
    }

    public function testIdentifyWithAnonIdIsUnchangedForLegacy() {
        $anon = '$device:' . "0f8fad5b-d9cb-469f-a165-70867728950e";
        $this->_legacy_events->identify("user_456", $anon);

        $msg = self::lastMessage($this->_legacy_events);
        $this->assertEquals('$identify', $msg['event']);
        $this->assertEquals("user_456", $msg['properties']['$identified_id']);
        $this->assertEquals($anon, $msg['properties']['$anon_id']);
    }

    public function testNonBooleanHierarchicalGroupsOptionIsRefused() {
        foreach (array(1, "true", "1", 0, null) as $value) {
            if ($value === null) {
                // null is the same as not set
                new Mixpanel("token", array("hierarchical_groups" => $value));
                continue;
            }
            try {
                new Mixpanel("token", array("hierarchical_groups" => $value));
                $this->fail("expected InvalidArgumentException for " . var_export($value, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("hierarchical_groups must be true or false", $e->getMessage());
            }
        }
        new Mixpanel("token", array("hierarchical_groups" => false));
        new Producers_MixpanelEvents("token", array("hierarchical_groups" => true));
    }

    // #endregion
    // #region Company profile    // #endregion
    // #region Company profile

    /**
     * Pairs of a company call and the group call it must match, each taking a forCompany("acme") client
     */
    private static function companyAndGroupCalls() {
        return array(
            array(function ($mp) { $mp->company->set(array("tier" => "enterprise")); },
                function ($mp) { $mp->group->set('$company_id', "acme", array("tier" => "enterprise")); }),
            array(function ($mp) { $mp->company->set(array("tier" => "enterprise"), true); },
                function ($mp) { $mp->group->set('$company_id', "acme", array("tier" => "enterprise"), true); }),
            array(function ($mp) { $mp->company->setOnce(array("first_seen" => "2024-01-02")); },
                function ($mp) { $mp->group->setOnce('$company_id', "acme", array("first_seen" => "2024-01-02")); }),
            array(function ($mp) { $mp->company->remove(array("products" => "legacy"), true); },
                function ($mp) { $mp->group->remove('$company_id', "acme", array("products" => "legacy"), true); }),
            array(function ($mp) { $mp->company->union("products", array("analytics")); },
                function ($mp) { $mp->group->union('$company_id', "acme", "products", array("analytics")); }),
            array(function ($mp) { $mp->company->deleteCompany(); },
                function ($mp) { $mp->group->deleteGroup('$company_id', "acme"); }),
            array(function ($mp) { $mp->company->deleteCompany(true); },
                function ($mp) { $mp->group->deleteGroup('$company_id', "acme", true); }),
        );
    }

    public function testCompanyMethodsQueueExactlyWhatTheGroupCallQueues() {
        foreach (array($this->_hg, $this->_legacy) as $mp) {
            $acme = $mp->forCompany("acme");
            foreach (self::companyAndGroupCalls() as $calls) {
                $mp->group->reset();
                call_user_func($calls[0], $acme);
                call_user_func($calls[1], $acme);

                $queue = $mp->group->getQueue();
                $this->assertCount(2, $queue);
                // $time is the only value that differs between two calls
                unset($queue[0]['$time'], $queue[1]['$time']);
                $this->assertSame(json_encode($queue[1]), json_encode($queue[0]));
                $this->assertEquals('$company_id', $queue[0]['$group_key']);
                $this->assertEquals("acme", $queue[0]['$group_id']);
                $this->assertEquals("acme", $queue[0]['$company_id']);
            }
        }
    }

    public function testCompanyWithoutForCompanyIsRejected() {
        foreach (array($this->_hg, $this->_legacy) as $mp) {
            foreach (self::companyAndGroupCalls() as $calls) {
                $call = $calls[0];
                $e = $this->assertThrows("LogicException", function () use ($call, $mp) {
                    call_user_func($call, $mp);
                });
                $this->assertMessageContains("forCompany", $e);
            }
            $this->assertCount(0, $mp->group->getQueue());
        }
    }

    public function testForCompanyLeavesTheReceiverCompanyUnbound() {
        $acme = $this->_hg->forCompany("acme");
        $other = $acme->forCompany("other");

        $acme->company->set(array("tier" => "enterprise"));
        $this->assertEquals("acme", self::lastMessage($this->_hg->group)['$group_id']);
        $other->company->set(array("tier" => "enterprise"));
        $this->assertEquals("other", self::lastMessage($this->_hg->group)['$group_id']);
        $this->assertEquals("other", self::lastMessage($this->_hg->group)['$company_id']);

        $mp = $this->_hg;
        $this->assertThrows("LogicException", function () use ($mp) {
            $mp->company->set(array("tier" => "enterprise"));
        });
    }

    // #endregion

    // #region Service testing fixes

    public function testIntegerIdsAreSentAsStringsForHierarchicalGroups() {
        // Mixpanel stored an integer $user_id as no $user_id at all
        $ints = self::identity(array("user_id" => 3190316, "company_id" => 3190317));
        $this->_hg_events->track("Signup", array("count" => 5), $ints);
        $props = self::lastEventProperties($this->_hg_events);
        $this->assertSame("3190316", $props['$user_id']);
        $this->assertSame("3190317", $props['$company_id']);
        $this->assertSame(5, $props['count']);

        $this->_hg_events->identify($ints);
        $this->_hg_events->track("Signup");
        $props = self::lastEventProperties($this->_hg_events);
        $this->assertSame("3190316", $props['$user_id']);
        $this->assertSame("3190317", $props['$company_id']);

        $this->_hg->people->set($ints, array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame("3190316", $msg['$group_id']);
        $this->assertSame("3190317", $msg['$company_id']);

        $acme = $this->_hg->forCompany(42);
        $acme->group->set("department", "finance", array("plan" => "pro"));
        $this->assertSame("42", self::lastMessage($this->_hg->group)['$company_id']);
        $acme->company->set(array("tier" => "enterprise"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame("42", $msg['$group_id']);
        $this->assertSame("42", $msg['$company_id']);
    }

    public function testStringIdsAreSentUnchangedForHierarchicalGroups() {
        $this->_hg_events->track("Signup", array("count" => 5), self::hgUser());
        $props = self::lastEventProperties($this->_hg_events);
        $this->assertSame("user_123", $props['$user_id']);
        $this->assertSame("acme", $props['$company_id']);
        $this->assertSame(5, $props['count']);

        $this->_hg->forCompany("acme")->people->set(self::hgUser(), array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame("user_123", $msg['$group_id']);
        $this->assertSame("acme", $msg['$company_id']);
    }

    public function testIntegerIdsAreUnchangedForLegacy() {
        $this->_legacy_events->track("Signup", array(), self::identity(array("user_id" => 7, "device_id" => 8)));
        $props = self::lastEventProperties($this->_legacy_events);
        $this->assertSame(7, $props['$user_id']);
        $this->assertSame(8, $props['$device_id']);

        $acme = $this->_legacy->forCompany(42);
        $acme->group->set("department", "finance", array("plan" => "pro"));
        $this->assertSame(42, self::lastMessage($this->_legacy->group)['$company_id']);
        $acme->company->set(array("tier" => "enterprise"));
        $msg = self::lastMessage($this->_legacy->group);
        $this->assertSame(42, $msg['$group_id']);
        $this->assertSame(42, $msg['$company_id']);
    }

    public function testCombinedDistinctIdConflictingWithPropertiesIsRejected() {
        // Mixpanel stored $company:c6|$user:u6 with $company_id zz6 as $company:zz6, dropping the user
        $events = $this->_hg_events;
        $combined = '$company:c6|$user:u6';

        $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $combined) {
            $events->track("Signup", array("distinct_id" => $combined, '$company_id' => "zz6"));
        });
        $this->assertEquals('distinct_id $company:c6|$user:u6 names company c6, but $company_id is zz6. '
            . 'Nothing sent', $e->getMessage());

        $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $combined) {
            $events->track("Signup", array("distinct_id" => $combined, '$user_id' => "u7"));
        });
        $this->assertEquals('distinct_id $company:c6|$user:u6 names user u6, but $user_id is u7. Nothing sent',
            $e->getMessage());

        // from super properties
        $events->identify($combined);
        $events->register('$company_id', "zz6");
        $this->assertThrows("InvalidArgumentException", function () use ($events) {
            $events->track("Signup");
        });
        $events->unregister('$company_id');
        $this->assertThrows("InvalidArgumentException", function () use ($events) {
            $events->track("Signup", array('$user_id' => 6));
        });
        $this->assertThrows("InvalidArgumentException", function () use ($events, $combined) {
            $events->track("Signup", array("distinct_id" => $combined, '$company_id' => array("x" => 1)));
        });

        $this->assertCount(0, $events->getQueue());
    }

    public function testCombinedDistinctIdMatchingPropertiesIsSentUnchanged() {
        $events = $this->_hg_events;
        $events->track("Signup", array("distinct_id" => '$company:c6|$user:u6', '$company_id' => "c6",
            '$user_id' => "u6"));
        $events->track("Signup", array("distinct_id" => '$company:6|$user:7', '$company_id' => 6, '$user_id' => 7));
        $events->track("Signup", array("distinct_id" => '$company:c6|$user:u6'));
        // a plain distinct id is not combined, so it is sent as given
        $events->track("Signup", array("distinct_id" => "user_123", '$company_id' => "zz6"));
        // an identity already decides its keys
        $events->track("Signup", array('$company_id' => "zz6"),
            self::identity(array("distinct_id" => '$company:c6|$user:u6')));

        $queue = $events->getQueue();
        $this->assertCount(5, $queue);
        $this->assertSame(6, $queue[1]['properties']['$company_id']);
        $this->assertSame(7, $queue[1]['properties']['$user_id']);
        $this->assertEquals("zz6", $queue[3]['properties']['$company_id']);
        $this->assertEquals('$company:c6|$user:u6', $queue[4]['properties']['distinct_id']);
        $this->assertEquals("zz6", $queue[4]['properties']['$company_id']);
    }

    public function testCombinedDistinctIdConflictIsUnchangedForLegacy() {
        $this->_legacy_events->track("Signup", array("distinct_id" => '$company:c6|$user:u6', '$company_id' => "zz6",
            '$user_id' => "u7"));
        $this->_legacy_events->track("Signup", array("distinct_id" => '$company: |$user:  '));
        $this->assertCount(2, $this->_legacy_events->getQueue());
        $props = self::lastEventProperties($this->_legacy_events);
        $this->assertEquals('$company: |$user:  ', $props['distinct_id']);
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
        $identity = self::identity(array("user_id" => " user_123 ", "company_id" => "acme "));
        $this->assertSame(" user_123 ", $identity->getUserId());
        $this->assertSame("acme ", $identity->getCompanyId());
    }

    public function testForCompanyRejectsWhitespaceOnly() {
        foreach (array($this->_hg, $this->_legacy) as $mp) {
            foreach (array(" ", "  ") as $blank) {
                $this->assertThrows("InvalidArgumentException", function () use ($mp, $blank) {
                    $mp->forCompany($blank);
                });
                $this->assertThrows("InvalidArgumentException", function () use ($mp, $blank) {
                    $mp->group->forCompany($blank);
                });
            }
            $mp->forCompany(" acme ")->company->set(array("tier" => "enterprise"));
            $this->assertSame(" acme ", self::lastMessage($mp->group)['$company_id']);
        }
    }

    public function testCombinedDistinctIdWithWhitespaceOnlyPartsIsRejected() {
        // Mixpanel stored these as the junk users "$company: |$user:" and "$company: |$user:  "
        $events = $this->_hg_events;
        $people = $this->_hg->people;
        foreach (array('$company: |$user:  ', '$company: |$user:u6', '$company:c6|$user: ') as $blank) {
            $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $blank) {
                $events->track("Signup", array("distinct_id" => $blank));
            });
            $this->assertMessageContains("only whitespace", $e);
            $this->assertThrows("InvalidArgumentException", function () use ($people, $blank) {
                $people->set($blank, array("plan" => "pro"));
            });
            $this->assertThrows("InvalidArgumentException", function () use ($people, $blank) {
                $people->set(new MixpanelIdentity(array("distinct_id" => $blank)), array("plan" => "pro"));
            });
        }
        $this->assertCount(0, $events->getQueue());
        $this->assertCount(0, $this->_hg->group->getQueue());

        // ids with surrounding spaces are not blank
        $events->track("Signup", array("distinct_id" => '$company: c6 |$user: u6'));
        $people->set('$company: c6 |$user: u6', array("plan" => "pro"));
        $msg = self::lastMessage($this->_hg->group);
        $this->assertSame(" u6", $msg['$group_id']);
        $this->assertSame(" c6 ", $msg['$company_id']);
    }

    public function testCombinedDistinctIdWithEmptyOrBlankPartsIsRejected() {
        $events = $this->_hg_events;
        $hg = $this->_hg;
        $blanks = array('$company:|$user:u', '$company:c|$user:', '$company:|$user:', '$company: |$user:',
            '$company:c|$user:  ');
        foreach ($blanks as $blank) {
            $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $blank) {
                $events->track("Signup", array("distinct_id" => $blank));
            });
            $this->assertMessageContains("distinct_id " . $blank . " names a company or user that is empty or only "
                . "whitespace. Nothing sent", $e);
            $this->assertThrows("InvalidArgumentException", function () use ($events, $blank) {
                $events->identify($blank);
                $events->track("Signup");
            });
            $calls = array(
                function () use ($hg, $blank) { $hg->people->set($blank, array("plan" => "pro")); },
                function () use ($hg, $blank) {
                    $hg->people->set(new MixpanelIdentity(array("distinct_id" => $blank)), array("plan" => "pro"));
                },
                function () use ($hg, $blank) { $hg->people->deleteUser($blank); },
            );
            foreach ($calls as $call) {
                $e = $this->assertThrows("InvalidArgumentException", $call);
                $this->assertMessageContains("names a company or user that is empty or only whitespace", $e);
            }
        }
        $this->assertCount(0, $events->getQueue());
        $this->assertCount(0, $hg->group->getQueue());
        $events->unregister("distinct_id");

        // a valid combined id is still sent
        $events->track("Signup", array("distinct_id" => '$company:c|$user:u'));
        $this->assertSame('$company:c|$user:u', self::lastEventProperties($events)['distinct_id']);
        $hg->people->set('$company:c|$user:u', array("plan" => "pro"));
        $msg = self::lastMessage($hg->group);
        $this->assertSame("u", $msg['$group_id']);
        $this->assertSame("c", $msg['$company_id']);

        // ids that are not combined are sent as given
        foreach (array('user_123', '$device:abc', '$company:acme', '$company:|$usr:u') as $id) {
            $events->track("Signup", array("distinct_id" => $id));
            $this->assertSame($id, self::lastEventProperties($events)['distinct_id']);
        }

        // without hierarchical groups nothing changes
        foreach ($blanks as $blank) {
            $this->_legacy_events->track("Signup", array("distinct_id" => $blank));
            $this->assertSame($blank, self::lastEventProperties($this->_legacy_events)['distinct_id']);
            $this->_legacy->people->set($blank, array("plan" => "pro"));
            $this->assertSame($blank, self::lastMessage($this->_legacy->people)['$distinct_id']);
        }
    }

    public function testIdentityWithCombinedDistinctIdWithEmptyOrBlankPartsIsRejected() {
        $events = $this->_hg_events;
        $blanks = array('$company:|$user:u', '$company:c|$user:', '$company:|$user:', '$company: |$user:',
            '$company:c|$user:  ');
        $valid = self::identity(array("distinct_id" => '$company:c|$user:u'));
        foreach ($blanks as $blank) {
            $identity = self::identity(array("distinct_id" => $blank));
            $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $identity) {
                $events->track("Signup", array(), $identity);
            });
            $this->assertMessageContains("distinct_id " . $blank . " names a company or user that is empty or only "
                . "whitespace. Nothing sent", $e);

            // identify refuses it and keeps the identity set earlier
            $events->identify($valid);
            $e = $this->assertThrows("InvalidArgumentException", function () use ($events, $identity) {
                $events->identify($identity);
            });
            $this->assertMessageContains("distinct_id " . $blank . " names a company or user that is empty or only "
                . "whitespace. Nothing sent", $e);
            $this->assertSame($valid, $events->getIdentity());
            $events->identify("plain");

            // the same through the Mixpanel facade
            $hg = $this->_hg;
            $this->assertThrows("InvalidArgumentException", function () use ($hg, $identity) {
                $hg->track("Signup", array(), $identity);
            });
            $this->assertThrows("InvalidArgumentException", function () use ($hg, $identity) {
                $hg->identify($identity);
            });
            $this->assertNull($hg->getIdentity());
        }
        $this->assertCount(0, $events->getQueue());

        // a valid combined id is still sent, per call and after identify
        $events->track("Signup", array(), $valid);
        $this->assertSame('$company:c|$user:u', self::lastEventProperties($events)['distinct_id']);
        $events->identify($valid);
        $events->track("Signup");
        $this->assertSame('$company:c|$user:u', self::lastEventProperties($events)['distinct_id']);

        // ids that are not combined are sent as given
        foreach (array('user_123', '$device:abc', '$company:acme', '$company:|$usr:u') as $id) {
            $events->track("Signup", array(), self::identity(array("distinct_id" => $id)));
            $this->assertSame($id, self::lastEventProperties($events)['distinct_id']);
        }

        // without hierarchical groups nothing changes
        $legacy = $this->_legacy_events;
        foreach ($blanks as $blank) {
            $identity = self::identity(array("distinct_id" => $blank));
            $legacy->track("Signup", array(), $identity);
            $this->assertSame($blank, self::lastEventProperties($legacy)['distinct_id']);
            $legacy->identify($identity);
            $legacy->track("Signup");
            $this->assertSame($blank, self::lastEventProperties($legacy)['distinct_id']);
        }
    }

    // #endregion
}
