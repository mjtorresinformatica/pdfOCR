<?php

declare(strict_types=1);

namespace PdfOcr;

use RuntimeException;

/**
 * Validates and stores an uploaded PDF.
 */
final class Uploader
{
    /** @param array<string,mixed> $config The "upload" section, plus a target directory. */
    public function __construct(private array $config, private string $targetDir)
    {
    }

    /**
     * @param array<string,mixed> $file One entry of $_FILES
     *
     * @return array{original_name:string,stored_name:string,path:string,size:int,sha256:string,mime:string}
     */
    public function store(array $file): array
    {
        $this->assertUploadOk($file);

        $originalName = (string) ($file['name'] ?? 'document.pdf');
        $tmp = (string) $file['tmp_name'];
        $size = (int) $file['size'];

        if ($size <= 0) {
            throw new RuntimeException('The file is empty.');
        }
        if ($size > (int) $this->config['max_bytes']) {
            throw new RuntimeException(sprintf(
                'The file is %s; the limit is %s.',
                self::humanBytes($size),
                self::humanBytes((int) $this->config['max_bytes'])
            ));
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->config['allowed_extension'], true)) {
            throw new RuntimeException('Only PDF files are accepted.');
        }

        $mime = $this->detectMime($tmp);
        if (!in_array($mime, $this->config['allowed_mime'], true)) {
            throw new RuntimeException('That file is not a PDF (detected ' . $mime . ').');
        }
        if (!$this->looksLikePdf($tmp)) {
            throw new RuntimeException('That file does not start with a PDF header.');
        }

        $sha256 = (string) hash_file('sha256', $tmp);
        $storedName = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $path = $this->targetDir . '/' . $storedName;

        if (!is_dir($this->targetDir) && !mkdir($this->targetDir, 0775, true) && !is_dir($this->targetDir)) {
            throw new RuntimeException('Cannot create the uploads directory.');
        }
        if (!move_uploaded_file($tmp, $path)) {
            throw new RuntimeException('Cannot move the uploaded file into storage.');
        }

        return [
            'original_name' => self::sanitiseName($originalName),
            'stored_name'   => $storedName,
            'path'          => $path,
            'size'          => $size,
            'sha256'        => $sha256,
            'mime'          => $mime,
        ];
    }

    /** @param array<string,mixed> $file */
    private function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            if (!is_uploaded_file((string) $file['tmp_name'])) {
                throw new RuntimeException('The upload could not be verified.');
            }

            return;
        }

        throw new RuntimeException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit (see upload_max_filesize).',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE                        => 'No file was received.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'PHP has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE                     => 'PHP cannot write to its temporary directory.',
            UPLOAD_ERR_EXTENSION                      => 'A PHP extension blocked the upload.',
            default                                   => 'The upload failed (code ' . $error . ').',
        });
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $path);
                finfo_close($finfo);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }

    private function looksLikePdf(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 5);
        fclose($handle);

        return str_starts_with($head, '%PDF-');
    }

    public static function sanitiseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;

        return mb_substr(trim($name) !== '' ? $name : 'document.pdf', 0, 200);
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }
}
