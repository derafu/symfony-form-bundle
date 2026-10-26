<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\DependencyInjection\Compiler;

use Derafu\Form\Contract\Csrf\CsrfTokenManagerInterface;
use Derafu\FormBundle\Csrf\SymfonyCsrfTokenManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the Symfony CSRF adapter when the application exposes Symfony's manager.
 */
final class RegisterCsrfTokenManagerPass implements CompilerPassInterface
{
    private const SYMFONY_CSRF_MANAGER = 'security.csrf.token_manager';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(self::SYMFONY_CSRF_MANAGER)) {
            return;
        }

        if ($container->has(CsrfTokenManagerInterface::class)) {
            return;
        }

        $container
            ->register(SymfonyCsrfTokenManager::class, SymfonyCsrfTokenManager::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArgument('$symfonyTokenManager', new Reference(self::SYMFONY_CSRF_MANAGER));

        $container->setAlias(CsrfTokenManagerInterface::class, SymfonyCsrfTokenManager::class);
    }
}
