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

use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Contract\Csrf\CsrfTokenManagerInterface;
use Derafu\FormBundle\FormBundle;
use Derafu\TranslationBundle\TranslationBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
            ]);
            $container->loadFromExtension('derafu_form', $this->config);
            $container->loadFromExtension('twig', []);
            if ($this->applicationServices) {
                $container->register(InMemoryCsrfTokenManager::class, InMemoryCsrfTokenManager::class)->setPublic(true);
                $container->setAlias(CsrfTokenManagerInterface::class, InMemoryCsrfTokenManager::class);
                $container->register(InMemoryCaptchaProvider::class, InMemoryCaptchaProvider::class)->setPublic(true);
                $container->setAlias(CaptchaProviderInterface::class, InMemoryCaptchaProvider::class);
                $container->register(ApplicationRuleRegistrar::class, ApplicationRuleRegistrar::class)
                    ->addTag('derafu_data_processor.rule_registrar');
            }
            $container->setAlias('test.twig', 'twig')->setPublic(true);
            $container->setAlias('test.translator', 'translator')->setPublic(true);
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
