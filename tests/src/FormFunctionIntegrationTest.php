<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsFormBundle;

use Composer\InstalledVersions;
use Derafu\Form\Contract\Factory\FormFactoryInterface;
use Derafu\Form\Contract\FormInterface;
use Derafu\Form\Contract\Renderer\FormRendererInterface;
use Derafu\Form\Renderer\FormTwigExtension;
use Derafu\FormBundle\DependencyInjection\Compiler\FormPathsPass;
use Derafu\FormBundle\DependencyInjection\Compiler\FormTemplatesPass;
use Derafu\FormBundle\DependencyInjection\Compiler\ImportMapEntriesPass;
use Derafu\FormBundle\DependencyInjection\Compiler\RegisterCsrfTokenManagerPass;
use Derafu\FormBundle\FormBundle;
use Derafu\FormBundle\Twig\FormIntegrationExtension;
use Derafu\FormBundle\Twig\Node\FormFunctionExpression;
use Derafu\FormBundle\Twig\NodeVisitor\FormFunctionNodeVisitor;
use Derafu\TestsFormBundle\Fixture\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * Exercises both real form implementations through the same Twig functions.
 */
#[CoversClass(FormIntegrationExtension::class)]
#[CoversClass(FormFunctionExpression::class)]
#[CoversClass(FormFunctionNodeVisitor::class)]
#[UsesClass(FormBundle::class)]
#[UsesClass(FormPathsPass::class)]
#[UsesClass(FormTemplatesPass::class)]
#[UsesClass(ImportMapEntriesPass::class)]
#[UsesClass(RegisterCsrfTokenManagerPass::class)]
final class FormFunctionIntegrationTest extends TestCase
{
    private string $project;

    private Kernel $kernel;

    /** @var array<array-key, mixed> */
    private array $environment;

    /** @var array<array-key, mixed> */
    private array $server;

    private string|false $shellVerbosity;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        $this->shellVerbosity = getenv('SHELL_VERBOSITY');
        $this->project = sys_get_temp_dir() . '/derafu-form-functions-' . bin2hex(random_bytes(8));
        (new Filesystem())->mirror(
            InstalledVersions::getInstallPath('derafu/form') . '/resources/js',
            $this->project . '/vendor/derafu/form/resources/js',
        );
        $this->kernel = new Kernel($this->project);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        try {
            $this->kernel->shutdown();
            (new Filesystem())->remove($this->project);
        } finally {
            $_ENV = $this->environment;
            $_SERVER = $this->server;
            putenv($this->shellVerbosity === false ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY=' . $this->shellVerbosity);
        }
    }

    private function form(): FormInterface
    {
        $factory = $this->kernel->getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $factory);

        return $factory->create([
            'schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string', 'title' => 'Name', 'description' => 'Your name']],
            ],
            'data' => ['name' => '<Ada>'],
            'options' => ['csrf_protection' => false, 'attributes' => ['id' => 'contact']],
        ]);
    }

    private function twig(bool $symfonyLast = true): Environment
    {
        $renderer = $this->kernel->getContainer()->get(FormRendererInterface::class);
        self::assertInstanceOf(FormRendererInterface::class, $renderer);
        $twig = new Environment(new FilesystemLoader([
            InstalledVersions::getInstallPath('derafu/form') . '/resources/templates',
            InstalledVersions::getInstallPath('symfony/twig-bridge') . '/Resources/views/Form',
            dirname(__DIR__) . '/fixtures/templates',
        ]), ['strict_variables' => true, 'autoescape' => 'html']);
        $derafu = new FormTwigExtension($renderer);
        $symfony = new FormExtension();
        foreach ($symfonyLast ? [$derafu, $symfony] : [$symfony, $derafu] as $extension) {
            $twig->addExtension($extension);
        }
        $twig->addExtension(new TranslationExtension());
        $twig->addExtension(new FormIntegrationExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(new TwigRendererEngine(['form_div_layout.html.twig'], $twig)),
        ]));

        return $twig;
    }

    private function nativeTwig(LoaderInterface $loader): Environment
    {
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(new TwigRendererEngine(['form_div_layout.html.twig'], $twig)),
        ]));

        return $twig;
    }

    /** @return iterable<string, array{string}> */
    public static function sharedFunctions(): iterable
    {
        yield 'complete form' => ['{{ form(item) }}'];
        yield 'opening tag with options' => ["{{ form_start(item, {'attr': {'class': 'custom'}}) }}"];
        yield 'closing tag' => ['{{ form_end(item) }}'];
        yield 'remaining fields' => ['{{ form_rest(item) }}'];
        yield 'field widget' => ['{{ form_widget(item.name) }}'];
        yield 'widget attributes' => ["{{ form_widget(item.name, {'attr': {'data-note': '<unsafe>'}}) }}"];
        yield 'custom label expression' => ["{{ form_label(item.name, 'label'|upper, {'label_attr': {'class': 'label'}}) }}"];
        yield 'null label' => ["{{ form_label(item.name, null, {'label_attr': {'class': 'label'}}) }}"];
        yield 'empty label' => ["{{ form_label(item.name, '', {'label_attr': {'class': 'label'}}) }}"];
        yield 'end without rest' => ["{{ form_end(item, {'render_rest': false}) }}"];

        yield 'field label' => ['{{ form_label(item.name) }}'];
        yield 'field label and attributes' => ["{{ form_label(item.name, 'Custom label', {'label_attr': {'class': 'label'}}) }}"];
        yield 'field help' => ['{{ form_help(item.name) }}'];
        yield 'field errors' => ['{{ form_errors(item.name) }}'];
        yield 'complete field row' => ['{{ form_row(item.name) }}'];
    }

    #[DataProvider('sharedFunctions')]
    public function testSharedFunctionsPreserveBothRenderingImplementations(string $template): void
    {
        foreach ([true, false] as $symfonyLast) {
            $twig = $this->twig($symfonyLast);
            $form = $this->form();
            // Derafu exposes fields through getField(), Symfony through FormView.
            $derafuTemplate = str_replace('item.name', "item.getField('name')", $template);
            // A separate environment without the visitor is the original
            // implementation's reference, not a production service graph.
            $renderer = $this->kernel->getContainer()->get(FormRendererInterface::class);
            self::assertInstanceOf(FormRendererInterface::class, $renderer);
            $derafuBaseline = new Environment($twig->getLoader(), ['strict_variables' => true, 'autoescape' => 'html']);
            $derafuBaseline->addExtension(new FormTwigExtension($renderer));
            self::assertSame(
                $derafuBaseline->createTemplate($derafuTemplate)->render(['item' => $form]),
                $twig->createTemplate($derafuTemplate)->render(['item' => $this->form()]),
            );

            $factory = Forms::createFormFactory();
            $createForm = static function () use ($factory) {
                $form = $factory->createNamedBuilder('native', FormType::class, ['name' => '<Ada>'])
                    ->add('name', TextType::class, ['label' => 'Name', 'help' => 'Your name'])
                    ->getForm();
                $form->get('name')->addError(new FormError('Invalid <name>'));

                return $form;
            };
            $baseline = $this->nativeTwig($twig->getLoader());
            self::assertSame(
                $baseline->createTemplate($template)->render(['item' => $createForm()->createView()]),
                $twig->createTemplate($template)->render(['item' => $createForm()->createView()]),
            );
        }
    }

    public function testFirstArgumentIsEvaluatedOnlyOnce(): void
    {
        $twig = $this->twig();
        $calls = 0;
        $form = $this->form();
        $twig->addFunction(new TwigFunction('next_form', static function () use (&$calls, $form): FormInterface {
            ++$calls;

            return $form;
        }));
        $html = $twig->createTemplate('{{ form_start(next_form()) }}')->render();
        self::assertStringContainsString('<form', $html);
        self::assertSame(1, $calls);
    }

    /** @return iterable<string, array{string}> */
    public static function derafuFunctions(): iterable
    {
        yield 'body' => ['{{ form_body(item) }}'];
        yield 'encoding' => ['{{ form_enctype(item) }}'];
        yield 'ui element' => ['{{ form_element(item.getUiSchema(), item) }}'];
        yield 'ui elements' => ["{{ form_elements(item.getUiSchema().getElements(), item)|join('') }}"];
        yield 'csrf disabled' => ['{{ form_csrf(item) }}'];
        yield 'captcha disabled' => ['{{ form_captcha(item) }}'];
        yield 'global errors' => ['{{ form_global_errors(item) }}'];
    }

    #[DataProvider('derafuFunctions')]
    public function testFunctionsExclusiveToDerafuKeepTheirBehavior(string $template): void
    {
        $twig = $this->twig();
        $renderer = $this->kernel->getContainer()->get(FormRendererInterface::class);
        self::assertInstanceOf(FormRendererInterface::class, $renderer);
        $reference = new Environment($twig->getLoader(), ['strict_variables' => true, 'autoescape' => 'html']);
        $reference->addExtension(new FormTwigExtension($renderer));
        self::assertSame(
            $reference->createTemplate($template)->render(['item' => $this->form()]),
            $twig->createTemplate($template)->render(['item' => $this->form()]),
        );
    }

    public function testNamedDerafuArgumentsKeepTheirBehavior(): void
    {
        $twig = $this->twig();
        $html = $twig->createTemplate(<<<'TWIG'
{{ form_start(form: item, options: {'attr': {'data-source': 'application'}}) }}
{{ form_widget(field: item.getField('name'), options: {'attr': {'class': 'custom'}}) }}
{{ form_end(form: item, options: {'render_rest': false}) }}
TWIG)->render(['item' => $this->form()]);
        self::assertStringContainsString('data-source="application"', $html);
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('custom', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function nativeLabelExpressions(): iterable
    {
        yield 'false label' => ["{{ form_label(item, false, {'label_attr': {'class': 'custom'}}) }}"];
        yield 'dynamic false label' => ["{{ form_label(item, label, {'label': 'fallback', 'label_attr': {'class': 'custom'}}) }}"];
        yield 'label from runtime variables' => ["{{ form_label(item, null, variables) }}"];
        yield 'dynamic label and variables' => ["{{ form_label(item, text, variables) }}"];
    }

    #[DataProvider('nativeLabelExpressions')]
    public function testSymfonyLabelCompilationIsPreserved(string $template): void
    {
        $twig = $this->twig();
        $view = Forms::createFormFactory()->createNamed('native', TextType::class)->createView();
        $context = ['item' => $view, 'label' => false, 'text' => '<Label>', 'variables' => ['label' => '<Fallback>', 'label_attr' => ['class' => 'custom']]];
        self::assertSame(
            $this->nativeTwig($twig->getLoader())->createTemplate($template)->render($context),
            $twig->createTemplate($template)->render($context),
        );
    }

    public function testNativeFormThemesAndFieldHelpersRemainAvailable(): void
    {
        $twig = $this->twig();
        $view = Forms::createFormFactory()->createNamed('native', TextType::class)->createView();
        $template = <<<'TWIG'
{% form_theme item 'native-theme.html.twig' %}
{{ form_widget(item) }}
TWIG;
        self::assertSame(
            $this->nativeTwig($twig->getLoader())->createTemplate($template)->render(['item' => $view]),
            $twig->createTemplate($template)->render(['item' => Forms::createFormFactory()->createNamed('native', TextType::class)->createView()]),
        );
    }

    public function testMissingArgumentsStillFail(): void
    {
        $this->expectException(SyntaxError::class);
        $this->twig(false)->createTemplate('{{ form_start() }}')->render();
    }
}
