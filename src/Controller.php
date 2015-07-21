<?php
/**
 * Created by PhpStorm.
 * User: egorov
 * Date: 17.07.2015
 * Time: 9:20
 */
namespace samsoncms\security;

use samson\activerecord\dbQuery;
use samsonframework\orm\Relation;

/**
 * SamsonCMS security controller
 * @package samsoncms\security
 */
class Controller extends \samsoncms\Application
{
    /** Application access right name pattern */
    const RIGHT_APPLICATION_KEY = '/^APPLICATION_(?<application>.*)/ui';

    /** @var array User group rights cache */
    protected $rightsCache = array();

    /** @var \samsonframework\orm\QueryInterface */
    protected $db;

    /** @var bool Do not show this application in main menu */
//    public $hide = true;

    /** Application name */
    public $name = 'Права';

    /** Application description */
    public $description = 'Права доступа';

    /** Application icon*/
    public $icon = 'unlock';

    /** Identifier */
    public $id = 'security';

    /** @var string Module identifier */
    protected $entity = '\samson\activerecord\group';

    protected $formClassName = '\samsoncms\app\security\form\Form';

    /**
     * Core routing(core.routing) event handler
     * @param \samson\Core $core
     * @param bollean $securityResult
     */
    public function handle(&$core, &$securityResult)
    {
        // Remove URL base from current URL, split by '/'
        $parts = explode('/', str_ireplace(__SAMSON_BASE__, '', $_SERVER['REQUEST_URI']));

        // Get module identifier
        $module = isset($parts[0]) ? $parts[0] : '';
        // Get action identifier
        $action = isset($parts[1]) ? $parts[1] : '';
        // Get parameter values collection
        $params = sizeof($parts) > 2 ? array_slice($parts, 2) : array();

        // If we have are authorized
        if (m('social')->authorized()) {
            /**@var \samson\avticerecord\user Get authorized user object */
            $authorizedUser = m('social')->user();

            // Try to load security group rights from cache
            $userRights = & $this->rightsCache[$authorizedUser->group_id];
            if (!isset($userRights)) {
                // Parse security group rights and store it to cache
                $userRights = $this->parseGroupRights($authorizedUser->group_id);
            }

            // Hide all applications except with access rights
            foreach (self::$loaded as $application) {
                if (!in_array($application->id, $userRights['application']) && !in_array(Right::APPLICATION_ACCESS_ALL, $userRights['application'])) {
                    $application->hide = true;
                }
            }

            // If we have full right to access all applications
            if (in_array(Right::APPLICATION_ACCESS_ALL, $userRights['application'])) {
                return $securityResult = true;
            } else if (in_array($module, $userRights['application'])) { // Try to find right to access current application
                return $securityResult = true;
            } else if ($module == '' && in_array('template', $userRights['application'])) {// Main page(empty url)
                return $securityResult = true;
            } else { // We cannot access this application
                return $securityResult = false;
            }
        }
    }

    /**
     * Parse application access right
     * @param string $rightName Right name
     * @return string Application name
     */
    private function matchApplicationAccessRight($rightName, &$applicationName)
    {
        // Parse application access rights
        $matches = array();
        if (preg_match(Right::APPLICATION_ACCESS_PATTERN, $rightName, $matches)) {
            // Return application name
            $applicationName = strtolower($matches['application']);
            return true;
        }

        return false;
    }

    /**
     * Parse database application user group rights
     * @param integer $groupID Security group identifier
     * @return array Parsed user group rights
     */
    public function parseGroupRights($groupID)
    {
        /** @var array $parsedRights Parsed rights */
        $parsedRights = array('application' => array());

        /** @var \samsonframework\orm\Record[] $groupRights Collection of user rights */
        $groupRights = array();
        // Retrieve all user group rights
        if ($this->db->className('groupright')->join('right')->cond('GroupID', $groupID)->exec($groupRights)) {
            // Iterate all group rights
            foreach ($groupRights as $groupRight) {
                // If we have rights for this group
                if (isset($groupRight->onetomany['_right'])) {
                    foreach ($groupRight->onetomany['_right'] as $userRight) {
                        // Parse application access rights
                        $applicationID = '';
                        if ($this->matchApplicationAccessRight($userRight->Name, $applicationID)) {
                            $parsedRights['application'][] = $applicationID;
                        }
                    }
                }
            }
        }

        return $parsedRights;
    }


    public function changeRights($entity)
    {
        // right for current entity
        $entityRightIDs = dbQuery('groupright')->cond('GroupID', $entity->id)->fields('RightID');

        // all rights
        $right = dbQuery('right')->exec();

        $chbView = '';

        foreach ($right as $item) {
            if (in_array($item->id, $entityRightIDs)) {
                $chbView .= "<div class='input-container'>";
                $chbView .= '<label><input type="checkbox" checked value="1">' . $item->Name . '</label>';
                $chbView .= "<input type='hidden' name='__action' value='/'>";
                $chbView .= "</div>";
            } else {
                $chbView .= "<div class='input-container'>";
                $chbView .= '<label><input type="checkbox" value="1">' . $item->Name . '</label>';
                $chbView .= "<input type='hidden' name='__action' value='".module_url('change_entity_right')."'>";
                $chbView .= "</div>";
            }
        }

        return $this->view('form/tab_item')->chbView($chbView)->output();
    }

    /** Application initialization */
    public function init(array $params = array())
    {
        // Create database query language
        $this->db = dbQuery('right');

        // Find all applications that needs access rights to it
        $accessibleApplications = array(
            'template' => 'template',   // Main application
            Right::APPLICATION_ACCESS_ALL => Right::APPLICATION_ACCESS_ALL // All application
        );

        // Iterate all loaded applications
        foreach (self::$loaded as $application) {
            // Iterate only applications with names
            $accessibleApplications[$application->id] = $application->name;
        }

        // Go throw all rights and remove unnecessary
//        foreach ($this->db->className('right')->exec() as $right) {
//            // Match application access rights
//            $applicationID = '';
//            if ($this->matchApplicationAccessRight($right->Name, $applicationID)) {
//                // If there is no such application that access right exists
//                if(!isset($accessibleApplications[$applicationID])) {
//                    $right->delete();
//                }
//            }
//        }

        // Iterate all applications that needs access rights
        foreach ($accessibleApplications as $accessibleApplicationID => $accessibleApplicationName) {
            // Try to find this right in db
            if (!$this->db->className('right')->cond('Name', Right::APPLICATION_ACCESS_TEMPLATE.$accessibleApplicationID)->first()) {
                $right = new Right();
                $right->Name = Right::APPLICATION_ACCESS_TEMPLATE.strtoupper($accessibleApplicationID);
                $right->Active = 1;
                $right->save();
            }
        }

        // Subscribe to core security event
        \samsonphp\event\Event::subscribe('core.security', array($this, 'handle'));
    }

    /**
     * Delete entity
     * @return array Asynchronous response array
     */
    public function __async_remove2($identifier)
    {
        if (dbQuery($this->entity)->id($identifier)->first($entity)) {
            $entity->delete();
        }
        return array('status' => 1);
    }
}
