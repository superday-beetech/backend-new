<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use App\Libraries\DynamicRouter\AuthenticationResolver;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\AuthPolicy;
use Modules\Core\Services\OrganizationContextService;
use Throwable;

final class OrganizationContextFilter implements FilterInterface
{
    /**
     * Endpoint yang memang harus dapat diakses
     * sebelum organisasi aktif dipilih.
     */
    private const CONTEXT_FREE_ENDPOINTS = [
        'organization-context/index-context',
        'organization-context/index-available',
        'organization-context/switch-organization',
        'organization-context/clear-context',
        'organizations/create-organization',
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return is_cli()
                ? null
                : service('response')->setStatusCode(400);
        }

        $path = strtolower(trim((string) $request->getPath(), '/'));

        // Filter ini hanya ditujukan untuk API.
        $apiPrefix = trim(config(AuthPolicy::class)->apiPrefix, '/');

        if (
            $path !== $apiPrefix
            && ! str_starts_with($path, $apiPrefix . '/')
        ) {
            return null;
        }

        /*
         * Hilangkan prefix API dan versi.
         * Contoh:
         * api/v1/core/organization/context
         * menjadi:
         * core/organization/context
         */
        $segments = explode('/', $path);

        if (
            count($segments) < 4
            || $segments[0] !== $apiPrefix
            || preg_match('/^v\d+$/', $segments[1]) !== 1
        ) {
            return null;
        }

        $module = $segments[2];

        // Filter ini hanya mengamankan API modul Core.
        if ($module !== 'core') {
            return null;
        }

        $endpoint = implode('/', array_slice($segments, 3));

        if (in_array($endpoint, self::CONTEXT_FREE_ENDPOINTS, true)) {
            return null;
        }

        try {
            $resolver = new AuthenticationResolver(
                $request,
                config(AuthPolicy::class)
            );

            $user = $resolver->getAuthenticatedUser();

            if ($user === null || (int) $user->id <= 0) {
                return $this->deny(
                    'ORGANIZATION_AUTH_REQUIRED',
                    'Autentikasi diperlukan untuk mengakses konteks organisasi.',
                    401
                );
            }

            $contextService = new OrganizationContextService();

            $context = $contextService->getCurrentContext(
                (int) $user->id
            );

            if (
                $context === null
                || empty($context['organization']['id'])
            ) {
                return $this->deny(
                    'ORGANIZATION_CONTEXT_REQUIRED',
                    'Pilih organisasi aktif sebelum mengakses layanan ini.',
                    409
                );
            }

            return null;
        } catch (Throwable $e) {
            log_message(
                'critical',
                '[Organization Context Filter] '
                    . $e::class . ': ' . $e->getMessage()
            );

            return $this->deny(
                'ORGANIZATION_CONTEXT_UNAVAILABLE',
                'Konteks organisasi tidak dapat divalidasi.',
                503
            );
        }
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ): void {
        // Tidak ada proses setelah request.
    }

    private function deny(
        string $error,
        string $message,
        int $status
    ): ResponseInterface {
        return service('response')
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'status' => false,
                'error' => $error,
                'message' => $message,
                'data' => null,
            ]);
    }
}
