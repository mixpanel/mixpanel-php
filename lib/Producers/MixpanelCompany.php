<?php
require_once(dirname(__FILE__) . "/MixpanelGroups.php");

/**
 * Provides an API to update the profile of the company a client is bound to by forCompany(), so the company is
 * named once:
 *
 * $acme = $mp->forCompany("acme");
 * $acme->company->set(array("tier" => "enterprise"));
 *
 * Each method queues exactly what the matching Producers_MixpanelGroups method queues with group key '$company_id'
 * and the bound company as group id, so the call above is the same as
 *
 * $acme->group->set('$company_id', "acme", array("tier" => "enterprise"));
 *
 * On a client with no bound company, every method throws LogicException, whether or not the project uses
 * hierarchical groups.
 */
class Producers_MixpanelCompany {

    /**
     * The group producer that sends the company profile updates
     * @var Producers_MixpanelGroups
     */
    private $_group;


    /**
     * You won't need to instantiate this class directly. Use $mp->forCompany($company_id)->company.
     * @param Producers_MixpanelGroups $group the client's group producer; its bound company is the company updated
     */
    public function __construct($group) {
        $this->_group = $group;
    }


    /**
     * Returns the company bound by forCompany()
     * @return string|int
     * @throws LogicException if no company is bound
     */
    private function _companyId() {
        $company_id = $this->_group->getCompanyId();
        if ($company_id === null) {
            throw new LogicException("Company profile updates require a company. "
                . "Use forCompany(\$company_id) to send them, e.g. \$mp->forCompany(\$company_id)->company->set(...).");
        }
        return $company_id;
    }

    /**
     * Set properties on the company profile, overwriting existing values. See Producers_MixpanelGroups::set().
     * @param array $props associative array of properties to set on the company profile
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the company profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @throws LogicException if no company is bound
     */
    public function set($props, $ignore_time = false) {
        $this->_group->set('$company_id', $this->_companyId(), $props, $ignore_time);
    }

    /**
     * Set properties on the company profile, but WILL NOT overwrite existing values. See
     * Producers_MixpanelGroups::setOnce().
     * @param array $props associative array of properties to set on the company profile
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the company profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @throws LogicException if no company is bound
     */
    public function setOnce($props, $ignore_time = false) {
        $this->_group->setOnce('$company_id', $this->_companyId(), $props, $ignore_time);
    }

    /**
     * Remove values from list properties on the company profile. See Producers_MixpanelGroups::remove().
     * @param array $props associative array of list properties and the values to remove from them
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the company profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @throws LogicException if no company is bound
     */
    public function remove($props, $ignore_time = false) {
        $this->_group->remove('$company_id', $this->_companyId(), $props, $ignore_time);
    }

    /**
     * Adds $val to a list located at $prop on the company profile, keeping the list's values unique. See
     * Producers_MixpanelGroups::union().
     * @param string $prop the property that holds the list
     * @param string|array $val items to add to the list
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the company profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @throws LogicException if no company is bound
     */
    public function union($prop, $val, $ignore_time = false) {
        $this->_group->union('$company_id', $this->_companyId(), $prop, $val, $ignore_time);
    }

    /**
     * Delete the company profile from Mixpanel. See Producers_MixpanelGroups::deleteGroup().
     * @param boolean $ignore_time If the $ignore_time property is true, Mixpanel will not automatically update the "Last Seen" property of the profile. Otherwise, Mixpanel will add a "Last Seen" property associated with the current time
     * @throws LogicException if no company is bound
     */
    public function deleteCompany($ignore_time = false) {
        $this->_group->deleteGroup('$company_id', $this->_companyId(), $ignore_time);
    }

}
