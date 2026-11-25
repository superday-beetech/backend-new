<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * CSRF untuk browser/session, termasuk request API dari Vue.
 * Request API bearer-only boleh melewati CSRF karena Authorization header
 * tidak dikirim otomatis oleh browser; token tetap diverifikasi oleh AuthPolicy.
 *
 * Jika session browser aktif, CSRF tetap wajib meskipun header Bearer ikut terkirim.
 */
final class ApiAwareCsrfFilter implements FilterInterface
{
    private CSRF $csrf;

    public function __construct()
    {
        $this->csrf = new CSRF();
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if (
            $request instanceof IncomingRequest
            && $this->isApiRequest($request)
            && $this->hasBearerHeader($request)
            && ! $this->hasAuthenticatedSession()
        ) {
            return null;
        }

        return $this->csrf->before($request, $arguments);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $this->csrf->after($request, $response, $arguments);
    }

    private function isApiRequest(IncomingRequest $request): bool
    {
        $prefix = trim(config(\Config\AuthPolicy::class)->apiPrefix, '/');
        $path = trim($request->getUri()->getPath(), '/');

        return $prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'));
    }

    private function hasBearerHeader(IncomingRequest $request): bool
    {
        return preg_match('/^Bearer\s+\S+$/i', trim($request->getHeaderLine('Authorization'))) === 1;
    }

    private function hasAuthenticatedSession(): bool
    {
        try {
            return auth('session')->loggedIn();
        } catch (Throwable) {
            // Gagal memeriksa session: jangan membuka jalur bypass CSRF.
            return true;
        }
    }
}
