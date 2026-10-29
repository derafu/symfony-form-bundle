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
use Derafu\DataProcessor\Contract\ProcessorInterface;
use Derafu\DataProcessor\Exception\ValidationException;
use Derafu\Form\Contract\Captcha\CaptchaProviderInterface;
use Derafu\Form\Contract\Csrf\CsrfTokenManagerInterface;
use Derafu\Form\Contract\Factory\FormFactoryInterface;
use Derafu\Form\Contract\FormInterface;
use Derafu\Form\Contract\Processor\FormDataProcessorInterface;
use Derafu\Form\Contract\Renderer\FormRendererInterface;
use Derafu\Form\Contract\Type\TypeRegistryInterface;
use Derafu\Form\Contract\Type\TypeResolverInterface;
use Derafu\Form\Factory\TranslatingFormFactory;
use Derafu\Form\Renderer\FormTwigExtension;
use Derafu\Form\Type\TypeRegistry;
use Derafu\Form\Type\TypeResolver;
use Derafu\Renderer\Contract\RendererInterface;
use Derafu\TestsFormBundle\Fixture\Kernel;
use Derafu\Translation\Exception\Core\TranslatableLogicException;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Derafu\Twig\Contract\TwigServiceInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface as SymfonyFormFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Verifies imported package services and tag consumption in a real application.
 */
#[CoversNothing]
final class ServicesTest extends TestCase
{
    private string $project;

    private ?Kernel $kernel = null;

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
        $this->project = sys_get_temp_dir() . '/derafu-form-services-' . bin2hex(random_bytes(8));
        (new Filesystem())->mirror(
            InstalledVersions::getInstallPath('derafu/form') . '/resources/js',
            $this->project . '/vendor/derafu/form/resources/js',
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->kernel?->shutdown();
            (new Filesystem())->remove($this->project);
        } finally {
            $_ENV = $this->environment;
            $_SERVER = $this->server;
            putenv($this->shellVerbosity === false ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY=' . $this->shellVerbosity);
        }
    }

    /** @param array<string, mixed> $config */
    private function boot(bool $applicationServices = false, array $config = [], bool $nativeForms = false): void
    {
        $this->kernel = new Kernel($this->project, $config, applicationServices: $applicationServices, nativeForms: $nativeForms);
        $this->kernel->boot();
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        self::assertNotNull($this->kernel);
        $service = $this->kernel->getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /** @param array<string, mixed> $options */
    private function form(array $options = []): FormInterface
    {
        return $this->service(FormFactoryInterface::class)->create([
            'schema' => [
                'name' => 'contact',
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string', 'title' => 'Name', 'minLength' => 3]],
            ],
            'options' => array_replace(['csrf_protection' => false], $options),
        ]);
    }

    public function testEveryImportedServiceCanBeConstructed(): void
    {
        $this->boot();
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer();
        self::assertInstanceOf(Container::class, $container);
        foreach ($container->getServiceIds() as $id) {
            if (str_starts_with($id, 'Derafu\\')) {
                $container->get($id);
            }
        }
        self::assertInstanceOf(TranslatingFormFactory::class, $this->service(FormFactoryInterface::class));
        self::assertInstanceOf(TypeRegistry::class, $this->service(TypeRegistryInterface::class));
        self::assertInstanceOf(TypeResolver::class, $this->service(TypeResolverInterface::class));
    }

    public function testSymfonyTwigConsumesTheSharedExtensionAndRendersARealForm(): void
    {
        $this->boot();
        self::assertNotNull($this->kernel);
        $twig = $this->kernel->getContainer()->get('test.twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame($this->service(FormTwigExtension::class), $twig->getExtension(FormTwigExtension::class));
        $html = $twig->createTemplate('{{ form(form) }}')->render(['form' => $this->form()]);
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('<form', $html);
        self::assertStringNotContainsString('&lt;form', $html);
    }

    public function testFunctionsDoNotRequireOrRegisterAPrefix(): void
    {
        $this->boot();
        self::assertNotNull($this->kernel);
        $twig = $this->kernel->getContainer()->get('test.twig');
        self::assertInstanceOf(Environment::class, $twig);
        foreach ($this->service(FormTwigExtension::class)->getFunctions() as $function) {
            self::assertNotNull($twig->getFunction($function->getName()));
            self::assertNull($twig->getFunction('derafu_' . $function->getName()));
        }
    }

    public function testNativeTranslatorIsInjectedIntoTheDecoratedFactory(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->project . '/translations');
        file_put_contents($this->project . '/translations/contact+intl-icu.es.php', "<?php return ['Name' => 'Nombre'];");
        $this->boot();
        self::assertNotNull($this->kernel);
        $translator = $this->kernel->getContainer()->get('test.translator');
        self::assertInstanceOf(Translator::class, $translator);
        $form = $this->form(['translation_domain' => 'contact']);
        self::assertStringContainsString('Nombre', $this->service(FormRendererInterface::class)->render($form));
    }

    public function testFormAndDataProcessorProvidersTranslateRealErrors(): void
    {
        $this->boot();
        $processor = $this->service(FormDataProcessorInterface::class);
        $form = $this->form();
        $valid = $processor->process($form, ['name' => 'Ada']);
        self::assertTrue($valid->isValid());
        self::assertSame('Ada', $valid->getReader()->requireString('name'));
        $invalid = $processor->process($form, ['name' => 'ab']);
        self::assertFalse($invalid->isValid());
        self::assertSame(['Debe tener al menos 3 caracteres.'], $invalid->getFieldErrors('name'));
        self::assertNotNull($this->kernel);
        $translator = $this->kernel->getContainer()->get('test.translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        try {
            $this->service(FormFactoryInterface::class)->create([]);
            self::fail('A definition without schema or data was accepted.');
        } catch (TranslatableInvalidArgumentException $exception) {
            self::assertSame('La definición del esquema del formulario debe asignarse si no se entregan los datos del formulario.', $exception->trans($translator));
        }
    }

    public function testConfiguredElementRenderersUseTheTranslatedActionResolver(): void
    {
        $this->boot();
        $form = $this->service(FormFactoryInterface::class)->create([
            'schema' => ['type' => 'object', 'properties' => ['password' => ['type' => 'string']]],
            'uischema' => [
                'type' => 'VerticalLayout',
                'elements' => [[
                    'type' => 'Control',
                    'scope' => '#/properties/password',
                    'options' => ['type' => 'password', 'actions' => ['toggle-password', 'copy']],
                ]],
            ],
            'options' => ['csrf_protection' => false],
        ]);
        $html = $this->service(FormRendererInterface::class)->render($form);
        self::assertStringContainsString('Mostrar u ocultar la contraseña', $html);
        self::assertStringContainsString('Copiar valor', $html);
    }

    public function testTaggedApplicationRegistrarsExtendTheDefaultProcessor(): void
    {
        $this->boot(applicationServices: true);
        $processor = $this->service(ProcessorInterface::class);
        self::assertSame('ABC', $processor->process('abc', ['transform' => ['uppercase'], 'validate' => ['application_min_length:3']]));
        $this->expectException(ValidationException::class);
        $processor->process('ab', ['validate' => ['application_min_length:3']]);
    }

    public function testOptionalProtectionServicesReachTheRendererAndProcessor(): void
    {
        $this->boot(applicationServices: true);
        $form = $this->form(['csrf_protection' => true, 'captcha_protection' => true]);
        $manager = $this->service(CsrfTokenManagerInterface::class);
        $captcha = $this->service(CaptchaProviderInterface::class);
        $token = $manager->getToken($form->getCsrfTokenId());
        $html = $this->service(FormRendererInterface::class)->render($form);
        self::assertStringContainsString('value="' . $token . '"', $html);
        self::assertStringContainsString('class="test-captcha"', $html);
        $processor = $this->service(FormDataProcessorInterface::class);
        self::assertFalse($processor->process($form, ['name' => 'Ada'])->isValid());
        self::assertFalse($processor->process($form, ['name' => 'Ada', FormInterface::CSRF_FIELD => $token, $captcha->getResponseField() => 'wrong'])->isValid());
        $valid = $processor->process($form, ['name' => 'Ada', FormInterface::CSRF_FIELD => $token, $captcha->getResponseField() => 'solved-' . $form->getId()]);
        self::assertTrue($valid->isValid());
        self::assertSame('Ada', $valid->getReader()->requireString('name'));
        self::assertFalse($valid->getReader()->hasKey(FormInterface::CSRF_FIELD));
        self::assertFalse($valid->getReader()->hasKey($captcha->getResponseField()));
    }

    public function testProtectionServicesRemainOptionalForUnprotectedForms(): void
    {
        $this->boot();
        self::assertNotNull($this->kernel);
        self::assertFalse($this->kernel->getContainer()->has(CsrfTokenManagerInterface::class));
        self::assertFalse($this->kernel->getContainer()->has(CaptchaProviderInterface::class));
        $form = $this->form();
        self::assertStringNotContainsString('name="' . FormInterface::CSRF_FIELD . '"', $this->service(FormRendererInterface::class)->render($form));
        self::assertTrue($this->service(FormDataProcessorInterface::class)->process($form, ['name' => 'Ada'])->isValid());
    }

    public function testProtectedFormsFailWhenTheApplicationProvidesNoTokenManager(): void
    {
        $this->boot();
        $form = $this->form(['csrf_protection' => true]);
        $this->expectException(TranslatableLogicException::class);
        $this->service(FormDataProcessorInterface::class)->process($form, ['name' => 'Ada']);
    }

    public function testBothFormSystemsRenderInTheSameNativeTwigEnvironment(): void
    {
        $this->boot(nativeForms: true);
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer();
        $twig = $container->get('test.twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame($twig, $this->service(TwigServiceInterface::class)->getTwig());
        self::assertSame($this->service(RendererInterface::class), $this->service(FormRendererInterface::class)->getRenderer());
        $factory = $container->get('test.symfony_form_factory');
        self::assertInstanceOf(SymfonyFormFactoryInterface::class, $factory);
        $native = $factory->createNamedBuilder('native', FormType::class)
            ->add('name', TextType::class)
            ->getForm()->createView();
        $html = $twig->createTemplate(<<<'TWIG'
{{ form_start(derafu) }}
{{ form_row(derafu.getField('name')) }}
{{ form_end(derafu) }}
{{ form_start(symfony) }}
{{ form_row(symfony.name) }}
{{ form_end(symfony) }}
TWIG)->render(['derafu' => $this->form(), 'symfony' => $native]);
        self::assertSame(2, substr_count($html, '<form'));
        self::assertSame(2, substr_count($html, '</form>'));
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('name="native[name]"', $html);
        self::assertStringNotContainsString('&lt;form', $html);
    }

    public function testApplicationTemplatesOverrideFormPackageTemplates(): void
    {
        (new Filesystem())->mkdir($this->project . '/templates/form');
        file_put_contents($this->project . '/templates/form/form_start.html.twig', '<form data-application="custom">');
        $this->boot();
        self::assertStringContainsString('<form data-application="custom">', $this->service(FormRendererInterface::class)->render($this->form()));
    }
}
