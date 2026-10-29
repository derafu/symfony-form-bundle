<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle;

use Composer\InstalledVersions;
use Derafu\Array\ArrayReader;
use Derafu\Form\Loader\PhpFormLoader;
use Derafu\FormBundle\DependencyInjection\Compiler\FormPathsPass;
use Derafu\FormBundle\DependencyInjection\Compiler\FormTemplatesPass;
use Derafu\FormBundle\DependencyInjection\Compiler\ImportMapEntriesPass;
use Derafu\FormBundle\DependencyInjection\Compiler\RegisterCsrfTokenManagerPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integrates package services with Symfony's Twig, translator and form paths.
 */
final class FormBundle extends AbstractBundle
{
    protected string $extensionAlias = 'derafu_form';

    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new FormPathsPass());
        $container->addCompilerPass(new FormTemplatesPass());
        $container->addCompilerPass(new ImportMapEntriesPass());
        $container->addCompilerPass(new RegisterCsrfTokenManagerPass());
    }

    /**
     * {@inheritDoc}
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('forms_path')
                    ->info('Directory for application form definitions, with the highest priority.')
                    ->defaultValue('%kernel.project_dir%/resources/forms')
                ->end()
            ->end()
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $projectDir = ArrayReader::fromArray($container->getParameterBag()->all())
            ->requireString('kernel.project_dir');

        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    $projectDir . '/vendor/derafu/form/resources/js' => 'derafu-form',
                ],
            ],
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $reader = ArrayReader::fromArray($config);
        $container->setParameter('derafu.form_bundle.forms_path', $reader->requireString('forms_path'));

        // Resolve actual installation paths, including custom vendor directories.
        foreach ([
            'derafu/data-processor' => 'data-processor-services.yaml',
            'derafu/form' => 'form-services.yaml',
        ] as $package => $configurationFile) {
            $configurator->import(InstalledVersions::getInstallPath($package) . '/resources/config/' . $configurationFile);
        }
        $configurator->import('../resources/config/symfony-form-bundle-services.yaml');
        $configurator->services()->remove(PhpFormLoader::class);
    }
}
