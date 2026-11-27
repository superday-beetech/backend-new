<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class RouteRegistryAccessService
{
    protected BaseConnection $db;
    protected AccessControlService $accessControl;

    /**
     * Cache route untuk findRoute():
     * module|endpoint|http_verb
     *
     * Cache hanya hidup selama instance service hidup.
     *
     * @var array<string, array|null>
     */
    private array $routeCache = [];

    /**
     * Cache info route untuk inspectEndpoint():
     * module|endpoint
     *
     * Nilai null berarti route tidak ditemukan (cache negatif).
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $routeInfoCache = [];

    /**
     * Cache set verb terdaftar per route ID.
     *
     * @var array<int, array<string, true>>
     */
    private array $verbCache = [];

    /**
     * Cache permission berdasarkan route ID.
     *
     * @var array<int, array>
     */
    private array $routePermissionsCache = [];

    public function __construct(
        ?BaseConnection $db = null,
        ?AccessControlService $accessControl = null
    ) {
        $this->db = $db ?? Database::connect();

        $this->accessControl = $accessControl
            ?? new AccessControlService($this->db);
    }

    // ============================================================
    // Cache keys
    // ============================================================

    private function routeCacheKey(
        string $module,
        string $endpoint,
        string $httpVerb
    ): string {
        return $module . '|' . $endpoint . '|' . $httpVerb;
    }

    private function routeInfoCacheKey(
        string $module,
        string $endpoint
    ): string {
        return $module . '|' . $endpoint;
    }

    /**
 * Membersihkan cache.
 *
 * - endpoint null, routeId null, module null → full clear.
 * - endpoint null, module != null → clear routeCache & routeInfoCache
 *   untuk module tsb; verbCache di-clear seluruhnya (karena di-key
 *   oleh routeId, tidak bisa per module tanpa query).
 * - endpoint != null, module != null → clear spesifik.
 * - routeId != null → clear permissions & verb untuk route tsb.
 */
    public function clearCache(
        ?string $endpoint = null,
        ?int $routeId = null,
        ?string $module = null
    ): void {
        if ($endpoint === null) {
            $this->routeCache = [];
            $this->routeInfoCache = [];
        } else {
            $endpoint = trim($endpoint);

            if ($module !== null) {
                $module = trim($module);

                $prefix = $module . '|' . $endpoint . '|';
                foreach (array_keys($this->routeCache) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->routeCache[$key]);
                    }
                }

                unset($this->routeInfoCache[$module . '|' . $endpoint]);
            } else {
                foreach (array_keys($this->routeCache) as $key) {
                    $parts = explode('|', $key, 3);
                    if (count($parts) === 3 && $parts[1] === $endpoint) {
                        unset($this->routeCache[$key]);
                    }
                }

                foreach (array_keys($this->routeInfoCache) as $key) {
                    $parts = explode('|', $key, 2);
                    if (count($parts) === 2 && $parts[1] === $endpoint) {
                        unset($this->routeInfoCache[$key]);
                    }
                }
            }
        }

        if ($routeId !== null) {
            unset(
                $this->routePermissionsCache[$routeId],
                $this->verbCache[$routeId]
            );
        } else {
            $this->routePermissionsCache = [];

            // verbCache di-key oleh routeId. Tidak ada cara membersihkan
            // per module/endpoint tanpa query. Saat endpoint === null,
            // clear semua untuk mencegah data stale.
            if ($endpoint === null) {
                $this->verbCache = [];
            }
        }
    }

    // ============================================================
    // findRoute (sudah ada cache)
    // ============================================================

    private function findRoute(
        string $module,
        string $endpoint,
        string $httpVerb
    ): ?array {
        $module = trim($module);
        $endpoint = trim($endpoint);
        $httpVerb = strtoupper(trim($httpVerb));

        if ($module === '' || $endpoint === '' || $httpVerb === '') {
            return null;
        }

        $key = $this->routeCacheKey($module, $endpoint, $httpVerb);

        if (array_key_exists($key, $this->routeCache)) {
            return $this->routeCache[$key];
        }

        $route = $this->db
            ->table('route_registry AS rr')
            ->select(['rr.id', 'rr.module', 'rr.endpoint'])
            ->join(
                'route_registry_verbs AS rrv',
                'rrv.route_id = rr.id',
                'inner'
            )
            ->where('rr.module', $module)
            ->where('rr.endpoint', $endpoint)
            ->where('rrv.http_verb', $httpVerb)
            ->where('rr.is_discovered', 1)
            ->where('rr.is_routable', 1)
            ->where('rr.is_allowed', 1)
            ->get()
            ->getRowArray();

        return $this->routeCache[$key] = $route ?: null;
    }

    public function allowsHttpVerb(
        string $module,
        string $endpoint,
        string $httpVerb
    ): bool {
        return $this->findRoute(
            $module,
            $endpoint,
            $httpVerb
        ) !== null;
    }

    public function findAllowedRoute(
        string $module,
        string $endpoint,
        string $httpVerb
    ): ?array {
        return $this->findRoute(
            $module,
            $endpoint,
            $httpVerb
        );
    }

    // ============================================================
    // getRoutePermissions (sudah ada cache)
    // ============================================================

    private function getRoutePermissions(int $routeId): array
    {
        if ($routeId <= 0) {
            return [];
        }

        if (array_key_exists($routeId, $this->routePermissionsCache)) {
            return $this->routePermissionsCache[$routeId];
        }

        $rows = $this->db
            ->table('route_registry_permissions')
            ->select('permission')
            ->where('route_id', $routeId)
            ->get()
            ->getResultArray();

        $permissions = [];

        foreach ($rows as $row) {
            $permission = trim((string) ($row['permission'] ?? ''));

            if ($permission === '') {
                log_message(
                    'error',
                    'Permission route tidak valid: route_id={routeId}',
                    ['routeId' => $routeId]
                );

                return $this->routePermissionsCache[$routeId] = [];
            }

            $permissions[$permission] = $permission;
        }

        $permissions = array_values($permissions);

        if ($permissions === []) {
            log_message(
                'error',
                'Permission route belum dikonfigurasi: route_id={routeId}',
                ['routeId' => $routeId]
            );
        }

        return $this->routePermissionsCache[$routeId] = $permissions;
    }

    // ============================================================
    // allows (sudah ada)
    // ============================================================

    public function allows(int $userId, int $routeId): bool
    {
        if (
            $userId <= 0
            || $routeId <= 0
            || ! $this->accessControl->isActiveUser($userId)
        ) {
            return false;
        }

        $permissions = $this->getRoutePermissions($routeId);

        if ($permissions === []) {
            return false;
        }

        return $this->accessControl->hasAllPermissions(
            $userId,
            $permissions
        );
    }

    // ============================================================
    // inspectEndpoint (REFACTORED: dengan cache)
    // ============================================================

    /**
     * Mengambil informasi route berdasarkan module dan endpoint.
     *
     * Hasil di-cache per (module, endpoint) — tidak termasuk verb,
     * karena info route sama untuk semua verb. `verb_registered`
     * dihitung dari cache verb terpisah per route ID.
     *
     * Permission menggunakan getRoutePermissions() agar
     * inspectEndpoint() dan allows() berbagi cache yang sama.
     *
     * @return array<string, mixed>
     */
    public function inspectEndpoint(
        string $module,
        string $endpoint,
        string $httpVerb
    ): array {
        $module = trim($module);
        $endpoint = trim($endpoint);
        $httpVerb = strtoupper(trim($httpVerb));

        if ($module === '' || $endpoint === '' || $httpVerb === '') {
            return [
                'found' => false,
                'module' => $module,
                'endpoint' => $endpoint,
                'http_verb' => $httpVerb,
                'message' => 'Identitas endpoint tidak valid.',
            ];
        }

        // 1. Ambil info route dari cache (atau query jika miss).
        $routeInfo = $this->getRouteInfo($module, $endpoint);

        if ($routeInfo === null) {
            return [
                'found' => false,
                'module' => $module,
                'endpoint' => $endpoint,
                'http_verb' => $httpVerb,
                'message' => 'Endpoint belum terdaftar.',
            ];
        }

        $routeId = (int) $routeInfo['id'];

        // 2. Cek verb terdaftar dari cache.
        $verbs = $this->getRegisteredVerbs($routeId);
        $verbRegistered = isset($verbs[$httpVerb]);

        // 3. Permission dari cache.
        $permissions = $this->getRoutePermissions($routeId);

        return [
            'found' => true,
            'id' => $routeId,
            'module' => $routeInfo['module'],
            'endpoint' => $routeInfo['endpoint'],
            'is_discovered' => (bool) $routeInfo['is_discovered'],
            'is_routable' => (bool) $routeInfo['is_routable'],
            'is_allowed' => (bool) $routeInfo['is_allowed'],
            'verb_registered' => $verbRegistered,
            'permissions' => $permissions,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRouteInfo(string $module, string $endpoint): ?array
    {
        $key = $this->routeInfoCacheKey($module, $endpoint);

        if (array_key_exists($key, $this->routeInfoCache)) {
            return $this->routeInfoCache[$key];
        }

        $route = $this->db
            ->table('route_registry')
            ->select([
                'id',
                'module',
                'endpoint',
                'is_discovered',
                'is_routable',
                'is_allowed',
            ])
            ->where('module', $module)
            ->where('endpoint', $endpoint)
            ->get()
            ->getRowArray();

        return $this->routeInfoCache[$key] = $route ?: null;
    }

    /**
     * @return array<string, true> Set verb (uppercase) untuk route.
     */
    private function getRegisteredVerbs(int $routeId): array
    {
        if (array_key_exists($routeId, $this->verbCache)) {
            return $this->verbCache[$routeId];
        }

        $rows = $this->db
            ->table('route_registry_verbs')
            ->select('http_verb')
            ->where('route_id', $routeId)
            ->get()
            ->getResultArray();

        $verbs = [];

        foreach ($rows as $row) {
            $verb = strtoupper(trim((string) ($row['http_verb'] ?? '')));

            if ($verb !== '') {
                $verbs[$verb] = true;
            }
        }

        return $this->verbCache[$routeId] = $verbs;
    }
}
