<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\Csrf;

use Derafu\Form\Contract\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface as SymfonyCsrfTokenManagerInterface;

/**
 * Bridges Derafu forms to Symfony's CSRF token manager.
 */
final class SymfonyCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function __construct(
        private readonly SymfonyCsrfTokenManagerInterface $symfonyTokenManager,
    ) {
    }

    public function getToken(string $id): string
    {
        return $this->symfonyTokenManager->getToken($id)->getValue();
    }

    public function isValid(string $id, string $token): bool
    {
        return $this->symfonyTokenManager->isTokenValid(new CsrfToken($id, $token));
    }
}
