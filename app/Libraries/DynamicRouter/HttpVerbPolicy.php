<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use CodeIgniter\HTTP\RequestInterface;
use Throwable;

/**
 * Validasi HTTP verb per endpoint.
 *
 * Prinsip: Route Registry adalah sumber kebenaran.
 * Konfigurasi lokal (allowedHttpVerbs) hanya boleh MEMPERKETAT,
 * tidak boleh MELONGGARKAN kebijakan registry.
 *
 * Urutan pemeriksaan:
 * 1. Exclusion — bypass total (keputusan eksplisit untuk melewati
 *    pemeriksaan tambahan; bukan untuk melewati AccessChecker).
 * 2. Route Registry — SELALU dicek (kecuali di-exclude).
 *    Jika registry error → fail-closed.
 * 3. Konfigurasi lokal — jika ada, harus JUGA mengizinkan (intersection).
 *    Jika tidak ada, registry saja yang menentukan.
 */
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
     */
    public function checkHttpVerb(string $endpoint): bool
    {
        // 1. Exclusion — bypass total.
        if ($this->isHttpVerbExcluded($endpoint)) {
            return true;
        }

        $verb   = strtoupper($this->request->getMethod());
        $module = trim($this->module);

        if ($module === '') {
            log_message(
                'error',
                'HttpVerbPolicy: module kosong saat validasi HTTP verb. Endpoint: {endpoint}',
                ['endpoint' => $endpoint]
            );

            return false;
        }

        // 2. Route Registry — SELALU dicek. Fail-closed pada error.
        $registryAllows = $this->registryAllows($module, $endpoint, $verb);

        if (! $registryAllows) {
            // Registry menolak → tolak. Konfigurasi lokal TIDAK boleh
            // melonggarkan keputusan registry.
            return false;
        }

        // 3. Konfigurasi lokal — jika ada, harus JUGA mengizinkan.
        if (array_key_exists($endpoint, $this->allowedHttpVerbs)) {
            return $this->localAllows($endpoint, $verb);
        }

        // Tidak ada konfigurasi lokal → registry menentukan.
        return true;
    }

    public function isHttpVerbExcluded(string $endpoint): bool
    {
        return in_array(
            $endpoint,
            $this->exclusions,
            true
        );
    }

    /**
     * Cek registry. Fail-closed pada error.
     */
    private function registryAllows(string $module, string $endpoint, string $verb): bool
    {
        try {
            return service('routeRegistryAccess')->allowsHttpVerb(
                $module,
                $endpoint,
                $verb
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                'HttpVerbPolicy: gagal memeriksa HTTP verb di registry. Module: {module}; Endpoint: {endpoint}; Error: {error}',
                [
                    'module'   => $module,
                    'endpoint' => $endpoint,
                    'error'    => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /**
     * Cek konfigurasi lokal. Hanya dipanggil jika registry sudah mengizinkan.
     *
     * Format tidak valid → fail-closed (deny).
     */
    private function localAllows(string $endpoint, string $verb): bool
    {
        $allowed = $this->allowedHttpVerbs[$endpoint];

        if (! is_array($allowed)) {
            log_message(
                'error',
                'HttpVerbPolicy: allowedHttpVerbs[{endpoint}] bukan array. Ditolak.',
                ['endpoint' => $endpoint]
            );

            return false;
        }

        $allowedUpper = [];

        foreach ($allowed as $value) {
            if (! is_string($value)) {
                log_message(
                    'warning',
                    'HttpVerbPolicy: allowedHttpVerbs[{endpoint}] berisi non-string. Entry diabaikan.',
                    ['endpoint' => $endpoint]
                );

                continue;
            }

            $trimmed = strtoupper(trim($value));

            if ($trimmed === '') {
                continue;
            }

            $allowedUpper[] = $trimmed;
        }

        return in_array($verb, $allowedUpper, true);
    }
}
