#!/usr/bin/env bash

# Don't run scaffolding when deps are installed for development.
composer install --no-scripts
