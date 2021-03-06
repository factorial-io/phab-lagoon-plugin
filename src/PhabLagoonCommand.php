<?php

namespace Phabalicious\CustomPlugin;

use Phabalicious\Command\BaseOptionsCommand;
use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Method\TaskContextInterface;
use Phabalicious\ShellProvider\LocalShellProvider;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PhabLagoonCommand extends BaseOptionsCommand
{
    public function configure()
    {
        parent::configure();
        $this->setName('lagoon');
        $this->addArgument(
          'subcommands',
          InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
          'The subcommand to execute',
          ['latest:deployments']
        );

      $this->addOption(
        'config',
        'c',
        InputOption::VALUE_OPTIONAL,
        'Which host-config should be worked on',
        false
      );
      $this->setHelp("
Provides integration with the lagoon cli command. You might need to login 
into the lagoon cluster via `lagoon login` before running any of these commands. 

<options=bold>Configuration:</>

Please add the name of the lagoon project to the host-configuration, so that 
the lagoon command can pick it up:

    hosts:
      example:
        lagoon:
          project: my-lagoon-project
        ...
    
If you need to pass additional arguments to the lagoon cli command you can add them
via the global `lagoonOptions` in your fabfile. 

    lagoonOptions:
      - -i
      - /path/to/my/private/key
  
<options=bold>Available subcommands:</>

* <options=underscore>list:deployments</>
  Get a list of the latest deployments for a given configuration and show 
  them in a table with their respective status. If your terminal supports
  urls the configuration name is also a link to the laggon ui page for
  that deployment. If the `--config` argument is omitted then all lagoon-
  instances are reported.
  
  Examples:
  <info>phab lagoon list:deployments</info>
  <info>phab lagoon list:deployments --config my-lagoon-config</info>

* <options=underscore>deploy:latest</>
  Trigger a new deployment for a given configuration. Shows the newly created
  deployment similat to `list:deployments`
  
  Example:
  <info>phab lagoon deploy:latest --config <my-lagoon-config></>
  

      ");
    }

  protected function getLagoonCmd(TaskContextInterface $context, $arguments)
    {
      return array_merge(
        ['lagoon'],
        $context->getConfigurationService()->getSetting('lagoonOptions', []),
        $arguments
      );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
      $this->readConfiguration($input);
      $context = $this->createContext($input, $output);

      if ($config_name = $input->getOption('config')) {
        $hosts = [ $context->getConfigurationService()->getHostConfig($config_name) ];
      }
      else {
        $hosts = $this->getAllLagoonHosts($context);
      }

      foreach ($input->getArgument('subcommands') as $subcommand) {
        switch (strtolower($subcommand)) {
          case 'latest:deployment':
          case 'list:deployments':
          case 'latest:deployments':
            $this->printLatestDeployments($context, $hosts, count($hosts) == 1 ? 10 : 3);
            break;

          case 'deploy':
          case 'deploy:latest':
            if (empty($config_name)) {
              throw new \RuntimeException('Please privide a configuration you want to deploy');
            }
            $host_config =$context->getConfigurationService()->getHostConfig($config_name);
            $this->deployLatest($host_config, $context);
            break;

          default:
            $context->io()
              ->error(sprintf('Unknown subcommand `%s`', $subcommand));
            return 1;
        }
      }
      return 0;
    }

    private function formatRow($row, $status) {
      $colors = [
        'complete' => 'green',
        'failed' => 'red'
      ];

      foreach ($row as $ndx => $str) {
         $row[$ndx] =isset($colors[$status]) ? sprintf('<fg=%s>%s</>', $colors[$status], $str) : $str;
      }

      return $row;
    }

    protected function printLatestDeployments(TaskContextInterface $context, array $hosts, $num_rows) {

      $data = [];
      $configuration_service = $context->getConfigurationService();
      $shell = $this->createLocalShell($configuration_service);

      $context->io()->progressStart(count($hosts));
      foreach ($hosts as $host) {
        $context->io()->progressAdvance();
        $context->io()->write(sprintf('  Getting info for %s ...', $host->getConfigName()));
        $lagoon_config = $host['lagoon'];
        $cmd = $this->getLagoonCmd($context, [
          'list',
          'deployments',
          '-p',
          $lagoon_config['project'],
          '-e',
          $host['branch'],
          '--output-json'
          ]);

        $result = $shell->run(implode(' ', $cmd), true, true);
        $output = implode("\n", $result->getOutput());
        $json = json_decode($output);
        $data[$host->getConfigName()] = $json;
      }
      $context->io()->progressFinish();
      $rows = [];
      foreach ($data as $host_config => $items) {
        $host = $configuration_service->getHostConfig($host_config);

        if (isset($items->error)) {
          $rows[] = [
            $host->getConfigName(),
            $host['branch'],
            'Error',
            '',
            ''
          ];
        } else {
          for ($k = 0; $k < min(count($items->data) - 1, $num_rows); $k++) {
            $item = $items->data[$k];
            $rows[] = $this->formatRow([
              sprintf(
                '<href=%s>%s (%s)</>',
                sprintf(
                  'https://dashboard.amazeeio.cloud/projects/%s/%s-%s/deployments/%s',
                  $lagoon_config['project'],
                  $lagoon_config['project'],
                  $host['branch'],
                  $item->name
                ),
                $host->getConfigName(),
                $item->name
              ),
              $host['branch'],
              $item->created,
              $item->completed,
              $item->status
            ],
            $item->status);
          }
        }
        $rows[] = new TableSeparator();
      }
      $context->io()->title(sprintf("latest deployments for %s", $configuration_service->getSetting('name')));
      $context->io()->table(['Config', 'Branch', 'Created', 'Completed', 'Status'], $rows);
    }

  private function getAllLagoonHosts(TaskContextInterface $context) {
    $configuration_service = $context->getConfigurationService();
    $hosts = [];

    foreach ($configuration_service->getAllHostConfigs() as $config_name => $host) {
      try {
        $host = $configuration_service
          ->getHostConfig($config_name);
        if (!isset($host['lagoon'])) {
          continue;
        }
        $hosts[] = $host;
      }
      catch (\Exception $e) {
        // ignore for now.
      }
    }
    return $hosts;
  }

  public function deployLatest(HostConfig $host_config, TaskContextInterface $context)
  {
    $configuration_service = $context->getConfigurationService();
    $shell = $this->createLocalShell($configuration_service);
    $lagoon_config = $host_config['lagoon'];
    if (empty($lagoon_config)) {
      throw new \RuntimeException('Missing lagoon configuration');
    }
    $cmd = $this->getLagoonCmd($context, [
      'deploy',
      'latest',
      '-p',
      $lagoon_config['project'],
      '-e',
      $host_config['branch'],
      '--force',
      '--output-json'
      ]);

    $result = $shell->run(implode(' ', $cmd), true, true);
    if ($result->succeeded()) {
      $this->printLatestDeployments($context, [ $host_config ], 1);
      $context->io()->success(sprintf('New deployment triggered for `%s`...', $host_config->getConfigName()));
    }
    else {
      $result->throwException("Could not trigger a new deployment via lagoon");
    }
  }

  /**
   * @param \Phabalicious\Configuration\ConfigurationService $configuration_service
   *
   * @return \Phabalicious\ShellProvider\LocalShellProvider
   */
  protected function createLocalShell(ConfigurationService $configuration_service): LocalShellProvider
  {
    $shell = new LocalShellProvider($configuration_service->getLogger());
    $shell_host_config = new HostConfig([
      'shellExecutable' => '/bin/sh',
      'rootFolder' => $configuration_service->getFabfilePath(),
    ], $shell, $configuration_service);
    return $shell;
}
}

