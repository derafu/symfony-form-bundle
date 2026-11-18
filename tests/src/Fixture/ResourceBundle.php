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

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * A bundle with real on-disk forms, including the legacy src-root layout.
 */
abstract class ResourceBundle extends Bundle
{
    public function __construct(private readonly string $directory)
    {
    }

    public function getPath(): string
    {
        return $this->directory;
    }
}
