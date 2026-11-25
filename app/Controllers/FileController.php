<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class FileController extends Controller
{
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    // Gambar produk: PUBLIK (jika katalog publik)
    public function productImage(string $filename): ResponseInterface
    {
        $this->validateFilename($filename);

        return $this->serveFile(
            WRITEPATH . 'uploads/products/' . $filename,
            private: false
        );
    }

    // Gambar temp: PRIVAT + ownership
    public function tempImage(string $filename): ResponseInterface
    {
        $this->validateFilename($filename);

        $user = auth()->user();
        if ($user === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Ownership: filename harus diawali "{userId}_"
        if (! str_starts_with($filename, $user->id . '_')) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->serveFile(
            WRITEPATH . 'uploads/products/temp/' . $filename,
            private: true
        );
    }

    private function validateFilename(string $filename): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1
            || str_contains($filename, '..')
            || str_starts_with($filename, '.')
        ) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function serveFile(string $path, bool $private): ResponseInterface
    {
        // Defense in depth: realpath harus di bawah base dir
        $base = realpath(dirname($path));
        $real = realpath($path);

        if ($base === false || $real === false
            || ! str_starts_with($real, $base . DIRECTORY_SEPARATOR)
            || ! is_file($real)
        ) {
            throw PageNotFoundException::forPageNotFound();
        }

        $mime = mime_content_type($real);
        if (! is_string($mime) || ! array_key_exists($mime, self::ALLOWED_MIMES)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Disposition', 'inline; filename="' . basename($real) . '"')
            ->setHeader(
                'Cache-Control',
                $private
                    ? 'private, no-store, max-age=0'
                    : 'public, max-age=31536000, immutable'
            )
            ->setBody(file_get_contents($real));
    }
}
