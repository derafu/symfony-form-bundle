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

use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Exception\CaptchaUnavailableException;

/**
 * A captcha provider that needs no service: what solves a form is the text
 * `solved-<id of the form>`, and the text `unavailable` is a service that does
 * not answer. It can also be not available (nothing configured) or disabled (on
 * purpose). It keeps what it was asked to verify.
 */
final class InMemoryCaptchaProvider implements CaptchaProviderInterface
{
    /**
     * What was asked to verify, in order: `[token, form id]`.
     *
     * @var list<array{string, string}>
     */
    public array $verified = [];

    public function __construct(
        private readonly bool $available = true,
        private readonly bool $disabled = false
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function getResponseField(): string
    {
        return 'test-captcha-response';
    }

    public function getWidget(string $formId): string
    {
        return '<div class="test-captcha" data-form="' . $formId . '"></div>';
    }

    public function verify(string $token, string $formId): bool
    {
        $this->verified[] = [$token, $formId];

        if ($token === 'unavailable') {
            throw new CaptchaUnavailableException('The captcha service did not answer.');
        }

        return $token === 'solved-' . $formId;
    }
}
