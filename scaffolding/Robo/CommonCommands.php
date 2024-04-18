<?php

namespace RoboEnv\Robo\Plugin\Commands;

use Robo\Result;
use Robo\Robo;
use Robo\Tasks;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Run common orchestration tasks and provides shared helper methods.
 *
 * @class RoboFile
 */
class CommonCommands extends Tasks
{

    /**
     * The path to Drush.
     *
     * Should be set by the commands that extend this task.
     *
     * @var string
     */
    protected string $path_to_drush;

    /**
     * Constructor.
     */
    public function __construct()
    {
        Robo::loadConfiguration(['roboConfDrupalEnv.yml']);
    }

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
     * Save the robo config.
     *
     * @return bool
     */
    protected function saveConfig(): bool
    {
        $config = Robo::Config()->export();
        unset($config['options']);
        return $this->saveYml('roboConfDrupalEnv.yml', $config);
    }

    /**
     * Add the server required to make Xdebug work in PhpStorm.
     *
     * This works with /.run/appserver.run.xml to allow Xdebug to work.
     *
     * @command xdebug:phpstorm-debug-config
     */
    public function xdebugPhpstormDebugConfig(): void
    {
        if (!class_exists('DOMDocument')) {
            throw new \Exception('Your local PHP must have the "dom" extension installed.');
        }
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->preserveWhiteSpace = TRUE;

        if (!@$xml->load(".idea/php.xml")) {
            throw new \Exception('Are you sure your using PhpStorm? There is no /.idea/php.xml file.');
        }
        $components = $xml->getElementsByTagName("component");
        /** @var \DOMElement $row */
        foreach ($components as $row) {
            if ($row->getAttribute('name') === 'PhpProjectServersManager') {
                throw new \Exception('Xdebug is already configured');
            }
        }
        /* Append a component that looks something like:
        <component name="PhpProjectServersManager">
          <servers>
            <server host="doesnotmatter.com" id="fdf5bc85-858f-4732-ba1d-29be7676b0a3" name="appserver" use_path_mappings="true">
              <path_mappings>
                <mapping local-root="$PROJECT_DIR$" remote-root="/app" />
              </path_mappings>
            </server>
          </servers>
        </component>
        */

        $project = $xml->getElementsByTagName('project');

        $mapping = $xml->createElement('mapping', '');
        $mapping->setAttribute('local-root', '$PROJECT_DIR$');
        $mapping->setAttribute('remote-root', '/app');

        $path_mappings = $xml->createElement('path_mappings', '');

        $path_mappings->appendChild($mapping);

        $server = $xml->createElement('server', '');
        $server->setAttribute('host', 'doesnotmatter.com');
        $server->setAttribute('id', $this->genUuidV4());
        $server->setAttribute('name', 'appserver');
        $server->setAttribute('use_path_mappings', 'true');

        $server->appendChild($path_mappings);

        $servers = $xml->createElement('servers', '');

        $servers->appendChild($server);

        $component = $xml->createElement('component');
        $component->setAttribute('name', 'PhpProjectServersManager');

        $component->appendChild($servers);

        $project->item(0)->appendChild($component);
        $xml->save('.idea/php.xml');

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
     * Call drush for your platform.
     *
     * @param string|array $args
     *   All arguments and options passed to drush.
     * @param array $exec_options
     *   Additional options passed to the robo taskExec().
     *
     * @return \Robo\Result
     *
     * @throws \Exception
     */
    protected function drush(string|array $args = '', array $exec_options = ['print_output' => true]): Result
    {
        if (empty($this->path_to_drush)) {
            throw new \Exception(get_called_class() . ' must implement set the property path_to_drush');
        }
        $task = $this->taskExec($this->path_to_drush);
        if (is_array($args)) {
            $task->args($args);
        } else {
            $task->arg($args);
        }
        return $task
            ->printOutput($exec_options['print_output'])
            ->run()
            ->stopOnFail();
    }

    /**
     * Check if Drupal is installed.
     *
     * @return bool
     *
     * @throws \Exception
     */
    protected function isDrupalInstalled(bool $return = false): bool {
        $result = $this->drush(['status', '--fields=bootstrap'], ['print_output' => false]);
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
            if (!empty($this->path_to_drush)) {
                $success[] = $this->drush('en -y ' . implode(', ', $install_projects))->wasSuccessful();
            }
        }
        if (!empty($install_projects_dev)) {
            $command = $this->taskComposerRequire('./composer');
            foreach ($install_projects_dev as $install_project_dev) {
                $this->yell("Installing $install_project_dev as a development only dependency");
                $command->dependency($install_project_dev);
            }
            $success[] = $command->dev()->run()->wasSuccessful();
            if (!empty($this->path_to_drush)) {
                $success[] = $this->drush('en -y ' . implode(', ', $install_projects_dev))->wasSuccessful();
            }
        }
        return !in_array(false, $success);
    }

    /**
     * Initialize the Drupal Environment.
     *
     * @command drupal-env-admin:init
     *
     * @return void
     */
    public function drupalEnvAdminInit(SymfonyStyle $io): void
    {
        // Create the config sync directory if it does not exist.
        if (!is_dir('config/sync')) {
            $io->note('Creating the config sync directory...');
            $this->taskFilesystemStack()->mkdir(['config/sync'], 0755)->run();
        }

        // Add required composer requirements.
        $io->note('Installing required dependencies...');
        $this->installDependencies($io, false, ['drupal/core-dev' => 'Provides PHP CS'], true);
        $this->installDependencies($io, false, ['drush/drush' => 'Required for CLI access to Drupal']);

        $io->success('Your project is now ready to install remote (none yet) and local environments');

        $io->success('Configure one or more local environments: ./robo drupal-env-admin:local');

        //$io->success('Configure a remote environment: ./robo drupal-env-admin:remote');


    }

    /**
     * Allows one to install one local environment at a time.
     *
     * @command drupal-env-admin:local
     *
     * @return void
     */
    public function drupalEnvAdminLocal(SymfonyStyle $io): void
    {
        $locals = [
            'lando' => [
                'name' => 'Lando',
                'installed' => $this->isDependencyInstalled('mpbixal/drupal-env-lando') ? 'Yes, installed' : 'Not installed',
                'description' => 'https://lando.dev/ Push-button development environments hosted on your computer or in the cloud. Automate your developer workflow and share it with your team.',
                'package' => 'mpbixal/drupal-env-lando',
                'post_install_command' => './robo drupal-env-lando:scaffold',
            ],
        ];
        $rows = [];
        foreach ($locals as $key => $options) {
            $rows[$key] = [
                $options['name'],
                $options['installed'],
                $options['package'],
                $options['post_install_command'],
                $options['description']
            ];
        }
        $io->table(['Name', 'Installed', 'Package', 'Post Install Command', 'Description'], $rows);
        $not_installed = array_filter($locals, static function (string $key) use ($locals) {
            return $locals[$key]['installed'] === 'Not installed';
        }, ARRAY_FILTER_USE_KEY);
        if (empty($not_installed)) {
            $io->warning('You have installed all local environments.');
            return;
        }
        $options = array_combine(array_keys($not_installed), array_column($not_installed, 'name'));
        $options['cancel'] = 'Cancel';
        $choice = $io->choice('Which environment do you want to install?',  $options, 'cancel');
        if ($choice === 'cancel') {
            $io->caution('Cancelled adding a new local environment.');
            return;
        }
        // Install the Drupal Env Local package.
        if ($this->installDependencies($io, false, [$locals[$choice]['package'] => $locals[$choice]['description']])) {
            if ($io->confirm('Success! Would you like to continue the installation and configuration of the new local environment')) {
                $this->_exec($locals[$choice]['post_install_command']);
            }
        } else {
            $io->warning("There was an issue installing {$locals[$choice]['package']}.");
        }
        // @TODO add confirm to scaffold for lando-admin:init.

    }

    /**
     * Allows one to install a remote environment.
     *
     * @command drupal-env-admin:remote
     *
     * @return void
     */
    public function drupalEnvAdminRemote(SymfonyStyle $io): void
    {
        $io->caution('There are no remotes able to be configured at this time, Platform.sh is coming soon.');
    }

    /**
     * Called from each local & remote install.
     *
     * @return void
     */
    protected function installOptionalDependencies(SymfonyStyle $io): void
    {
        $flag_name = 'flags.installedOptionalDependencies';

        $already_run_label = '';
        if ($already_run = Robo::Config()->get($flag_name, 0)) {
            $already_run_label = " You've already run this before.";
        }
        if ($io->confirm("Would you like to install some optional but helpful dependencies?$already_run_label", !$already_run)) {
            $optional_deps = [
                'drupal/admin_toolbar' => 'Easy access at the top of the page to admin only links.',
                'drupal/paragraphs' => 'Allows site builders to create dynamic content for every entity.',
                'drupal/disable_user_1_edit' => 'Don\'t let anyone but user 1 edit the super user.',
                'drupal/menu_admin_per_menu' => 'Allows granular per-menu access.',
                'drupal/role_delegation' => 'Allow a role to give only certain roles (don\'t let them make admins)',
                'drupal/twig_tweak' => 'Handy shortcuts and helpers when working in Twig',
                'drupal/twig_field_value' => 'Easily get field values and labels separately in Twig.',
            ];
            $this->installDependencies($io, true, $optional_deps, false);
            $this->installDependencies($io, true, ['drupal/devel' => 'This has many great debugging tools.'], true, true);
        }

        if (!$already_run) {
            Robo::Config()->set($flag_name, 1);
            $this->saveConfig();
        }
    }

}
