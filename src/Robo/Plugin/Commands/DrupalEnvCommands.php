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
   * Update the environment so that the scaffolding can happen, and run it.
   *
   * @command drupal-env:scaffold
   */
  public function drupalEnvScaffold(): void {
    $composer_path = 'composer';
    if (!`which $composer_path`) {
      if (!`which docker`) {
        throw new \Exception('Either composer or docker must be installed to continue');
      }
      $composer_path = 'docker run --rm -i --tty -v $PWD:/app composer:2';
    }

    $composer_json_hash_before = md5_file('composer.json');

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
      return strpos($post_drupal_scaffold_cmds[$key], 'Allowing orchestration files to be executed') !== false;
    }, ARRAY_FILTER_USE_KEY);
    $post_drupal_scaffold_cmd = "echo 'Allowing orchestration files to be executed...' & chmod +x ./orch/*.sh ./composer ./php ./robo ./drush";
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

    // Make sure that our scaffolding can run.
    $this->enableScaffolding();

    // Now that everything is ready, run the scaffolding.
    $this->_exec($composer_path . ' drupal:scaffold');

    $this->disableScaffolding();

    // If composer.json was updated, the lock file also has to be updated.
    if ($composer_json_hash_before !== md5_file('composer.json')) {
      $this->_exec($composer_path . ' update --lock');
    }

    $this->yell('The scaffolding has been enabled and run, bringing in any new scaffolded files. Afterwords, it was disabled so that scaffolding is not updated every time composer install is called.');
  }

  /**
   * Retrieve the value of composer.json.
   *
   * @return array
   */
  protected function getComposerJson(): array
  {
    return json_decode(file_get_contents('composer.json'), true);
  }

  /**
   * Save composer.json.
   *
   * @param array $composer_json
   *
   * @return void
   */
  protected function saveComposerJson(array $composer_json): void
  {
    file_put_contents('composer.json', json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
  }

  /**
   * Turn on scaffolding in composer.json for a single $project.
   *
   * @param string $project
   *   A project that has scaffolding.
   *
   * @return bool
   *   True if composer.json needed to be updated.
   */
  protected function enableScaffolding(string $project = 'mpbixal/drupal-env'): bool
  {
    $composer_json = $this->getComposerJson();
    if (!in_array($project, $composer_json['extra']['drupal-scaffold']['allowed-packages'] ?? [])) {
      $composer_json['extra']['drupal-scaffold']['allowed-packages'][] = $project;
      $this->saveComposerJson($composer_json);
      //$this->_exec($composer_path . ' config extra.drupal-scaffold.allowed-packages --json --merge \'["mpbixal/drupal-env"]\'');
      return true;
    }
    return false;
  }

  /**
   * Turn off scaffolding in composer.json for a single $project.
   *
   * @param string $project
   *   A project that has scaffolding.
   *
   * @return bool
   *   True if composer.json needed to be updated.
   *
   */
  protected function disableScaffolding(string $project = 'mpbixal/drupal-env'): bool
  {
    $composer_json = $this->getComposerJson();
    if (false !== $key = array_search($project, $composer_json['extra']['drupal-scaffold']['allowed-packages'] ?? [])) {
      unset($composer_json['extra']['drupal-scaffold']['allowed-packages'][$key]);
      $this->saveComposerJson($composer_json);
      return true;
    }
    return false;
  }

}
