<?php

declare(strict_types=1);

namespace Duva\Symfony\Mailer;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/** Registers the `duva://` scheme with Symfony Mailer. Add it to config/bundles.php. */
final class DuvaMailerBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set('mailer.transport_factory.duva', DuvaTransportFactory::class)
            ->parent('mailer.transport_factory.abstract')
            ->tag('mailer.transport_factory');
    }
}
