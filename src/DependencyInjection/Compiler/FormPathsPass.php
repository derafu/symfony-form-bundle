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

use Derafu\Form\Loader\PhpFormLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Discovers form directories in registered bundles and wires them into
 * the {@see PhpFormLoader}.
 *
 * Convention: any bundle that has a `resources/forms/` directory gets
 * its forms auto-registered — zero configuration required.
 *
 * Priority (last `addPath()` wins):
 *   1. Bundle paths (in bundle registration order)
 *   2. App path (`derafu_form.forms_path`) — always highest priority
 *
 * This means the app can override any bundle-provided form by placing
 * a file with the same name in its own `resources/forms/` directory.
 */
final class FormPathsPass implements CompilerPassInterface
{
    private const string FORMS_DIR = 'resources/forms';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PhpFormLoader::class)) {
            return;
        }

        $loaderDefinition = $container->getDefinition(PhpFormLoader::class);

        // 1. Add bundle paths (in registration order → lower priority).
        //    kernel.bundles_metadata 'path' points to the namespace root
        //    (e.g. src/), but resources/ is typically one level up at the
        //    package root. Check both locations.
        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        foreach ($bundlesMetadata as $bundleMeta) {
            $basePath = $bundleMeta['path'];
            foreach ([$basePath, dirname($basePath)] as $candidate) {
                $formsDir = $candidate . '/' . self::FORMS_DIR;
                if (is_dir($formsDir)) {
                    $loaderDefinition->addMethodCall('addPath', [$formsDir]);
                    break;
                }
            }
        }

        // 2. Add app path last (highest priority → overrides bundles).
        $appFormsPath = $container->getParameter('derafu_form.forms_path');
        $loaderDefinition->addMethodCall('addPath', [$appFormsPath]);
    }
}
