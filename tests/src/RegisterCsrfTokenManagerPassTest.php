<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsFormBundle;

use Derafu\Form\Contract\Csrf\CsrfTokenManagerInterface;
use Derafu\FormBundle\Csrf\SymfonyCsrfTokenManager;
use Derafu\FormBundle\DependencyInjection\Compiler\RegisterCsrfTokenManagerPass;
use Derafu\TestsFormBundle\Fixture\InMemoryCsrfTokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RegisterCsrfTokenManagerPass::class)]
final class RegisterCsrfTokenManagerPassTest extends TestCase
{
    public function testItRegistersTheAdapterWhenSymfonyCsrfManagerExists(): void
    {
        $container = new ContainerBuilder();
        $container->register('security.csrf.token_manager', Definition::class);

        (new RegisterCsrfTokenManagerPass())->process($container);

        $this->assertTrue($container->has(CsrfTokenManagerInterface::class));
        $definition = $container->getDefinition(CsrfTokenManagerInterface::class);
        $this->assertSame(SymfonyCsrfTokenManager::class, $definition->getClass());
        $reference = $definition->getArgument('$symfonyTokenManager');
        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame('security.csrf.token_manager', $reference->__toString());
    }

    public function testItDoesNothingWhenSymfonyCsrfManagerIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new RegisterCsrfTokenManagerPass())->process($container);

        $this->assertFalse($container->has(CsrfTokenManagerInterface::class));
    }

    public function testItDoesNotReplaceAnApplicationManager(): void
    {
        $container = new ContainerBuilder();
        $container->register('security.csrf.token_manager', Definition::class);
        $container->register(InMemoryCsrfTokenManager::class, InMemoryCsrfTokenManager::class);
        $container->setAlias(CsrfTokenManagerInterface::class, InMemoryCsrfTokenManager::class);

        (new RegisterCsrfTokenManagerPass())->process($container);

        $this->assertSame(InMemoryCsrfTokenManager::class, (string) $container->getAlias(CsrfTokenManagerInterface::class));
        $this->assertFalse($container->hasDefinition(SymfonyCsrfTokenManager::class));
    }
}
