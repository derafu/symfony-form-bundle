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

use Derafu\Form\Contract\Csrf\CsrfTokenManagerInterface;

/**
 * A CSRF token manager that keeps the tokens in memory: one token for each id,
 * that is not used up when it is checked. It is what the tests need of one, and
 * what a real one does for a user with a session.
 */
final class InMemoryCsrfTokenManager implements CsrfTokenManagerInterface
{
    /**
     * @var array<string, string>
     */
    private array $tokens = [];

    public function getToken(string $id): string
    {
        return $this->tokens[$id] ??= bin2hex(random_bytes(16));
    }

    public function isValid(string $id, string $token): bool
    {
        return isset($this->tokens[$id]) && hash_equals($this->tokens[$id], $token);
    }
}
