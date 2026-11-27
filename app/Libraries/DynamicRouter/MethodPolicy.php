<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use Throwable;

/**
 * Validasi method berdasarkan hasil discovery ControllerScanner,
 * static allowlist, dan Route Registry.
 *
 * Prinsip:
 * - Konflik nama method case-insensitive → fail-closed PERMANEN
 *   untuk controller tersebut (semua method controller ditolak).
 * - Tanpa HTTP verb, registry TIDAK dicek (dokumentasi eksplisit);
 *   keamanan bergantung pada AccessChecker. Reason code dibuat jelas
 *   agar pemanggil tahu ini bukan "allowed by registry".
 * - Allowlist kosong karena config invalid dibedakan dari
 *   "allowlist tidak dikonfigurasi".
 */
class MethodPolicy
{
    /** @var array<string, array<string, string>> */
    private array $index = [];

    /** @var array<string, true> */
    private array $allowedLookup = [];

    /**
     * Controller yang memiliki konflik nama method case-insensitive.
     * Semua method controller ini ditolak (fail-closed permanen).
     *
     * @var array<string, true>
     */
    private array $conflictedControllers = [];

    /** @var bool Apakah allowedMethods dikonfigurasi (non-empty). */
    private bool $hasAllowedMethods;

    public function __construct(
        private array $discoveredMethods,
        private array $allowedMethods = [],
        private string $module = '',
    ) {
        $this->hasAllowedMethods = $this->allowedMethods !== [];

        $this->buildIndex();
        $this->buildAllowedLookup();
    }

    // ============================================================
    // Build
    // ============================================================

    private function buildIndex(): void
    {
        /** @var array<string, array<string, list<string>>> $raw */
        $raw = [];

        foreach ($this->discoveredMethods as $endpoint) {
            if (! is_string($endpoint)) {
                log_message(
                    'warning',
                    'MethodPolicy: discovered endpoint bukan string.'
                );

                continue;
            }

            $parts = explode('::', $endpoint, 2);

            if (count($parts) !== 2) {
                log_message(
                    'warning',
                    'MethodPolicy: format endpoint tidak valid: {endpoint}',
                    ['endpoint' => $endpoint]
                );

                continue;
            }

            [$controller, $method] = $parts;

            if (
                ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $controller)
                || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $method)
            ) {
                log_message(
                    'warning',
                    'MethodPolicy: nama controller/method tidak valid: {endpoint}',
                    ['endpoint' => $endpoint]
                );

                continue;
            }

            $raw[$controller][strtolower($method)][] = $method;
        }

        foreach ($raw as $controller => $byKey) {
            foreach ($byKey as $key => $names) {
                $names = array_values(array_unique($names));

                if (count($names) === 1) {
                    $this->index[$controller][$key] = $names[0];
                    continue;
                }

                // Lebih dari satu nama untuk key yang sama → konflik.
                // Fail-closed PERMANEN untuk controller ini.
                $this->conflictedControllers[$controller] = true;

                log_message(
                    'critical',
                    'MethodPolicy: konflik nama method case-insensitive pada {controller}: {names}. '
                    . 'Seluruh controller dinonaktifkan.',
                    [
                        'controller' => $controller,
                        'names'      => implode(', ', $names),
                    ]
                );
            }
        }

        // Controller yang berkonflik: buang semua method-nya.
        foreach (array_keys($this->conflictedControllers) as $controller) {
            unset($this->index[$controller]);
        }
    }

    /**
     * Normalisasi allowlist agar pencocokan endpoint tidak sensitif
     * terhadap kapitalisasi nama method.
     *
     * Format yang diterima: Controller::method
     */
    private function buildAllowedLookup(): void
    {
        $this->allowedLookup = [];

        if (! $this->hasAllowedMethods) {
            return;
        }

        foreach ($this->allowedMethods as $endpoint) {
            if (! is_string($endpoint)) {
                log_message(
                    'warning',
                    'MethodPolicy: allowedMethods berisi nilai bukan string.'
                );

                continue;
            }

            $endpoint = trim($endpoint);

            if (! preg_match(
                '/^([A-Za-z][A-Za-z0-9_]*)::([A-Za-z][A-Za-z0-9_]*)$/',
                $endpoint,
                $matches
            )) {
                log_message(
                    'warning',
                    'MethodPolicy: format allowlist tidak valid: {endpoint}',
                    ['endpoint' => $endpoint]
                );

                continue;
            }

            $canonical = strtolower($matches[1] . '::' . $matches[2]);

            $this->allowedLookup[$canonical] = true;
        }

        if ($this->allowedLookup === []) {
            // Semua entry invalid. Fail-closed: setiap method akan ditolak
            // oleh diagnoseMethod(), tapi kita log sekali di sini agar
            // tidak membingungkan saat debugging.
            log_message(
                'critical',
                'MethodPolicy: allowedMethods dikonfigurasi tetapi tidak ada entry valid. '
                . 'Semua method akan ditolak (fail-closed).'
            );
        }
    }

    // ============================================================
    // Normalisasi & resolusi
    // ============================================================

    public function normalizeMethodName(string $method): string
    {
        $method = trim($method);

        if ($method === '') {
            return 'index';
        }

        if (
            ! str_contains($method, '-')
            && ! str_contains($method, '_')
            && ! str_contains($method, ' ')
        ) {
            return $method;
        }

        $parts = preg_split(
            '/[-_\s]+/',
            strtolower($method),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($parts === false || $parts === []) {
            return 'index';
        }

        $first = array_shift($parts);

        return $first . implode(
            '',
            array_map('ucfirst', $parts)
        );
    }

    public function resolveDiscoveredMethod(
        string $controller,
        string $requestedMethod
    ): ?string {
        if (isset($this->conflictedControllers[$controller])) {
            return null;
        }

        if (! isset($this->index[$controller])) {
            return null;
        }

        $key = strtolower(trim($requestedMethod));

        if (isset($this->index[$controller][$key])) {
            return $this->index[$controller][$key];
        }

        $normalized = $this->normalizeMethodName($requestedMethod);

        return $this->index[$controller][strtolower($normalized)] ?? null;
    }

    public function isMethodAllowed(
        string $controller,
        string $method
    ): bool {
        return $this->diagnoseMethod(
            $controller,
            $method
        )['allowed'];
    }

    public function isDiscovered(
        string $controller,
        string $method
    ): bool {
        return $this->resolveDiscoveredMethod(
            $controller,
            $method
        ) !== null;
    }

    // ============================================================
    // Diagnosis
    // ============================================================

    /**
     * Diagnosa method.
     *
     * Alur:
     * 1. Method harus ditemukan oleh scanner.
     *    - Controller dengan konflik case-insensitive → METHOD_CONFLICTED.
     * 2. Jika allowedMethods dikonfigurasi, gunakan static allowlist.
     * 3. Jika HTTP verb TIDAK tersedia:
     *    - Return METHOD_DISCOVERED_ONLY (allowed=true).
     *    - Pemeriksaan Route Registry diserahkan ke AccessChecker.
     *    - Ini adalah desain yang disengaja; AccessChecker WAJIB aktif
     *      di production (lihat isSecurityFeatureEnabled()).
     * 4. Jika HTTP verb tersedia, fallback ke Route Registry.
     *
     * @return array{allowed: bool, reason: string, endpoint?: string}
     */
    public function diagnoseMethod(
        string $controller,
        string $method,
        string $httpVerb = ''
    ): array {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $controller)) {
            return [
                'allowed' => false,
                'reason'  => 'INVALID_CONTROLLER_NAME',
            ];
        }

        // Konflik case-insensitive pada controller → fail-closed.
        if (isset($this->conflictedControllers[$controller])) {
            return [
                'allowed' => false,
                'reason'  => 'METHOD_CONFLICTED',
            ];
        }

        $resolved = $this->resolveDiscoveredMethod(
            $controller,
            $method
        );

        if ($resolved === null) {
            return [
                'allowed' => false,
                'reason'  => 'METHOD_NOT_DISCOVERED',
            ];
        }

        $endpoint = $controller . '::' . $resolved;

        /*
         * 1. Static allowlist.
         */
        if ($this->hasAllowedMethods) {
            if (! isset($this->allowedLookup[strtolower($endpoint)])) {
                return [
                    'allowed'  => false,
                    'reason'   => 'METHOD_NOT_ALLOWLISTED',
                    'endpoint' => $endpoint,
                ];
            }

            return [
                'allowed'  => true,
                'reason'   => 'METHOD_ALLOWED_BY_CONFIG',
                'endpoint' => $endpoint,
            ];
        }

        /*
         * 2. Tanpa HTTP verb:
         * pemeriksaan Route Registry dilakukan oleh AccessChecker.
         *
         * Reason code METHOD_DISCOVERED_ONLY menandakan bahwa method
         * LOLOS discovery, tetapi BELUM divalidasi registry.
         * Pemanggil (DynamicRouterBase) WAJIB memastikan AccessChecker
         * aktif agar ini tidak menjadi fail-open.
         */
        $httpVerb = strtoupper(trim($httpVerb));

        if ($httpVerb === '') {
            return [
                'allowed'  => true,
                'reason'   => 'METHOD_DISCOVERED_ONLY',
                'endpoint' => $endpoint,
            ];
        }

        /*
         * 3. Fallback Route Registry.
         *
         * Module wajib tersedia agar endpoint dari module lain
         * tidak ikut dianggap valid.
         */
        $module = trim($this->module);

        if ($module === '') {
            log_message(
                'error',
                'MethodPolicy: module kosong saat validasi Route Registry.'
            );

            return [
                'allowed'  => false,
                'reason'   => 'MODULE_CONTEXT_MISSING',
                'endpoint' => $endpoint,
            ];
        }

        try {
            $registry = service('routeRegistryAccess');

            $route = $registry->findAllowedRoute(
                $module,
                $endpoint,
                $httpVerb
            );

            if ($route === null) {
                return [
                    'allowed'  => false,
                    'reason'   => 'ENDPOINT_NOT_REGISTERED',
                    'endpoint' => $endpoint,
                ];
            }

            return [
                'allowed'  => true,
                'reason'   => 'METHOD_ALLOWED_BY_DATABASE',
                'endpoint' => $endpoint,
            ];
        } catch (Throwable $e) {
            log_message(
                'error',
                'MethodPolicy: gagal cek database — ' . $e->getMessage()
            );

            return [
                'allowed'  => false,
                'reason'   => 'REGISTRY_ERROR',
                'endpoint' => $endpoint,
            ];
        }
    }
}
