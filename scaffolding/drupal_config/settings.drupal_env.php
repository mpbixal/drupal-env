<?php

// phpcs:ignoreFile

/**
 * Load configuration for the environment.
 */
if (getenv('LANDO_INFO') !== FALSE) {
  include $app_root . '/' . $site_path . '/settings.lando.php';
}
elseif (getenv('PLATFORM_ENVIRONMENT_TYPE') !== FALSE) {
  include $app_root . '/' . $site_path . '/settings.platformsh.php';
}

/**
 * Load local development override configuration, if available.
 *
 * Create a settings.local.php file to override variables on secondary (staging,
 * development, etc.) installations of this site.
 *
 * Typical uses of settings.local.php include:
 * - Disabling caching.
 * - Disabling JavaScript/CSS compression.
 * - Rerouting outgoing emails.
 *
 * Keep this code block at the end of this file to take full effect.
 */
// Create the settings.local.php from updated.settings.local.php if
// settings.local.php does not exist yet and this is a local environment.
if (getenv('DRUPAL_ENV_LOCAL') !== FALSE) {
  $settings_local_php = $app_root . '/' . $site_path . '/settings.local.php';
  if (!file_exists($settings_local_php)) {
    copy(DRUPAL_ROOT . '/sites/updated.settings.local.php', $settings_local_php);
  }
  include $settings_local_php;
}
