<?php
/**
 * LICENSE
 *
 * Copyright © 2016-2017 Teclib'
 * Copyright © 2010-2016 by the FusionInventory Development Team.
 *
 * This file is part of Flyve MDM Plugin for GLPI.
 *
 * Flyve MDM Plugin for GLPI is a subproject of Flyve MDM. Flyve MDM is a mobile
 * device management software.
 *
 * Flyve MDM Plugin for GLPI is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * Flyve MDM Plugin for GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License
 * along with Flyve MDM Plugin for GLPI. If not, see http://www.gnu.org/licenses/.
 * ------------------------------------------------------------------------------
 * @author    Thierry Bugier Pineau
 * @copyright Copyright © 2017 Teclib
 * @license   AGPLv3+ http://www.gnu.org/licenses/agpl.txt
 * @link      https://github.com/flyve-mdm/flyve-mdm-glpi-plugin
 * @link      https://flyve-mdm.com/
 * ------------------------------------------------------------------------------
 */

namespace tests\units;

use Glpi\Test\CommonTestCase;
use stdClass;

class PluginFlyvemdmPackage  extends CommonTestCase {
   public function beforeTestMethod($method) {
      $this->resetState();
      parent::beforeTestMethod($method);
      $this->setupGLPIFramework();
      $this->login('glpi', 'glpi');
   }

   /**
    *
    */
   public function testCreateApplication() {
      global $DB;

      // Create an application (directly in DB) because we are not uploading any file
      $packageName = 'com.domain.author.application';
      $packageTable = \PluginFlyvemdmPackage::getTable();
      $entityId = $_SESSION['glpiactive_entity'];
      $query = "INSERT INTO $packageTable (
         `name`,
         `alias`,
         `version`,
         `filename`,
         `filesize`,
         `entities_id`,
         `dl_filename`,
         `icon`
      )
      VALUES (
         '$packageName',
         'application',
         '1.0.5',
         '$entityId/123456789_application_105.apk',
         '1048576',
         '$entityId',
         'application_105.apk',
         ''
      )";
      $DB->query($query);
      $mysqlError = $DB->error();
      $package = $this->newTestedinstance();
      $this->boolean($package->getFromDBByQuery("WHERE `name`='$packageName'"))->isTrue($mysqlError);

      $policyDataDeploy = new \PluginFlyvemdmPolicy();
      $this->boolean($policyDataDeploy->getFromDBBySymbol('deployApp'))->isTrue();

      $policyDataRemove = new \PluginFlyvemdmPolicy();
      $this->boolean($policyDataRemove->getFromDBBySymbol('removeApp'))->isTrue();

      $fleet = $this->createFleet();
      $task = $this->applyAddPackagePolicy($policyDataDeploy, $package, $fleet);
      $this->boolean($task->isNewItem())->isFalse();

      // delete the application
      $this->boolean($package->delete([
         'id' => $package->getID()
      ]))->isTrue();

      // Check the policy is removed
      $itemtype = $package->getType();
      $itemId = $policyDataDeploy->getID();
      $rows = $task->find("`itemtype`='$itemtype' AND `items_id`='$itemId'");
      $this->integer(count($rows))->isEqualTo(0);

      // Check a removal policy is added
      $policyId = $policyDataRemove->getID();
      $rows = $task->find("`plugin_flyvemdm_policies_id`='$policyId' AND `value`='$packageName'");
      $this->integer(count($rows))->isEqualTo(1);

      // Check adding a deploy policy conflicts with removal one
      $task = $this->applyAddPackagePolicy($policyDataDeploy, $package, $fleet);
      $this->boolean($task->isNewItem())->isTrue();
   }

   private function createFleet() {
      $fleet = $this->newMockInstance(\PluginFlyvemdmFleet::class, '\MyMock');
      $fleet->getMockController()->post_addItem = function() {};
      $fleet->add([
         'entities_id'     => $_SESSION['glpiactive_entity'],
         'name'            => 'a fleet'
      ]);
      $this->boolean($fleet->isNewItem())->isFalse();

      return $fleet;
   }

   private function applyAddPackagePolicy(\PluginFlyvemdmPolicy $policyData, \PluginFlyvemdmPackage $package, \PluginFlyvemdmFleet $fleet) {
      $value = new stdClass();
      $value->remove_on_delete = '1';

      $task = new \PluginFlyvemdmTask();
      $task->add([
         'plugin_flyvemdm_fleets_id'   => $fleet->getID(),
         'plugin_flyvemdm_policies_id' => $policyData->getID(),
         'value'                       => $value,
         'itemtype'                    => get_class($package),
         'items_id'                    => $package->getID(),
      ]);

      return $task;
   }

}
