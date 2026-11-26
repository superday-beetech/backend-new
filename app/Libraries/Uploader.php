<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Services;
use App\Services\Storage\FileHandler;
use RuntimeException;
use Throwable;

class Uploader
{
    private const DEFAULT_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const DEFAULT_ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MIN_THUMB_WIDTH = 1;
    private const MAX_THUMB_WIDTH = 2000;
    private const THUMB_QUALITY   = 85;

    /**
     * Kompatibilitas dengan pemanggilan library lama.
     *
     * @return array{
     *     status: bool,
     *     filename: string|null,
     *     file_id?: int|string|null,
     *     thumbnail_id?: int|string|null,
     *     message: string
     * }
     */
    public function upload(
        UploadedFile $file,
        string $folder,
        array $allowedExtensions = self::DEFAULT_ALLOWED_EXTENSIONS,
        int $thumbWidth = 150
    ): array {
        return $this->uploadWithThumbnail(
            $file,
            $folder,
            $allowedExtensions,
            $thumbWidth
        );
    }

    /**
     * @return array{
     *     status: bool,
     *     filename: string|null,
     *     file_id?: int|string|null,
     *     thumbnail_id?: int|string|null,
     *     message: string
     * }
     */
    public function uploadWithThumbnail(
        UploadedFile $file,
        string $_folder,
        array $allowedExtensions = self::DEFAULT_ALLOWED_EXTENSIONS,
        int $thumbWidth = 150
    ): array {
        if (! $file->isValid() || $file->hasMoved()) {
            return $this->failure('File tidak valid atau sudah dipindahkan.');
        }

        if ($thumbWidth < self::MIN_THUMB_WIDTH || $thumbWidth > self::MAX_THUMB_WIDTH) {
            return $this->failure('Ukuran thumbnail tidak valid.');
        }

        $validation = $this->validateFile($file, $allowedExtensions);
        if ($validation !== null) {
            return $this->failure($validation);
        }

        $extension = strtolower($file->getExtension());
        $sourcePath = $file->getTempName();

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            return $this->failure('File sementara tidak dapat dibaca.');
        }

        $handler         = new FileHandler();
        $fileId          = null;
        $thumbnailId     = null;
        $tempThumbnail   = null;

        try {
            // 1. Upload file asli.
            $stored = $handler->upload(
                sourcePath: $sourcePath,
                originalName: $file->getName(),
                visibility: 'private'
            );

            $fileId = $stored['id'] ?? null;

            if ($fileId === null) {
                throw new RuntimeException('Storage tidak mengembalikan ID file.');
            }

            // 2. Buat thumbnail sementara.
            $tempThumbnail = $this->createTempThumbnail(
                sourcePath: $sourcePath,
                thumbWidth: $thumbWidth,
                extension: $extension
            );

            // 3. Upload thumbnail sebagai file Storage tersendiri.
            $thumbnail = $handler->upload(
                sourcePath: $tempThumbnail,
                originalName: 'thumbnail-' . $fileId . '.' . pathinfo($tempThumbnail, PATHINFO_EXTENSION),
                visibility: 'private'
            );

            $thumbnailId = $thumbnail['id'] ?? null;

            if ($thumbnailId === null) {
                throw new RuntimeException('Storage tidak mengembalikan ID thumbnail.');
            }

            return [
                'status'       => true,
                'filename'     => (string) $fileId,
                'file_id'      => $fileId,
                'thumbnail_id' => $thumbnailId,
                'message'      => 'Upload dan thumbnail berhasil.',
            ];
        } catch (Throwable $e) {
            log_message(
                'error',
                'Uploader Storage gagal: {message}',
                ['message' => $e->getMessage()]
            );

            // Rollback: hapus file yang baru dibuat di request ini.
            $this->safeDeleteStored($handler, $thumbnailId);
            $this->safeDeleteStored($handler, $fileId);

            return $this->failure('Upload atau pembuatan thumbnail gagal.');
        } finally {
            if ($tempThumbnail !== null && is_file($tempThumbnail)) {
                if (! @unlink($tempThumbnail)) {
                    log_message(
                        'warning',
                        'Uploader gagal menghapus file sementara: {path}',
                        ['path' => $tempThumbnail]
                    );
                }
            }
        }
    }

    /**
     * Validasi ekstensi, MIME, dan isi gambar sebelum menyentuh Storage.
     *
     * @return string|null Pesan error, atau null jika valid.
     */
    private function validateFile(UploadedFile $file, array $allowedExtensions): ?string
    {
        $extension = strtolower($file->getExtension());
        $allowed   = array_map('strtolower', $allowedExtensions);

        if (! in_array($extension, $allowed, true)) {
            return 'Ekstensi file tidak diizinkan.';
        }

        // MIME dari server (finfo), bukan dari client.
        $mime = $file->getMimeType();

        if ($mime === null || ! in_array($mime, self::DEFAULT_ALLOWED_MIMES, true)) {
            return 'Tipe file tidak diizinkan.';
        }

        // Pastikan isi benar-benar gambar dan ekstensi konsisten dengan MIME.
        $info = @getimagesize($file->getTempName());

        if ($info === false) {
            return 'File bukan gambar yang valid.';
        }

        $expectedMime = $info['mime'] ?? null;

        if ($expectedMime === null || $expectedMime !== $mime) {
            return 'Isi file tidak sesuai dengan tipe yang diklaim.';
        }

        return null;
    }

    /**
     * Buat thumbnail sementara; pertahankan transparansi untuk PNG/WEBP.
     */
    private function createTempThumbnail(
        string $sourcePath,
        int $thumbWidth,
        string $extension
    ): string {
        $tempDirectory = WRITEPATH . 'uploads/tmp/';

        if (
            ! is_dir($tempDirectory)
            && ! mkdir($tempDirectory, 0750, true)
            && ! is_dir($tempDirectory)
        ) {
            throw new RuntimeException('Gagal membuat direktori sementara.');
        }

        $thumbExtension = in_array($extension, ['png', 'webp'], true) ? 'png' : 'jpg';
        $tempThumbnail  = $tempDirectory
            . bin2hex(random_bytes(16))
            . '.' . $thumbExtension;

        Services::image()
            ->withFile($sourcePath)
            ->resize($thumbWidth, $thumbWidth, true, 'width')
            ->save($tempThumbnail, self::THUMB_QUALITY);

        if (! is_file($tempThumbnail)) {
            throw new RuntimeException('Thumbnail gagal dibuat.');
        }

        return $tempThumbnail;
    }

    /**
     * Hapus file Storage yang dibuat di request ini; tidak pernah melempar.
     */
    private function safeDeleteStored(FileHandler $handler, int|string|null $fileId): void
    {
        if ($fileId === null) {
            return;
        }

        if (! method_exists($handler, 'delete')) {
            log_message(
                'warning',
                'Uploader tidak dapat rollback file Storage: FileHandler::delete() tidak tersedia.'
            );
            return;
        }

        try {
            // $handler->delete($fileId);
        } catch (Throwable $e) {
            log_message(
                'error',
                'Uploader gagal rollback file Storage {id}: {message}',
                ['id' => $fileId, 'message' => $e->getMessage()]
            );
        }
    }

    private function failure(string $message): array
    {
        return [
            'status'   => false,
            'filename' => null,
            'message'  => $message,
        ];
    }

    /**
     * @deprecated Penghapusan fisik dikelola oleh lifecycle Storage.
     *             Method ini tidak melakukan apa pun dan akan dihapus.
     */
    public function deleteImage(string $folder, string $filename): bool
    {
        log_message(
            'warning',
            'Uploader::deleteImage() tidak menghapus file fisik. '
                . 'Gunakan pengelolaan relasi dan lifecycle Storage.'
        );

        return false;
    }
}
