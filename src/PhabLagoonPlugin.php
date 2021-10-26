<?php

namespace Phabalicious\CustomPlugin;

use Phabalicious\Utilities\AvailableMethodsAndCommandsPluginInterface;
use Phabalicious\Utilities\PluginInterface;

class PhabLagoonPlugin implements PluginInterface, AvailableMethodsAndCommandsPluginInterface
{

    public static function getName(): string
    {
        return "PhabLagoonPlugin";
    }

    public static function requires(): string
    {
        return "3.7.0";
    }

    public static function getMethods(): array
    {
        return [
        ];
    }

    public static function getCommands(): array
    {
        return [
            PhabLagoonCommand::class
        ];
    }

}
