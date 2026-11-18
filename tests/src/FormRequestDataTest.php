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

use Derafu\Array\Exception\ArrayException;
use Derafu\FormBundle\Http\FormRequestData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(FormRequestData::class)]
final class FormRequestDataTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/derafu-form-request-data-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function file(string $name, string $content = '<DTE/>'): UploadedFile
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/xml', null, true);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function data(array $fields = [], array $files = []): array
    {
        return (new FormRequestData())->from(Request::create('/', 'POST', $fields, [], $files));
    }

    public function testItGivesTheFieldsOfTheBody(): void
    {
        $this->assertSame(
            ['name' => 'Ada', 'track' => '12', 'tags' => ['a', 'b']],
            $this->data(['name' => 'Ada', 'track' => '12', 'tags' => ['a', 'b']])
        );
    }

    public function testAFileIsDescribedAsTheArrayThatPhpBuildsInFiles(): void
    {
        $file = $this->file('factura.xml', '<DTE>1234</DTE>');

        $data = $this->data(['track' => '12'], ['archivo' => $file]);

        $this->assertSame('12', $data['track']);
        $this->assertSame(
            [
                'name' => 'factura.xml',
                'type' => 'application/xml',
                'tmp_name' => $file->getPathname(),
                'error' => UPLOAD_ERR_OK,
                'size' => 15,
            ],
            $data['archivo']
        );
    }

    public function testAFieldWhereNoFileWasSelectedIsNull(): void
    {
        $data = $this->data([], [
            'archivo' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        $this->assertArrayHasKey('archivo', $data);
        $this->assertNull($data['archivo']);
    }

    public function testAnUploadErrorKeepsItsCode(): void
    {
        $data = $this->data([], [
            'archivo' => ['name' => 'a.xml', 'type' => 'application/xml', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0],
        ]);

        $this->assertIsArray($data['archivo']);
        $this->assertSame(UPLOAD_ERR_PARTIAL, $data['archivo']['error']);
    }

    public function testAFieldWithManyFilesKeepsTheOrderOfTheFiles(): void
    {
        $data = $this->data([], ['archivos' => [$this->file('uno.xml'), $this->file('dos.xml')]]);

        $this->assertIsArray($data['archivos']);
        $this->assertSame(['uno.xml', 'dos.xml'], array_column($data['archivos'], 'name'));
    }

    public function testNestedFieldsKeepTheirStructureAndTheFilesJoinTheBody(): void
    {
        $data = $this->data(
            ['documento' => ['titulo' => 'Factura']],
            ['documento' => ['adjunto' => $this->file('adjunto.xml')]]
        );

        $this->assertIsArray($data['documento']);
        $this->assertSame('Factura', $data['documento']['titulo']);
        $this->assertIsArray($data['documento']['adjunto']);
        $this->assertSame('adjunto.xml', $data['documento']['adjunto']['name']);
    }

    public function testASubmissionWithAFieldNamedByANumberIsNotAcceptedAsData(): void
    {
        $this->expectException(ArrayException::class);

        $this->data([0 => 'value', 'name' => 'Ada']);
    }

    public function testAFileTakesThePlaceOfAFieldOfTheBodyWithTheSameName(): void
    {
        $data = $this->data(['archivo' => 'texto'], ['archivo' => $this->file('real.xml')]);

        $this->assertIsArray($data['archivo']);
        $this->assertSame('real.xml', $data['archivo']['name']);
    }
}
