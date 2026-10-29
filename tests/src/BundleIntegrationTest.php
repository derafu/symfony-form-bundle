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
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Form\Contract\Factory\FormFactoryInterface;
use Derafu\Form\Contract\Loader\FormLoaderInterface;
use Derafu\Form\Contract\Processor\FormDataProcessorInterface;
use Derafu\Form\Contract\Renderer\FormRendererInterface;
use Derafu\Form\Loader\PhpFormLoader;
use Derafu\Form\Renderer\FormTwigExtension;
use Derafu\FormBundle\DependencyInjection\Compiler\FormPathsPass;
use Derafu\FormBundle\DependencyInjection\Compiler\FormTemplatesPass;
use Derafu\FormBundle\DependencyInjection\Compiler\ImportMapEntriesPass;
use Derafu\FormBundle\DependencyInjection\Compiler\RegisterCsrfTokenManagerPass;
use Derafu\FormBundle\FormBundle;
use Derafu\TestsFormBundle\Fixture\EmptyBundle;
use Derafu\TestsFormBundle\Fixture\FirstBundle;
use Derafu\TestsFormBundle\Fixture\Kernel;
use Derafu\TestsFormBundle\Fixture\SecondBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

#[CoversClass(FormBundle::class)]
#[CoversClass(FormPathsPass::class)]
#[CoversClass(FormTemplatesPass::class)]
#[CoversClass(ImportMapEntriesPass::class)]
#[CoversClass(RegisterCsrfTokenManagerPass::class)]
final class BundleIntegrationTest extends TestCase
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
        $this->project = sys_get_temp_dir() . '/derafu-form-bundle-' . bin2hex(random_bytes(8));
        mkdir($this->project);
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

    private function write(string $relative, string $content): string
    {
        $path = $this->project . '/' . $relative;
        (new Filesystem())->mkdir(dirname($path));
        file_put_contents($path, $content);

        return $path;
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->project);
        $container->setParameter('kernel.bundles_metadata', []);
        $container->setParameter('derafu.form_bundle.forms_path', $this->project . '/resources/forms');

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<BundleInterface> $bundles
     */
    private function boot(array $config = [], array $bundles = []): Kernel
    {
        // Model a consuming Composer project's existing JavaScript assets.
        (new Filesystem())->mirror(
            InstalledVersions::getInstallPath('derafu/form') . '/resources/js',
            $this->project . '/vendor/derafu/form/resources/js',
        );
        $this->kernel = new Kernel($this->project, $config, $bundles);
        $this->kernel->boot();

        return $this->kernel;
    }

    public function testBundleCompilesAndResolvesItsRealServices(): void
    {
        $container = $this->container();
        $bundle = new FormBundle();
        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
        self::assertSame('derafu_form', $extension->getAlias());
        $extension->prepend($container);
        self::assertSame([
            ['asset_mapper' => ['paths' => [$this->project . '/vendor/derafu/form/resources/js' => 'derafu-form']]],
        ], $container->getExtensionConfig('framework'));
        $this->write('resources/forms/contact.form.php', "<?php return ['data' => ['name' => 'Ada']];");
        $container = $this->boot()->getContainer();
        self::assertInstanceOf(FormFactoryInterface::class, $container->get(FormFactoryInterface::class));
        self::assertInstanceOf(FormDataProcessorInterface::class, $container->get(FormDataProcessorInterface::class));
        self::assertInstanceOf(FormRendererInterface::class, $container->get(FormRendererInterface::class));
        $twig = $container->get(FormTwigExtension::class);
        self::assertInstanceOf(FormTwigExtension::class, $twig);
        self::assertSame('form', $twig->getFunctions()[0]->getName());
        $loader = $container->get(FormLoaderInterface::class);
        self::assertInstanceOf(PhpFormLoader::class, $loader);
        self::assertArrayHasKey('name', $loader->load('contact')->getFields());
    }

    public function testCustomConfigurationReachesTheServices(): void
    {
        $container = $this->boot(['forms_path' => '/custom/forms'])->getContainer();
        self::assertSame('/custom/forms', $container->getParameter('derafu.form_bundle.forms_path'));
    }

    public function testRetiredTwigPrefixOptionIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->boot(['twig_prefix' => 'custom_']);
    }

    public function testFormsAreRegisteredInOverrideOrderAndTheApplicationWins(): void
    {
        $container = $this->container();
        $extension = (new FormBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $container);
        $container->setParameter('kernel.bundles_metadata', [
            'First' => ['path' => $this->project . '/first/src'],
            'Second' => ['path' => $this->project . '/second'],
            'Empty' => ['path' => $this->project . '/empty/src'],
        ]);
        $this->write('first/resources/forms/contact.form.php', "<?php return ['data' => ['first' => 'value']];");
        $this->write('second/resources/forms/contact.form.php', "<?php return ['data' => ['second' => 'value']];");
        $this->write('resources/forms/contact.form.php', "<?php return ['data' => ['application' => 'value']];");
        (new FormPathsPass())->process($container);
        self::assertSame([
            ['addPath', [$this->project . '/first/resources/forms']],
            ['addPath', [$this->project . '/second/resources/forms']],
            ['addPath', ['%kernel.project_dir%/resources/forms']],
        ], $container->getDefinition(FormLoaderInterface::class)->getMethodCalls());
        (new Filesystem())->mkdir([$this->project . '/first/src', $this->project . '/empty/src']);
        $application = $this->boot(bundles: [
            new FirstBundle($this->project . '/first/src'),
            new SecondBundle($this->project . '/second'),
            new EmptyBundle($this->project . '/empty/src'),
        ])->getContainer();
        $loader = $application->get(FormLoaderInterface::class);
        self::assertInstanceOf(PhpFormLoader::class, $loader);
        self::assertArrayHasKey('application', $loader->load('contact')->getFields());
        unlink($this->project . '/resources/forms/contact.form.php');
        self::assertArrayHasKey('second', $loader->load('contact')->getFields());
    }

    public function testFormPassDoesNothingWithoutALoader(): void
    {
        $container = new ContainerBuilder();
        $definitions = $container->getDefinitions();
        (new FormPathsPass())->process($container);
        self::assertSame($definitions, $container->getDefinitions());
    }

    /** @return iterable<string, array{string, array<string, mixed>|string}> */
    public static function invalidMetadata(): iterable
    {
        yield 'collection is not an array' => ['kernel.bundles_metadata', 'invalid'];
        yield 'bundle is not an array' => ['kernel.bundles_metadata', ['Example' => 'invalid']];
        yield 'path is not a string' => ['kernel.bundles_metadata', ['Example' => ['path' => 12]]];
        yield 'application path is not a string' => ['derafu.form_bundle.forms_path', []];
    }

    /** @param array<string, mixed>|string $value */
    #[DataProvider('invalidMetadata')]
    public function testFormPassRejectsInvalidParameterTypes(string $parameter, array|string $value): void
    {
        $container = $this->container();
        $container->register(FormLoaderInterface::class, PhpFormLoader::class);
        $container->setParameter($parameter, $value);
        $this->expectException(TypeMismatchException::class);
        (new FormPathsPass())->process($container);
    }

    public function testExtensionRejectsAnInvalidProjectDirectory(): void
    {
        $container = $this->container();
        $container->setParameter('kernel.project_dir', []);
        $this->expectException(TypeMismatchException::class);
        $extension = (new FormBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);
    }

    public function testImportMapRejectsAnInvalidProjectDirectory(): void
    {
        $container = $this->container();
        $container->setParameter('kernel.project_dir', false);
        $this->expectException(TypeMismatchException::class);
        (new ImportMapEntriesPass())->process($container);
    }

    public function testMissingImportMapIsLeftAbsent(): void
    {
        $this->write('vendor/derafu/form/resources/js/widget.js', 'export {};');
        (new ImportMapEntriesPass())->process($this->container());
        self::assertFileDoesNotExist($this->project . '/importmap.php');
    }

    public function testMissingJavascriptDirectoryLeavesTheImportMapUnchanged(): void
    {
        $original = "<?php return ['app' => ['path' => './app.js', 'preload' => true]];";
        $path = $this->write('importmap.php', $original);
        (new ImportMapEntriesPass())->process($this->container());
        self::assertSame($original, file_get_contents($path));
    }

    public function testImportMapAddsOnlyMissingEntriesAndPreservesExistingValues(): void
    {
        $this->write('vendor/derafu/form/resources/js/widget.js', 'export {};');
        $this->write('vendor/derafu/form/resources/js/custom.js', 'export {};');
        $this->write('vendor/derafu/form/resources/js/ignored.txt', 'ignored');
        $existing = [
            'app' => ['path' => './app.js', 'preload' => true, 'metadata' => ['version' => 2]],
            'derafu-form/custom.js' => ['path' => './custom.js', 'preload' => false],
        ];
        $header = "<?php\n\n// Project imports.\n";
        $path = $this->write('importmap.php', $header . "return [
            'app' => ['path' => './app.js', 'preload' => true, 'metadata' => ['version' => 2]],
            'derafu-form/custom.js' => ['path' => './custom.js', 'preload' => false],
        ];");
        $pass = new ImportMapEntriesPass();
        $pass->process($this->container());
        $actual = require $path;
        self::assertSame($existing + ['derafu-form/widget.js' => ['path' => 'derafu-form/widget.js']], $actual);
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringStartsWith($header, $content);
        $pass->process($this->container());
        self::assertSame($content, file_get_contents($path));
    }

    public function testAlternativePhpArraySyntaxProducesAValidImportMap(): void
    {
        $this->write('vendor/derafu/form/resources/js/widget.js', 'export {};');
        $path = $this->write('importmap.php', "<?php return array('app' => array('path' => './app.js'));");
        (new ImportMapEntriesPass())->process($this->container());
        self::assertSame([
            'app' => ['path' => './app.js'],
            'derafu-form/widget.js' => ['path' => 'derafu-form/widget.js'],
        ], require $path);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidImportMaps(): iterable
    {
        yield 'scalar root' => ['invalid'];
        yield 'scalar entry' => [['app' => 'invalid']];
        yield 'numeric specifier' => [[['path' => './app.js']]];
    }

    #[DataProvider('invalidImportMaps')]
    public function testInvalidImportMapIsRejectedWithoutRewritingIt(mixed $entries): void
    {
        $path = $this->write('importmap.php', '<?php return ' . var_export($entries, true) . ';');
        $original = file_get_contents($path);
        try {
            (new ImportMapEntriesPass())->process($this->container());
            self::fail('Invalid import map was accepted.');
        } catch (TypeMismatchException $exception) {
            self::assertStringContainsString($path, $exception->getMessage());
            self::assertSame($original, file_get_contents($path));
        }
    }

    public function testUnreadableImportMapReportsTheFailure(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root can read files without read permissions.');
        }
        $path = $this->write('importmap.php', '<?php return [];');
        chmod($path, 0000);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot read import map: ' . $path);
            (new ImportMapEntriesPass())->process($this->container());
        } finally {
            chmod($path, 0644);
        }
    }

    public function testUnwritableImportMapReportsTheFailure(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root can write read-only files.');
        }
        $this->write('vendor/derafu/form/resources/js/widget.js', 'export {};');
        $path = $this->write('importmap.php', '<?php return [];');
        chmod($path, 0444);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot write import map: ' . $path);
            (new ImportMapEntriesPass())->process($this->container());
        } finally {
            chmod($path, 0644);
        }
    }
}
