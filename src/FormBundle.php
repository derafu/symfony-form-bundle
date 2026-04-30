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

use Derafu\FormBundle\DependencyInjection\Compiler\FormPathsPass;
use Derafu\FormBundle\DependencyInjection\Compiler\ImportMapEntriesPass;
use Derafu\FormBundle\DependencyInjection\FormExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Derafu Form Bundle — Symfony integration for derafu/form.
 *
 * Registers the derafu/form declarative form system as Symfony services:
 * form renderer, type registry, form factory, and a Twig extension with
 * configurable function prefix to avoid collisions with `symfony/form`.
 *
 * The form rendering uses its own internal Twig environment (via
 * derafu/renderer) for form templates. A separate `FormTwigExtension`
 * is registered in Symfony's Twig with a prefix so page templates can
 * call `{{ derafu_form(form) }}` etc.
 *
 * A compiler pass auto-discovers `resources/forms/` directories in all
 * registered bundles and wires them into the PhpFormLoader. The app's
 * own forms directory has the highest priority (override pattern).
 */
final class FormBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new FormPathsPass());
        $container->addCompilerPass(new ImportMapEntriesPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new FormExtension();
        }

        return $this->extension ?: null;
    }
}
