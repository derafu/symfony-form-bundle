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

use Derafu\FormBundle\Csrf\SymfonyCsrfTokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface as SymfonyCsrfTokenManagerInterface;

#[CoversClass(SymfonyCsrfTokenManager::class)]
final class SymfonyCsrfTokenManagerTest extends TestCase
{
    public function testItDelegatesToSymfonyManager(): void
    {
        $symfony = $this->createMock(SymfonyCsrfTokenManagerInterface::class);
        $symfony->expects($this->once())->method('getToken')->with('authenticate')->willReturn(new CsrfToken('authenticate', 'abc'));
        $symfony->expects($this->once())->method('isTokenValid')->with(new CsrfToken('authenticate', 'abc'))->willReturn(true);

        $manager = new SymfonyCsrfTokenManager($symfony);

        $this->assertSame('abc', $manager->getToken('authenticate'));
        $this->assertTrue($manager->isValid('authenticate', 'abc'));
    }
}
