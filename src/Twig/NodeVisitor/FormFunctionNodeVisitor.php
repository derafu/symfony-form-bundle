<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\Twig\NodeVisitor;

use Derafu\Form\Renderer\FormTwigExtension;
use Derafu\FormBundle\Twig\Node\FormFunctionExpression;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Twig\Environment;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Adapts shared form functions only when both form extensions are installed.
 *
 * Functions exclusive to either implementation retain their original nodes.
 */
final class FormFunctionNodeVisitor implements NodeVisitorInterface
{
    /**
     * {@inheritDoc}
     */
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    /**
     * {@inheritDoc}
     */
    public function leaveNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof FunctionExpression
            || !$env->hasExtension(FormTwigExtension::class)
            || !$env->hasExtension(FormExtension::class)
        ) {
            return $node;
        }

        foreach ($env->getExtension(FormTwigExtension::class)->getFunctions() as $derafu) {
            if ($derafu->getName() !== $node->getAttribute('name')) {
                continue;
            }

            foreach ($env->getExtension(FormExtension::class)->getFunctions() as $symfony) {
                if ($symfony->getName() !== $derafu->getName()) {
                    continue;
                }

                $arguments = $node->getNode('arguments');
                foreach (['0', 'form', 'field'] as $argumentName) {
                    if ($arguments->hasNode($argumentName)) {
                        return new FormFunctionExpression(
                            $derafu,
                            $symfony,
                            $arguments,
                            $argumentName,
                            $node->getTemplateLine(),
                        );
                    }
                }
            }
        }

        return $node;
    }

    /**
     * {@inheritDoc}
     */
    public function getPriority(): int
    {
        // Run before Twig's escaping analysis inspects the selected branches.
        return -10;
    }
}
