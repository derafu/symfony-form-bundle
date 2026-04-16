<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Config tree for the `derafu_form:` section.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('derafu_form');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('twig_prefix')
                    ->info('Prefix for Twig form functions (e.g. "derafu_" → derafu_form(), derafu_form_start()). Defaults to "derafu_" to avoid collisions with symfony/form.')
                    ->defaultValue('derafu_')
                    ->cannotBeEmpty()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
