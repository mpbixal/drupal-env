<?php

namespace DrupalEnv\Robo\Plugin\Commands;

use Robo\Tasks;

/**
 * Provide commands to handle installation tasks.
 *
 * @class RoboFile
 */
class DrupalEnvCommands extends Tasks
{

  /**
   * Allows installation
   *
   * @command drupal-env:init
   */
  public function dplInit() {
    $this->_exec('lando drush theme:enable claro');
    $this->_exec('lando drush config-set system.theme admin claro -y');
    $this->_exec('lando drush config-set node.settings use_admin_theme 1 -y');
    $this->_exec('lando drush theme:enable olivero');
    $this->_exec('lando drush config-set system.theme default olivero -y');

    $this->taskComposerRequire('./composer')
      ->dependency('drupal/core-dev')
      ->dev()
      ->run();

    $this->taskComposerRequire('./composer')
      ->dependency('mattsqd/robovalidate', '@alpha')
      ->dependency('platformsh/config-reader')
      ->dependency('drupal/admin_toolbar')
      ->dependency('drupal/devel')
      ->dependency('drupal/disable_user_1_edit')
      ->dependency('drupal/menu_admin_per_menu')
      ->dependency('drupal/redis')
      ->dependency('drupal/role_delegation')
      ->dependency('drupal/search_api_solr')
      ->dependency('drupal/twig_tweak')
      ->dependency('drupal/twig_field_value')
      ->run();

    $this->_exec('lando drush en -y admin_toolbar_search devel disable_user_1_edit menu_admin_per_menu redis role_delegation search_api_solr twig_tweak twig_field_value');

    // Create the config sync dir and export config to it.
    $this->taskFilesystemStack()->mkdir(['config/sync'], 0755)->run();
    $this->_exec('lando drush cex -y');
  }

}
