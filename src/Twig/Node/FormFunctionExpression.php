<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\Twig\Node;

use Derafu\Form\Contract\FormFieldInterface;
use Derafu\Form\Contract\FormInterface;
use Symfony\Bridge\Twig\Node\RenderBlockNode;
use Symfony\Bridge\Twig\Node\SearchAndRenderBlockNode;
use Twig\Compiler;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\Node;
use Twig\TwigFunction;

/**
 * Selects a form implementation without changing either rendering contract.
 *
 * Symfony's own nodes compile the Symfony branch, including label handling.
 * The first argument is evaluated once, even when it is a method call.
 */
final class FormFunctionExpression extends FunctionExpression
{
    public function __construct(
        TwigFunction $derafu,
        TwigFunction $symfony,
        Node $arguments,
        string $argumentName,
        int $line,
    ) {
        $argument = $arguments->getNode($argumentName);
        $variable = new LocalVariable(null, $line);
        $derafuArguments = clone $arguments;
        $symfonyArguments = clone $arguments;
        $derafuArguments->setNode($argumentName, $variable);
        $symfonyArguments->setNode($argumentName, $variable);

        $native = $symfony->getNodeClass() === RenderBlockNode::class
            ? new RenderBlockNode($symfony, $symfonyArguments, $line)
            : new SearchAndRenderBlockNode($symfony, $symfonyArguments, $line);

        // Both branches produce HTML. Keep Symfony's safety metadata so the
        // native renderer's string is not escaped by the surrounding template.
        parent::__construct($symfony, $arguments, $line);
        $this->setNode('argument', $argument);
        $this->setNode('variable', $variable);
        $this->setNode('derafu', new FunctionExpression($derafu, $derafuArguments, $line));
        $this->setNode('symfony', $native);
    }

    /**
     * {@inheritDoc}
     */
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->raw('((((')
            ->subcompile($this->getNode('variable'))
            ->raw(' = ')
            ->subcompile($this->getNode('argument'))
            ->raw(') instanceof \\' . FormInterface::class . ') || (')
            ->subcompile($this->getNode('variable'))
            ->raw(' instanceof \\' . FormFieldInterface::class . ')) ? ')
            ->subcompile($this->getNode('derafu'))
            ->raw(' : ')
            ->subcompile($this->getNode('symfony'))
            ->raw(')');
    }
}
