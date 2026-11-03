<?php

/**
 * Records every batch a producer flushes, with the endpoint it is sent to
 */
class HierarchicalGroupsScenariosTest_CaptureConsumer extends ConsumerStrategies_AbstractConsumer {

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
 * The hierarchical groups scenarios, one test each, asserting the endpoint each call hits and the exact message it
 * sends, or that it throws and sends nothing.
 */
class HierarchicalGroupsScenariosTest extends PHPUnit_Framework_TestCase {

    const TRACK = "/track";
    const ENGAGE = "/engage";
    const GROUPS = "/groups";

    const USER_ID = "my_user_id";
    const COMPANY_ID = "my_company_id";
    const TEAM_ID = "my_team_id";
    const DEVICE_ID = "my_device_id";
    const COMBINED_DISTINCT_ID = '$company:my_company_id|$user:my_user_id';

    /**
     * A client with hierarchical groups on
     * @var Mixpanel
     */
    protected $_mp = null;

    /**
     * A client with hierarchical groups off
     * @var Mixpanel
     */
    protected $_legacy = null;

    protected function setUp() {
        parent::setUp();
        $capture = array(
            "consumers" => array("capture" => "HierarchicalGroupsScenariosTest_CaptureConsumer"),
            "consumer" => "capture"
        );
        $this->_mp = new Mixpanel("token", array_merge($capture, array("hierarchical_groups" => true)));
        $this->_legacy = new Mixpanel("token", $capture);
        HierarchicalGroupsScenariosTest_CaptureConsumer::$sent = array();
    }

    protected function tearDown() {
        parent::tearDown();
        foreach (array($this->_mp, $this->_legacy) as $mp) {
            $mp->reset();
            $mp->people->reset();
            $mp->group->reset();
        }
        $this->_mp = $this->_legacy = null;
        HierarchicalGroupsScenariosTest_CaptureConsumer::$sent = array();
    }

    private static function user() {
        return new MixpanelIdentity(array("user_id" => self::USER_ID, "company_id" => self::COMPANY_ID));
    }

    private static function userWithDevice() {
        return new MixpanelIdentity(array("user_id" => self::USER_ID, "company_id" => self::COMPANY_ID,
            "device_id" => self::DEVICE_ID));
    }

    /**
     * Flushes every queue of $mp and returns what was sent
     */
    private static function sent($mp) {
        $mp->flush();
        $mp->people->flush();
        $mp->group->flush();
        return HierarchicalGroupsScenariosTest_CaptureConsumer::$sent;
    }

    private function onlySent($mp, $endpoint) {
        $sent = self::sent($mp);
        $this->assertCount(1, $sent, "Expected exactly one message");
        $this->assertEquals($endpoint, $sent[0][0]);
        return $sent[0][1];
    }

    private function assertNothingSent($mp) {
        $this->assertCount(0, self::sent($mp), "Expected no messages");
    }

    /**
     * Asserts the single /track message and returns its properties
     */
    private function onlyEvent($mp, $event, $expected_keys) {
        $msg = $this->onlySent($mp, self::TRACK);
        $this->assertEquals(array("event", "properties"), array_keys($msg));
        $this->assertEquals($event, $msg['event']);
        $props = $msg['properties'];
        $this->assertKeys(array_merge(array("mp_lib", "token", "time"), $expected_keys), $props);
        $this->assertEquals("php", $props['mp_lib']);
        $this->assertEquals("token", $props['token']);
        return $props;
    }

    private function assertIdentified($props, $device_id = null) {
        $this->assertEquals(self::USER_ID, $props['$user_id']);
        $this->assertEquals(self::COMPANY_ID, $props['$company_id']);
        $this->assertArrayNotHasKey("distinct_id", $props);
        if ($device_id === null) {
            $this->assertArrayNotHasKey('$device_id', $props);
        } else {
            $this->assertEquals($device_id, $props['$device_id']);
        }
    }

    /**
     * Asserts the single /groups message has exactly the hierarchical groups keys and one operation, and returns
     * the operation's value
     */
    private function onlyGroupsUpdate($mp, $group_key, $group_id, $operation) {
        $msg = $this->onlySent($mp, self::GROUPS);
        $this->assertKeys(array('$token', '$group_key', '$group_id', '$time', '$company_id', $operation), $msg);
        $this->assertEquals("token", $msg['$token']);
        $this->assertSame($group_key, $msg['$group_key']);
        $this->assertSame($group_id, $msg['$group_id']);
        $this->assertSame(self::COMPANY_ID, $msg['$company_id']);
        return $msg[$operation];
    }

    private function onlyUserUpdate($mp, $operation) {
        return $this->onlyGroupsUpdate($mp, '$user_id', self::USER_ID, $operation);
    }

    private function onlyCompanyUpdate($mp, $operation) {
        return $this->onlyGroupsUpdate($mp, '$company_id', self::COMPANY_ID, $operation);
    }

    private function onlyTeamUpdate($mp, $operation) {
        return $this->onlyGroupsUpdate($mp, "team_id", self::TEAM_ID, $operation);
    }

    private function assertKeys($expected, $array) {
        $actual = array_keys($array);
        sort($expected);
        sort($actual);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Runs $call and fails unless it throws exactly $class
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

    private function forCompany() {
        return $this->_mp->forCompany(self::COMPANY_ID);
    }

    // #region Events

    public function testTrackAnonymous() {
        $this->_mp->track("HG Event!", array("source" => "hierarchical_groups_page"),
            new MixpanelIdentity(array("device_id" => self::DEVICE_ID)));

        $props = $this->onlyEvent($this->_mp, "HG Event!", array('$device_id', "source"));
        $this->assertEquals(self::DEVICE_ID, $props['$device_id']);
        $this->assertEquals("hierarchical_groups_page", $props['source']);
    }

    public function testTrackIdentified() {
        $this->_mp->track("HG Event!", array(), self::userWithDevice());

        $props = $this->onlyEvent($this->_mp, "HG Event!", array('$user_id', '$company_id', '$device_id'));
        $this->assertIdentified($props, self::DEVICE_ID);
    }

    public function testTrackIdentifiedWithoutDevice() {
        $this->_mp->track("HG Event!", array(), self::user());

        $props = $this->onlyEvent($this->_mp, "HG Event!", array('$user_id', '$company_id'));
        $this->assertIdentified($props);
    }

    public function testTrackWithCustomGroup() {
        $this->_mp->track("HG Event!", array("team_id" => self::TEAM_ID), self::user());

        $props = $this->onlyEvent($this->_mp, "HG Event!", array('$user_id', '$company_id', "team_id"));
        $this->assertIdentified($props);
        $this->assertSame(self::TEAM_ID, $props['team_id']);
    }

    public function testIdentityOverridesProperties() {
        $this->_mp->track("HG Event!", array('$user_id' => "other_user", '$company_id' => "other_company",
            "plan" => "pro"), self::user());

        $props = $this->onlyEvent($this->_mp, "HG Event!", array('$user_id', '$company_id', "plan"));
        $this->assertIdentified($props);
        $this->assertEquals("pro", $props['plan']);
    }

    public function testTrackWithCombinedDistinctId() {
        $this->_mp->identify(self::COMBINED_DISTINCT_ID);
        $this->_mp->track("HG Event!");

        $props = $this->onlyEvent($this->_mp, "HG Event!", array("distinct_id"));
        $this->assertEquals(self::COMBINED_DISTINCT_ID, $props['distinct_id']);
    }

    public function testTrackWithCombinedDistinctIdIdentity() {
        $this->_mp->track("HG Event!", array(),
            new MixpanelIdentity(array("distinct_id" => self::COMBINED_DISTINCT_ID)));

        $props = $this->onlyEvent($this->_mp, "HG Event!", array("distinct_id"));
        $this->assertEquals(self::COMBINED_DISTINCT_ID, $props['distinct_id']);
    }

    // #endregion
    // #region User profile

    public function testSetUserProperty() {
        $this->_mp->people->set(self::user(), array("plan" => "pro"));

        $this->assertEquals(array("plan" => "pro"), $this->onlyUserUpdate($this->_mp, '$set'));
    }

    public function testSetUserPropertyWithCombinedDistinctId() {
        $this->_mp->people->set(self::COMBINED_DISTINCT_ID, array("plan" => "pro"));

        $this->assertEquals(array("plan" => "pro"), $this->onlyUserUpdate($this->_mp, '$set'));
    }

    public function testSetUserPropertyWithCombinedDistinctIdIdentity() {
        $this->_mp->people->set(new MixpanelIdentity(array("distinct_id" => self::COMBINED_DISTINCT_ID)),
            array("plan" => "pro"));

        $this->assertEquals(array("plan" => "pro"), $this->onlyUserUpdate($this->_mp, '$set'));
    }

    public function testSetUserPropertyWithDistinctIdUnsupported() {
        $people = $this->_mp->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->set(HierarchicalGroupsScenariosTest::USER_ID, array("plan" => "pro"));
        });
        $this->assertNothingSent($this->_mp);
    }

    public function testSetUserProperties() {
        $this->_mp->people->set(self::user(), array("plan" => "pro", "seats" => 5));

        $this->assertEquals(array("plan" => "pro", "seats" => 5), $this->onlyUserUpdate($this->_mp, '$set'));
    }

    public function testSetOnceUserProperty() {
        $this->_mp->people->setOnce(self::user(), array("first_seen" => "today"));

        $this->assertEquals(array("first_seen" => "today"), $this->onlyUserUpdate($this->_mp, '$set_once'));
    }

    public function testUnionUserProperty() {
        $this->_mp->people->append(self::user(), "devices", array("android"));

        $this->assertEquals(array("devices" => array("android")), $this->onlyUserUpdate($this->_mp, '$union'));
    }

    public function testUnsetUserProperty() {
        $this->_mp->people->remove(self::user(), array("plan"));

        $this->assertEquals(array("plan"), $this->onlyUserUpdate($this->_mp, '$unset'));
    }

    public function testDeleteUser() {
        $this->_mp->people->deleteUser(self::user());

        $this->assertSame("", $this->onlyUserUpdate($this->_mp, '$delete'));
    }

    public function testClearChargesUnsupported() {
        $people = $this->_mp->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->clearCharges(HierarchicalGroupsScenariosTest::userForClosure());
        });
        $this->assertNothingSent($this->_mp);
    }

    public function testIncrementUserPropertyUnsupported() {
        $people = $this->_mp->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->increment(HierarchicalGroupsScenariosTest::userForClosure(), "logins", 1);
        });
        $this->assertNothingSent($this->_mp);
    }

    public function testAppendUserPropertyUnsupported() {
        $people = $this->_mp->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->append(HierarchicalGroupsScenariosTest::userForClosure(), "tags", "a");
        });
        $this->assertNothingSent($this->_mp);
    }

    public function testTrackChargeUnsupported() {
        $people = $this->_mp->people;
        $this->assertThrows("InvalidArgumentException", function () use ($people) {
            $people->trackCharge(HierarchicalGroupsScenariosTest::userForClosure(), "9.99");
        });
        $this->assertNothingSent($this->_mp);
    }

    // #endregion
    // #region Company

    public function testSetCompanyProperty() {
        $this->forCompany()->group->set('$company_id', self::COMPANY_ID, array("tier" => "enterprise"));

        $this->assertEquals(array("tier" => "enterprise"), $this->onlyCompanyUpdate($this->_mp, '$set'));
    }

    public function testSetCompanyProperties() {
        $this->forCompany()->group->set('$company_id', self::COMPANY_ID, array("tier" => "enterprise",
            "employees" => 500));

        $this->assertEquals(array("tier" => "enterprise", "employees" => 500),
            $this->onlyCompanyUpdate($this->_mp, '$set'));
    }

    public function testSetOnceCompanyProperty() {
        $this->forCompany()->group->setOnce('$company_id', self::COMPANY_ID, array("founded" => 2009));

        $this->assertEquals(array("founded" => 2009), $this->onlyCompanyUpdate($this->_mp, '$set_once'));
    }

    public function testUnionCompanyProperty() {
        $this->forCompany()->group->union('$company_id', self::COMPANY_ID, "regions", array("us", "eu"));

        $this->assertEquals(array("regions" => array("us", "eu")), $this->onlyCompanyUpdate($this->_mp, '$union'));
    }

    public function testRemoveCompanyProperty() {
        $this->forCompany()->group->remove('$company_id', self::COMPANY_ID, array("regions" => "eu"));

        $this->assertEquals(array("regions" => "eu"), $this->onlyCompanyUpdate($this->_mp, '$remove'));
    }

    // #endregion
    // #region Custom groups

    public function testSetUsersCustomGroup() {
        $this->forCompany()->people->set(self::user(), array("team_id" => self::TEAM_ID));

        $set = $this->onlyUserUpdate($this->_mp, '$set');
        $this->assertSame(array("team_id" => self::TEAM_ID), $set);
    }

    public function testRemoveUsersCustomGroup() {
        $this->forCompany()->people->remove(self::user(), array("team_id"));

        $this->assertEquals(array("team_id"), $this->onlyUserUpdate($this->_mp, '$unset'));
    }

    public function testSetCustomGroupProperty() {
        $this->forCompany()->group->set("team_id", self::TEAM_ID, array("name" => "Platform"));

        $this->assertEquals(array("name" => "Platform"), $this->onlyTeamUpdate($this->_mp, '$set'));
    }

    public function testSetCustomGroupProperties() {
        $this->forCompany()->group->set("team_id", self::TEAM_ID, array("name" => "Platform", "size" => 8));

        $this->assertEquals(array("name" => "Platform", "size" => 8), $this->onlyTeamUpdate($this->_mp, '$set'));
    }

    public function testSetOnceCustomGroupProperty() {
        $this->forCompany()->group->setOnce("team_id", self::TEAM_ID, array("created" => "today"));

        $this->assertEquals(array("created" => "today"), $this->onlyTeamUpdate($this->_mp, '$set_once'));
    }

    public function testUnionCustomGroupProperty() {
        $this->forCompany()->group->union("team_id", self::TEAM_ID, "tools", array("jira", "slack"));

        $this->assertEquals(array("tools" => array("jira", "slack")), $this->onlyTeamUpdate($this->_mp, '$union'));
    }

    public function testRemoveCustomGroupProperty() {
        $this->forCompany()->group->remove("team_id", self::TEAM_ID, array("tools" => "jira"));

        $this->assertEquals(array("tools" => "jira"), $this->onlyTeamUpdate($this->_mp, '$remove'));
    }

    public function testDeleteCompany() {
        $this->forCompany()->group->deleteGroup('$company_id', self::COMPANY_ID);

        $this->assertSame("", $this->onlyCompanyUpdate($this->_mp, '$delete'));
    }

    public function testDeleteCustomGroup() {
        $this->forCompany()->group->deleteGroup("team_id", self::TEAM_ID);

        $this->assertSame("", $this->onlyTeamUpdate($this->_mp, '$delete'));
    }

    // #endregion
    // #region Unsupported / rejected

    public function testIdentifyWithoutCompany() {
        $mp = $this->_mp;
        $identity = new MixpanelIdentity(array("user_id" => self::USER_ID));

        $this->assertThrows("InvalidArgumentException", function () use ($mp, $identity) {
            $mp->people->set($identity, array("plan" => "pro"));
        });
        $this->assertThrows("InvalidArgumentException", function () use ($mp, $identity) {
            $mp->track("HG Event!", array(), $identity);
        });
        $this->assertThrows("InvalidArgumentException", function () use ($mp, $identity) {
            $mp->identify($identity);
        });
        $this->assertNothingSent($mp);
    }

    public function testSetUserPropertyWithoutUser() {
        $mp = $this->_mp;
        $this->assertThrows("InvalidArgumentException", function () use ($mp) {
            $mp->people->set(new MixpanelIdentity(array("company_id" => HierarchicalGroupsScenariosTest::COMPANY_ID)),
                array("plan" => "pro"));
        });
        $this->assertNothingSent($mp);
    }

    public function testSetCompanyPropertyWithoutCompany() {
        $mp = $this->_mp;
        $this->assertThrows("LogicException", function () use ($mp) {
            $mp->group->set('$company_id', HierarchicalGroupsScenariosTest::COMPANY_ID, array("tier" => "enterprise"));
        });
        $this->assertNothingSent($mp);
    }

    public function testSetCustomGroupPropertyWithoutCompany() {
        $mp = $this->_mp;
        $this->assertThrows("LogicException", function () use ($mp) {
            $mp->group->set("team_id", HierarchicalGroupsScenariosTest::TEAM_ID, array("name" => "Platform"));
        });
        $this->assertNothingSent($mp);
    }

    public function testCompanyIdentityWithoutHierarchicalGroups() {
        $mp = $this->_legacy;
        $this->assertThrows("InvalidArgumentException", function () use ($mp) {
            $mp->track("HG Event!", array(), HierarchicalGroupsScenariosTest::userForClosure());
        });
        $this->assertThrows("InvalidArgumentException", function () use ($mp) {
            $mp->identify(HierarchicalGroupsScenariosTest::userForClosure());
        });
        $this->assertThrows("InvalidArgumentException", function () use ($mp) {
            $mp->people->set(HierarchicalGroupsScenariosTest::userForClosure(), array("plan" => "pro"));
        });
        $this->assertNothingSent($mp);
    }

    public function testAliasUnsupported() {
        // A user is identified by company and user id, so there is nothing to alias
        $mp = $this->_mp;
        $this->assertThrows("BadMethodCallException", function() use ($mp) {
            $mp->createAlias(self::USER_ID, "alias_id");
        });
        $this->assertNothingSent($this->_mp);
    }

    // #endregion
    // #region Without hierarchical groups

    public function testTrackWithDistinctId() {
        $this->_legacy->identify(self::USER_ID);
        $this->_legacy->track("Event!", array("plan" => "pro"));

        $props = $this->onlyEvent($this->_legacy, "Event!", array("distinct_id", "plan"));
        $this->assertEquals(self::USER_ID, $props['distinct_id']);
        $this->assertArrayNotHasKey('$user_id', $props);
        $this->assertArrayNotHasKey('$company_id', $props);
    }

    public function testSetUserPropertyWithDistinctId() {
        $this->_legacy->people->set(self::USER_ID, array("plan" => "pro"));

        $msg = $this->onlySent($this->_legacy, self::ENGAGE);
        $this->assertKeys(array('$token', '$distinct_id', '$time', '$set'), $msg);
        $this->assertEquals(self::USER_ID, $msg['$distinct_id']);
        $this->assertEquals(array("plan" => "pro"), $msg['$set']);
    }

    public function testSetGroupProperty() {
        $this->_legacy->group->set("team_id", self::TEAM_ID, array("name" => "Platform"));

        $msg = $this->onlySent($this->_legacy, self::GROUPS);
        $this->assertKeys(array('$token', '$group_key', '$group_id', '$time', '$set'), $msg);
        $this->assertEquals("team_id", $msg['$group_key']);
        $this->assertEquals(self::TEAM_ID, $msg['$group_id']);
        $this->assertEquals(array("name" => "Platform"), $msg['$set']);
    }

    // #endregion

    public static function userForClosure() {
        return self::user();
    }
}
