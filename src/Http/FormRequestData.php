<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Form Bundle - Integrates derafu/form with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\FormBundle\Http;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds from a Symfony request the data that a Derafu form processes.
 *
 * Symfony keeps the fields of a submission in `$request->request` and the
 * uploaded files in `$request->files`, as `UploadedFile` objects. The form
 * processor validates a file field only when its value is a PSR-7 uploaded file
 * or the array that PHP builds in `$_FILES` for a file: it does not accept
 * Symfony's `UploadedFile`. This class gives both in one array, with every file
 * described as the `$_FILES` array.
 */
final class FormRequestData
{
    /**
     * The fields and the files of the request, in the shape that the form
     * processor validates.
     *
     * A file field is the array `name`, `type`, `tmp_name`, `error` and `size`.
     * A file field where no file was selected is `null`, so the processor reports
     * it as missing. A field that holds many files, or that is nested in
     * another one, keeps the structure of the submission. A file takes the place
     * of a field of the body that has the same name.
     *
     * @return array<array-key, mixed>
     */
    public function from(Request $request): array
    {
        return array_replace_recursive(
            $request->request->all(),
            $this->files($request->files->all())
        );
    }

    /**
     * Describes each uploaded file of the request as the `$_FILES` array.
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function files(array $files): array
    {
        $data = [];

        foreach ($files as $name => $file) {
            $data[$name] = match (true) {
                $file instanceof UploadedFile => $this->describe($file),
                is_array($file) => $this->files($file),
                default => null,
            };
        }

        return $data;
    }

    /**
     * The array that PHP builds in `$_FILES` for an uploaded file.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function describe(UploadedFile $file): array
    {
        $size = $file->getSize();

        return [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'tmp_name' => $file->getPathname(),
            'error' => $file->getError(),
            'size' => is_int($size) ? $size : 0,
        ];
    }
}
