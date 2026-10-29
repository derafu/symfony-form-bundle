<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsFormBundle\Fixture;

use Composer\InstalledVersions;
use Derafu\FormBundle\FormBundle;
use Derafu\TranslationBundle\TranslationBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Real consuming application with native Twig and translation integration.
 */
final class Kernel extends BaseKernel
{
    /**
     * @param array<string, mixed> $config Form bundle configuration.
     * @param list<BundleInterface> $resourceBundles Bundles containing form definitions.
     */
    public function __construct(
        private readonly string $project,
        private readonly array $config = [],
        private readonly array $resourceBundles = [],
        private readonly bool $applicationServices = false,
        private readonly bool $nativeForms = false,
    ) {
        parent::__construct('test', true);
    }

    /** @return iterable<BundleInterface> */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TranslationBundle();
        yield new FormBundle();
        yield from $this->resourceBundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'form-bundle-test-secret',
                'default_locale' => 'es',
                'translator' => ['fallbacks' => ['en']],
                'form' => ['enabled' => $this->nativeForms],
            ]);
            $container->loadFromExtension('derafu_form', $this->config);
            $container->loadFromExtension('twig', []);
            $services = new YamlFileLoader($container, new FileLocator(__DIR__ . '/config'));
            $services->load('test-services.yaml');
            if ($this->applicationServices) {
                $services->load('application-services.yaml');
            }
            if ($this->nativeForms) {
                // Symfony omits this configuration when Form is require-dev
                // but TwigBundle is a production dependency of the root package.
                // Load Symfony's real configuration for the consuming-app case.
                (new PhpFileLoader($container, new FileLocator()))->load(
                    InstalledVersions::getInstallPath('symfony/twig-bundle') . '/Resources/config/form.php',
                );
                $services->load('native-form-services.yaml');
            }
            // Keep every package definition reachable, so removal of unused
            // private services cannot conceal an invalid constructor or alias.
            $container->addCompilerPass(new class () implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if (str_starts_with($id, 'Derafu\\')) {
                            $definition->setPublic(true);
                        }
                    }
                    foreach ($container->getAliases() as $id => $alias) {
                        if (str_starts_with($id, 'Derafu\\')) {
                            $alias->setPublic(true);
                        }
                    }
                }
            });
        });
    }

    public function getProjectDir(): string
    {
        return $this->project;
    }

    public function getCacheDir(): string
    {
        return $this->project . '/var/cache';
    }

    public function getLogDir(): string
    {
        return $this->project . '/var/log';
    }
}
