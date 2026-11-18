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
use Derafu\Form\Contract\Processor\FormDataProcessorInterface;
use Derafu\Form\Contract\Processor\ProcessResultInterface;
use Derafu\FormBundle\Http\FormRequestData;
use Derafu\TestsFormBundle\Fixture\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The data that the bundle builds from a request is validated by the real form
 * processor, in a real application: a file field is validated as it is with a
 * file that comes from `$_FILES`.
 */
#[CoversNothing]
final class FormRequestDataIntegrationTest extends TestCase
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
        $this->project = sys_get_temp_dir() . '/derafu-form-request-data-integration-' . bin2hex(random_bytes(8));
        (new Filesystem())->mirror(
            InstalledVersions::getInstallPath('derafu/form') . '/resources/js',
            $this->project . '/vendor/derafu/form/resources/js',
        );
        mkdir($this->project . '/uploads');
        $this->kernel = new Kernel($this->project);
        $this->kernel->boot();
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

    private function form(bool $multiple = false): FormInterface
    {
        $control = ['type' => 'file', 'accept' => '.xml'];
        if ($multiple) {
            $control['multiple'] = true;
        }

        return $this->service(FormFactoryInterface::class)->create([
            'schema' => [
                'name' => 'upload',
                'type' => 'object',
                'required' => ['archivo'],
                'properties' => [
                    'archivo' => $multiple ? ['type' => 'array', 'items' => ['type' => 'string']] : ['type' => 'string', 'title' => 'File'],
                    'trackId' => ['type' => 'integer', 'title' => 'Track ID'],
                ],
            ],
            'uischema' => [
                'type' => 'VerticalLayout',
                'elements' => [
                    ['type' => 'Control', 'scope' => '#/properties/archivo', 'options' => $control],
                    ['type' => 'Control', 'scope' => '#/properties/trackId'],
                ],
            ],
            'options' => ['csrf_protection' => false],
        ]);
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = $this->project . '/uploads/' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/xml', null, true);
    }

    /**
     * @param array<string, mixed> $files
     */
    private function process(array $files, bool $multiple = false): ProcessResultInterface
    {
        $request = Request::create('/', 'POST', ['trackId' => '12'], [], $files);

        return $this->service(FormDataProcessorInterface::class)->process(
            $this->form($multiple),
            $this->service(FormRequestData::class)->from($request)
        );
    }

    public function testAValidFileAndItsFieldsAreProcessed(): void
    {
        $result = $this->process(['archivo' => $this->file('factura.xml', '<?xml version="1.0"?><DTE/>')]);

        $this->assertTrue($result->isValid());
        $this->assertSame(12, $result->getReader()->requireAsInt('trackId'));
        $this->assertSame('factura.xml', $result->getReader()->requireString('archivo.name'));
    }

    public function testAnEmptyFileIsRejected(): void
    {
        $result = $this->process(['archivo' => $this->file('vacio.xml', '')]);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasFieldErrors('archivo'));
    }

    public function testAFileOfAnotherTypeIsRejectedByItsContent(): void
    {
        $result = $this->process(['archivo' => $this->file('imagen.xml', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64))]);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasFieldErrors('archivo'));
    }

    public function testARequiredFileThatWasNotSelectedIsMissing(): void
    {
        $result = $this->process([
            'archivo' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasFieldErrors('archivo'));
    }

    public function testAFieldWithManyFilesIsProcessed(): void
    {
        $result = $this->process([
            'archivo' => [$this->file('uno.xml', '<DTE/>'), $this->file('dos.xml', '<DTE/>')],
        ], multiple: true);

        $this->assertTrue($result->isValid());
    }

    public function testAFieldWithManyFilesWhereNoneWasSelectedIsMissing(): void
    {
        $result = $this->process([
            'archivo' => ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]],
        ], multiple: true);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasFieldErrors('archivo'));
    }

    public function testTheFilesOfSymfonyAreRejectedByTheProcessorAsTheyAre(): void
    {
        $request = Request::create('/', 'POST', ['trackId' => '12'], [], [
            'archivo' => $this->file('factura.xml', '<?xml version="1.0"?><DTE/>'),
        ]);

        $result = $this->service(FormDataProcessorInterface::class)->process(
            $this->form(),
            $request->request->all() + $request->files->all()
        );

        $this->assertTrue($result->hasFieldErrors('archivo'));
    }
}
