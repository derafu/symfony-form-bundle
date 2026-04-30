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

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ensures that derafu/form JS assets are registered in the project's
 * importmap.php so bare module specifiers resolve in the browser.
 *
 * Runs at container compilation time (cache:clear / first boot).
 * Idempotent: only adds entries that are not already present.
 */
final class ImportMapEntriesPass implements CompilerPassInterface
{
    /** Namespace prefix registered by FormExtension::prepend(). */
    private const string NAMESPACE = 'derafu-form';

    /** Path relative to kernel.project_dir where the JS files live. */
    private const string JS_DIR = 'vendor/derafu/form/resources/js';

    public function process(ContainerBuilder $container): void
    {
        $projectDir    = $container->getParameter('kernel.project_dir');
        $importMapPath = $projectDir . '/importmap.php';

        if (!is_file($importMapPath)) {
            return;
        }

        $entries = $this->discoverEntries($projectDir);
        $current = require $importMapPath;
        $missing = array_diff_key($entries, $current);

        if (empty($missing)) {
            return;
        }

        file_put_contents(
            $importMapPath,
            $this->render($importMapPath, array_merge($current, $missing)),
        );
    }

    /** @return array<string, array<string, string>> */
    private function discoverEntries(string $projectDir): array
    {
        $jsDir   = $projectDir . '/' . self::JS_DIR;
        $entries = [];

        if (!is_dir($jsDir)) {
            return $entries;
        }

        foreach (glob($jsDir . '/*.js') ?: [] as $file) {
            $logicalPath           = self::NAMESPACE . '/' . basename($file);
            $entries[$logicalPath] = ['path' => $logicalPath];
        }

        return $entries;
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function render(string $path, array $entries): string
    {
        $original  = file_get_contents($path);
        $returnPos = strrpos($original, 'return [');
        $header    = $returnPos !== false
            ? substr($original, 0, $returnPos)
            : "<?php\n\ndeclare(strict_types=1);\n\n";

        $lines = [$header . "return [\n"];

        foreach ($entries as $specifier => $config) {
            $lines[] = '    ' . var_export($specifier, true) . " => [\n";
            foreach ($config as $key => $value) {
                $lines[] = '        ' . var_export($key, true) . ' => ' . var_export($value, true) . ",\n";
            }
            $lines[] = "    ],\n";
        }

        $lines[] = "];\n";

        return implode('', $lines);
    }
}
