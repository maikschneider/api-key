<?php

declare(strict_types=1);

use MaikSchneider\ApiKey\Controller\ApiKeyModuleController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;

return static function (ContainerConfigurator $containerConfigurator): void {
    // The backend module depends on the v14-only ComponentFactory, see Configuration/Backend/Modules.php.
    if (class_exists(ComponentFactory::class)) {
        $containerConfigurator->services()
            ->set(ApiKeyModuleController::class)
            ->autowire()
            ->autoconfigure();
    }
};
