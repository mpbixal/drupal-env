<?php

namespace DrupalEnv\Robo\Plugin\Commands;

use Robo\Tasks;

/**
 * Provide commands to handle installation tasks.
 *
 * @class RoboFile
 */
class DrupalEnvCommands extends DrupalEnvCommandsBase
{

  /**
   * {@inheritdoc}
   */
  protected string $package_name = 'mpbixal/drupal-env';

  /**
   * Update the environment so that the scaffolding can happen, and run it.
   *
   * @command drupal-env:scaffold
   */
  public function scaffold(): void
  {
    $this->updateScaffolding();
  }

  /**
   * {@inheritdoc}
   */
  protected function preScaffoldChanges(): void
  {
    $composer_json = $this->getComposerJson();

    // Ensure that settings.php is in place, so it can be appended to by the
    // scaffolding.
    $web_root = $composer_json['extra']['drupal-scaffold']['locations']['web-root'] ?? 'web';
    $web_root = rtrim($web_root, '/');
    if (!file_exists("$web_root/sites/default/settings.php") && file_exists("$web_root/sites/default/default.settings.php")) {
      $this->_copy("$web_root/sites/default/default.settings.php", "$web_root/sites/default/settings.php");
    }

    // Add autoloading so that the robo tasks that are scaffolded in will work.
    if (!in_array('./RoboEnv/', $composer_json['autoload']['psr-4'] ?? [])) {
      $composer_json['autoload']['psr-4']['RoboEnv\\'] = './RoboEnv/';
      $this->saveComposerJson($composer_json);
    }

    // Create the config sync directory.
    if (!is_dir('config/sync')) {
      $this->taskFilesystemStack()->mkdir(['config/sync'], 0755)->run();
    }

    // Ensure .gitignore exists, so it can be appended to.
    if (!file_exists('.gitignore')) {
      $this->taskFilesystemStack()->touch('.gitignore')->run();
    }

    // Ensure orchestration and shortcuts can be executed.
    $composer_json = $this->getComposerJson();
    $post_drupal_scaffold_cmds = $composer_json['scripts']['post-drupal-scaffold-cmd'] ?? [];
    $results = array_filter($post_drupal_scaffold_cmds, function($key) use ($post_drupal_scaffold_cmds) {
      // Only search by this partial text which should never change, that way
      // if the files that get modified get updated, then this command will be
      // updated instead of adding a new.
      return str_contains($post_drupal_scaffold_cmds[$key], 'Allowing orchestration files to be executed');
    }, ARRAY_FILTER_USE_KEY);
    $post_drupal_scaffold_cmd = "echo 'Allowing orchestration files to be executed...' & chmod -f +x ./orch/*.sh ./composer ./php ./robo ./drsh";
    if (!empty($results)) {
      foreach ($results as $key => $result) {
        if ($result !== $post_drupal_scaffold_cmd) {
          $composer_json['scripts']['post-drupal-scaffold-cmd'][$key] = $post_drupal_scaffold_cmd;
          $this->saveComposerJson($composer_json);
        }
      }
    } else {
      $composer_json['scripts']['post-drupal-scaffold-cmd'][] = $post_drupal_scaffold_cmd;
      $this->saveComposerJson($composer_json);
    }
  }

}
