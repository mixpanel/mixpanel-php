<?php

class MixpanelCompanyProducerTest extends \PHPUnit\Framework\TestCase {

    /**
     * @var Mixpanel
     */
    protected $_hg = null;

    /**
     * @var Mixpanel
     */
    protected $_legacy = null;

    protected function setUp(): void {
        parent::setUp();
        $this->_hg = new Mixpanel("token", array("hierarchical_groups" => true));
        $this->_legacy = new Mixpanel("token");
    }

    protected function tearDown(): void {
        parent::tearDown();
        foreach (array($this->_hg, $this->_legacy) as $mp) {
            $mp->reset();
            $mp->people->reset();
            $mp->group->reset();
        }
        $this->_hg = $this->_legacy = null;
    }

    private static function lastMessage($producer) {
        $queue = $producer->getQueue();
        return $queue[count($queue) - 1];
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
}
