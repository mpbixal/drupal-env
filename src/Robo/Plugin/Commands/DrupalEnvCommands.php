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
   * Do tasks that are required before the scaffolding can be applied.
   *
   * @command drupal-env:init
   */
  public function dplInit() {
    $composer_path = 'composer';
    if (!`which $composer_path`) {
      if (!`which docker`) {
        throw new \Exception('Either composer or docker must be installed to continue');
      }
      $composer_path = 'docker run --rm -i --tty -v $PWD:/app composer:2';
    }
    $composer_json = json_decode(file_get_contents('composer.json'), true);
    // Make sure that our scaffolding can run.
    if (empty($composer_json['extra']['drupal-scaffold']['allowed-packages']) || !in_array('mpbixal/drupal-env', $composer_json['extra']['drupal-scaffold']['allowed-packages'] ?? [])) {
      $this->_exec($composer_path . ' config extra.drupal-scaffold.allowed-packages --json --merge \'["mpbixal/drupal-env"]\'');
    }
    $web_root = $composer_json['extra']['drupal-scaffold']['locations']['web-root'] ?? 'web';
    $web_root = rtrim($web_root, '/');
    // Ensure that settings.php is in place so it can be appended to by the
    // scaffolding.
    if (!file_exists("$web_root/sites/default/settings.php") && file_exists("$web_root/sites/default/default.settings.php")) {
      $this->_copy("$web_root/sites/default/default.settings.php", "$web_root/sites/default/settings.php");
    }
    // Add autoloading so that the robo tasks that are scaffolded in will work.
    if (empty($composer_json['autoload']['psr-4']) || !in_array('./RoboEnv/', $composer_json['autoload']['psr-4'] ?? [])) {
      $composer_json['autoload']['psr-4']['RoboEnv\\'] = './RoboEnv/';
      // composer config does not support 'autoload'.
      file_put_contents('composer.json', json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    // Create the config sync directory.
    if (!is_dir('config/sync')) {
      $this->taskFilesystemStack()->mkdir(['config/sync'], 0755)->run();
    }

    // Ensure .gitignore exists so it can be appended to.
    if (!file_exists('.gitignore')) {
      $this->taskFilesystemStack()->touch('.gitignore')->run();
    }

    // Now that everything is ready, run the scaffolding.
    $this->_exec($composer_path . ' drupal:scaffold');
  }

}
