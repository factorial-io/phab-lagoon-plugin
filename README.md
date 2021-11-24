# phab-lagoon-plugin

A phabalicious plugin to integrate with lagoon cli. The plugin provides a new command to show the states of the latest deployments in the CLI

## Prerequisites

You need to install the lagoon cli from [here](https://github.com/uselagoon/lagoon-cli)

## Needed configuration in the fabfile

You need to provide the name of the lagoon project via:

```yaml
hosts:
  config-a:
    .
    .
    .
    lagoon:
      project: the-lagoon-project-name
```

## Customize configuration

If you need to add options to the `lagoon`-command you can add the following to the root of the fabfile:

```
lagoonOptions:
  - -i
  - /home/my-user-name/.ssh/my-private-key
```
## Provided commands

### Show the list deployments

The command `list:deployments` will list the latest deployments for a specific configuration or for all lagoon-based configurations:

```shell
phab lagoon list:deployments
phab lagoon list:deployments --config my-config
```

### Trigger a new deployment

The command `deploy:latest` will trigger a new deployent for a given configuration and print out the new deployment similar to `latest:deployments`

```shell
phab -cmyconfig lagoon deploy:latest
```
