<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use CodeIgniter\HTTP\RequestInterface;

class HttpVerbPolicy
{
    public function __construct(
        private RequestInterface $request,
        private array $allowedHttpVerbs = [],
        private array $exclusions = [],
        private string $module = '',
    ) {
    }

    /**
     * Memeriksa HTTP verb endpoint.
     *
     * Urutan:
     * 1. Periksa exclusion.
     * 2. Jika ada konfigurasi lokal, gunakan konfigurasi lokal.
     * 3. Jika tidak ada, gunakan Route Registry berdasarkan module.
     */
    public function checkHttpVerb(string $endpoint): bool
    {
        if ($this->isHttpVerbExcluded($endpoint)) {
            return true;
        }

        /*
         * 1. Konfigurasi lokal.
         */
        if (array_key_exists($endpoint, $this->allowedHttpVerbs)) {
            $allowed = $this->allowedHttpVerbs[$endpoint];

            if (! is_array($allowed)) {
                return false;
            }

            $verb = strtoupper($this->request->getMethod());

            $allowedUpper = array_map(
                static fn ($value): string => is_string($value)
                    ? strtoupper(trim($value))
                    : '',
                $allowed
            );

            return in_array($verb, $allowedUpper, true);
        }

        /*
         * 2. Fallback Route Registry.
         */
        $module = trim($this->module);
        $verb = strtoupper($this->request->getMethod());

        if ($module === '') {
            log_message(
                'error',
                'HttpVerbPolicy: module kosong saat validasi HTTP verb.'
            );

            return false;
        }

        try {
            return service('routeRegistryAccess')->allowsHttpVerb(
                $module,
                $endpoint,
                $verb
            );
        } catch (\Throwable $e) {
            log_message(
                'error',
                'HttpVerbPolicy: gagal memeriksa HTTP verb. Module: {module}; Endpoint: {endpoint}; Error: {error}',
                [
                    'module' => $module,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    public function isHttpVerbExcluded(string $endpoint): bool
    {
        return in_array(
            $endpoint,
            $this->exclusions,
            true
        );
    }
}
