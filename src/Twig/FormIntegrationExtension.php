<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\Twig;

use Derafu\FormBundle\Twig\NodeVisitor\FormFunctionNodeVisitor;
use Twig\Extension\AbstractExtension;

/**
 * Adds form dispatching to the native Twig environment without replacing either
 * form extension or the functions, filters and token parsers it provides.
 */
final class FormIntegrationExtension extends AbstractExtension
{
    /**
     * {@inheritDoc}
     *
     * @return list<FormFunctionNodeVisitor>
     */
    public function getNodeVisitors(): array
    {
        return [new FormFunctionNodeVisitor()];
    }
}
