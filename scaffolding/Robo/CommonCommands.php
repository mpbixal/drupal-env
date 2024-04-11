<?php

namespace RoboEnv\Robo\Plugin\Commands;

use Robo\Result;
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

}
