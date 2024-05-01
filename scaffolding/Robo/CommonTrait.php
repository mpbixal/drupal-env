<?php

namespace RoboEnv\Robo\Plugin\Commands;

use Robo\Robo;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides common functionality that all plugins can use.
 */
trait CommonTrait
{

    /**
     * Create a v4 UUID.
     *
     * @param string|null $data
     *   Optional 16 characters random data. Will cause non-random UUID return.
     *
     * @return string
     *   A v4 UUID.
     */
    protected function genUuidV4(?string $data = null): string
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Save the $file_contents to $file_path.
     *
     * @param string $file_path
     *   The path to the file to be saved.
     * @param array|string $file_contents
     *   A string of yaml or an array.
     *
     * @return bool
     */
    protected function saveYml(string $file_path, array|string $file_contents): bool
    {
        // Ensure a YML string is still valid.
        if (is_string($file_contents)) {
            Yaml::parse($file_contents);
        }
        return (bool) file_put_contents($file_path, Yaml::dump($file_contents, 5, 2));
    }

    /**
     * Save a key to the config.
     *
     * @param string $key
     * @param mixed $value
     * @param bool $local
     *
     * @return bool
     */
    protected function saveConfig(string $key, mixed $value, bool $local = false): bool
    {
        $config_file = $this->switchConfig($local);
        Robo::Config()->set($key, $value);
        $config = Robo::Config()->export();
        unset($config['options']);
        return $this->saveYml($config_file, $config);
    }

    /**
     * Get a config value.
     *
     * @param string $key
     * @param null $default
     * @param bool $local
     *
     * @return mixed
     */
    protected function getConfig(string $key, $default = NULL, bool $local = false): mixed
    {
        $this->switchConfig($local);
        return Robo::config()->get($key, $default);
    }

    /**
     * Switch between the active config.
     *
     * @param bool $local
     *
     * @return string
     */
    protected function switchConfig(bool $local = false): string
    {
        if ($local) {
            $config_file = 'roboConfDrupalEnv.local.yml';
            Robo::loadConfiguration([$config_file]);
        } else {
            $config_file = 'roboConfDrupalEnv.yml';
            Robo::loadConfiguration([$config_file]);
        }
        return $config_file;
    }



    /**
     * Retrieve the current default local environment.
     *
     * @return array
     */
    public function getDefaultLocalEnvironment(): array
    {
        $config = $this->getConfig('flags.common.defaultLocalEnvironment', [], true);
        if (empty($config)) {
            throw new \Exception('Cannot call this until the local environment has been initialized.');
        }
        return $config;
    }



    /**
     * Get the path to the bin on the machine.
     *
     * @param string $name
     *   The executable name.
     *
     * @return null|string
     *   Null if not found.
     */
    protected function executableFilePath(string $name): ?string
    {
        $command = sprintf('which %s', escapeshellarg($name));
        $file_path = shell_exec($command);
        if (!empty($file_path)) {
            return trim($file_path);
        }
        return NULL;
    }

    /**
     * The headers of the software requirements table.
     *
     * @return string[]
     */
    protected function softwareTableHeaders(): array
    {
        return [
            'name' => 'Name',
            'bin' => 'Bin Searched',
            'file_path' => 'Found At',
            'download' => 'Download',
            'requirements' => 'Requirements'
        ];
    }

    /**
     * Adds a new software requirement to the table.
     *
     * @param string $name
     *   The software's name.
     * @param string $bin
     *   The binary name.
     * @param string $download
     *   The path to download the software package.
     * @param string $requirements
     *   The path to see the requirements for the software package.
     * @param array $rows
     *   All current rows plus the new.
     *
     * @return bool
     *   False if the software cannot be found on the current machine.
     */
    protected function addSoftwareTableRow(string $name, string $bin, string $download, string $requirements, array &$rows): bool
    {
        $row = $this->softwareTableHeaders();
        $row['name'] = $name;
        $row['bin'] = $bin;
        $file_path = $this->executableFilePath($bin);
        $row['file_path'] = $file_path ?? '!!! Does not exist !!!';
        $row['download'] = $download;
        $row['requirements'] = $requirements;
        $rows[] = $row;
        return $file_path !== NULL;
    }

    /**
     * Print out the software requirements table.
     *
     * @param SymfonyStyle $io
     *   The style object.
     * @param array $rows
     *   All rows of the table.
     * @param bool $missing_software
     *   If any of the rows were missing on the current machine.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function printSoftWareTable(SymfonyStyle $io, array $rows, bool $missing_software): void
    {
        $io->table($this->softwareTableHeaders(), $rows);
        if ($missing_software) {
            throw new \Exception('You are missing a piece of software, please download and re-run.');
        }
        else {
            $io->success('All software found.');
        }
    }



    /**
     * Determine the path to $name.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io
     * @param $name
     *   The name of the binary.
     * @param $docker_run
     *   The fallback docker run command to use $name.
     *
     * @return string
     *   The full path to the binary.
     *
     * @throws \Exception
     */
    protected function getBinaryLocation(SymfonyStyle $io, $name, string $docker_run = '', $local_machine_allowed = true): string
    {
        // If inside the local environment, always run that local environments
        // internal command.
        if (FALSE !== getenv('DRUPAL_ENV_LOCAL')) {
            return $this->getLocalEnvCommand($name);
            // @TODO add remote command here.
            //} elseif (FALSE !== getenv('DRUPAL_ENV_REMOTE')) {
            //    return $this->getRemoteEnvCommand('composer');
            // If not inside the local env or the remote env, only allow calls to
            // to local env  if $local_machine_allowed is false. For example, from
            // local machine, you can only run drush through your local env.
        } elseif (!$local_machine_allowed) {
            return $this->getLocalEnvCommand($name, false);
        }
        // If not local or remote, then prompt the user how they want to access
        // the binary.
        return $this->askForBinaryLocation($io, $name, $docker_run);
    }

    /**
     * Ask the user how they want their local to use $name.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io
     * @param $name
     *   The name of the binary.
     * @param $docker_run
     *   The fallback docker run command to use $name.
     *
     * @return string
     *   The full path to the binary.
     *
     * @throws \Exception
     */
    protected function askForBinaryLocation(SymfonyStyle $io, $name, string $docker_run = ''): string
    {
        // This flag stores how their local should access composer.
        $flag_name = 'flags.common.paths.' . $name;
        $path_config = $this->getConfig($flag_name, [], true);
        // If false, this means to use the local and we're not inside the
        // environment right now.
        if (!empty($path_config)) {
            switch ($path_config['type']) {
                case 'local_environment':
                    return $this->getLocalEnvCommand('composer', false);

                case 'local_machine':
                    if (!empty($path_config['path']) && $this->executableFilePath($path_config['path'])) {
                        return $path_config['path'];
                    }
                    $path = $path_config['path'] ?? '<not set>';
                    $io->warning("Your path to $name ({$path}) no longer exists.");
                    break;

                case 'docker':
                    return $docker_run;

            }
        }

        $io->warning("You have not chosen where $name lives on your system yet.");

        $io->note("Running $name on your own machine is usually faster than running through docker.");
        $choice = $io->choice(
            "Would you like to run $name from your local machine, through your local environment (usually uses docker), or directly through docker?",
            ['Local Machine', 'Local Environment', 'Docker']
        );
        switch ($choice) {
            case 'Local Machine':
                $default_full_path = $this->executableFilePath($name);
                $io->note("Showing possible locations for $name");
                $this->_exec("whereis $name");
                $binary_location = $io->ask("Enter the full path to $name", $default_full_path);
                if (!strlen($name)) {
                    throw new \Exception('A path is required.');
                }
                if (!$this->executableFilePath($binary_location)) {
                    throw new \Exception("The path '$binary_location' does not exist on your machine.");
                }
                $this->saveConfig($flag_name, ['type' => 'local_machine', 'path' => $binary_location], true);
                return $binary_location;

            case 'Local Environment':
                $this->saveConfig($flag_name, ['type' => 'local_environment'], true);
                return $this->getLocalEnvCommand('composer', false);

            case 'Docker':
                if ($this->executableFilePath('docker')) {
                    $this->saveConfig($flag_name, ['type' => 'docker'], true);
                    return $docker_run;
                } else {
                    throw new \Exception('Docker could not be found on your system.');
                }

        }
        throw new \Exception("Invalid operation when choosing path to $name");
    }

    /**
     * Check if Drupal is installed.
     *
     * @return bool
     *
     * @throws \Exception
     */
    protected function isDrupalInstalled(SymfonyStyle $io, bool $return = false): bool {
        $result = $this->drush($io, ['status', '--fields=bootstrap'], ['print_output' => false]);
        $installed = $result->getMessage() === 'Drupal bootstrap : Successful';
        if ($return) {
            return $installed;
        }
        if (!$installed) {
            throw new \Exception('Drupal is not installed or the environment is not started.');
        }
        return true;
    }


    /**
     * Is the $project installed in Composer?
     *
     * @param string $project
     *
     * @return bool
     */
    protected function isDependencyInstalled(string $project): bool
    {
        return $this->_exec("./composer show $project  > /dev/null 2>&1")->wasSuccessful();
    }

    /**
     * Install one or more dependencies.
     *
     * @param SymfonyStyle $io
     * @param bool $ask_before_install
     *   If true, ask before installing.
     * @param array $projects
     *   An array with keys of project name and description values.
     * @param bool $dev_dep
     *   If true, all $projects will be installed as dev dependencies.
     * @param bool $ask_dev_dep
     *   If true, it will ask for each dep if it should be a dev dep.
     *
     * @return bool
     *   True if there was no error.
     */
    protected function installDependencies(SymfonyStyle $io, bool $ask_before_install, array $projects = [], bool $dev_dep = false, bool $ask_dev_dep = false): bool
    {
        if (!$ask_before_install && $ask_dev_dep) {
            throw new \Exception('You must ask before install if you want to ask for a dev dependency.');
        }
        $_self = $this;
        $not_installed_projects = array_filter($projects, static function (string $key) use ($projects, $_self): bool {
            return !$_self->isDependencyInstalled($key);
        }, ARRAY_FILTER_USE_KEY);
        // All installed, nothing to do.
        if (empty($not_installed_projects)) {
            return true;
        }
        if ($ask_before_install) {
            $install_projects = [];
            $install_projects_dev = [];
            foreach ($not_installed_projects as $not_installed_project => $description) {
                $dev_dep_label = '';
                if ($dev_dep && !$ask_dev_dep) {
                    $dev_dep_label = ' (Development only dependency)';
                }
                if ($io->confirm("Would you like to install $not_installed_project$dev_dep_label? $description")) {
                    if ($dev_dep_label || ($ask_dev_dep && $io->confirm('Would you like this to be a dev only dependency?', $dev_dep))) {
                        $install_projects_dev[] = $not_installed_project;
                    }
                    else {
                        $install_projects[] = $not_installed_project;
                    }
                }
            }
        } else {
            if ($dev_dep) {
                $install_projects_dev = array_keys($not_installed_projects);
            } else {
                $install_projects = array_keys($not_installed_projects);
            }
        }
        $success = [];
        if (!empty($install_projects)) {
            $command = $this->taskComposerRequire('./composer');
            foreach ($install_projects as $install_project) {
                $this->yell("Installing $install_project");
                $command->dependency($install_project);
            }
            $success[] = $command->run()->wasSuccessful();
            try {
                $this->commonGetDrushPath($io);
                $success[] = $this->drush($io, ['en',  '-y' , implode(', ', $install_projects)])->wasSuccessful();
            } catch (\Exception $exception) {
                // Do nothing.
            }
        }
        if (!empty($install_projects_dev)) {
            $command = $this->taskComposerRequire('./composer');
            foreach ($install_projects_dev as $install_project_dev) {
                $this->yell("Installing $install_project_dev as a development only dependency");
                $command->dependency($install_project_dev);
            }
            $success[] = $command->dev()->run()->wasSuccessful();
            try {
                $this->commonGetDrushPath($io);
                $success[] = $this->drush($io, ['en', '-y', implode(', ', $install_projects_dev)])->wasSuccessful();
            } catch (\Exception $exception) {
                // Do nothing.
            }
        }
        return !in_array(false, $success);
    }

}
