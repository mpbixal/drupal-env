#!/usr/bin/env bash

cd "$(dirname "$0")" || exit

BIN_PATH_PHP="php"

# If the .php.env file exists. use it to populate BIN_PATH_PHP.
if [ -f .php.env ]; then
  . .php.env
fi

# Allow local environments to choose their PHP version.
if [[ ! (-v DRUPAL_ENV_LOCAL || -v DRUPAL_ENV_REMOTE) && ! -f .php.env ]]; then
  echo ""
  echo "PHP must be installed locally."
  echo ""
  echo "Possible PHP paths to use:"
  whereis php
  echo ""
  echo "The following is the default version of PHP in your \$PATH. If PHP is already in your path and you want to use that version, just hit enter."
  which php
  echo ""
  read -p "Please enter the path to PHP on your local machine: " custom_php_path
  default_php_path=$(which php)
  custom_php_path=${custom_php_path:-$default_php_path}
  if ! builtin command -v $custom_php_path > /dev/null; then
    echo "The path '$custom_php_path' does not exist"
    exit 1
  fi
  echo ""
  echo "You entered: $custom_php_path. This value will be written to .php.env, you can update the value at any time or delete the file to get this prompt again."
  echo ""
  echo "Your PHP version is:"
  $custom_php_path --version
  echo ""
  echo "BIN_PATH_PHP=\"$custom_php_path\"" > .php.env
  . .php.env
fi

if ! builtin command -v $BIN_PATH_PHP > /dev/null; then
  echo "PHP could not be found at the path '$BIN_PATH_PHP'. Please enter the environment variable BIN_PATH_PHP in .php.env."
fi
set -x
${BIN_PATH_PHP} "$@"
