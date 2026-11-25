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
     * Cache route:
     * module|endpoint|http_verb
     *
     * Cache hanya hidup selama instance service hidup.
     *
     * @var array<string, array|null>
     */
    private array $routeCache = [];

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

    /**
     * Membuat cache key yang terisolasi berdasarkan module.
     */
    private function routeCacheKey(
        string $module,
        string $endpoint,
        string $httpVerb
    ): string {
        return $module . '|' . $endpoint . '|' . $httpVerb;
    }

    /**
     * Membersihkan cache.
     *
     * Jika module diberikan, hanya cache module tersebut yang dibersihkan.
     * Jika module tidak diberikan, semua module untuk endpoint tersebut
     * akan dibersihkan.
     *
     * Jika endpoint null, seluruh route cache dibersihkan.
     *
     * Jika routeId diberikan, hanya permission cache route tersebut
     * yang dibersihkan.
     */
    public function clearCache(
        ?string $endpoint = null,
        ?int $routeId = null,
        ?string $module = null
    ): void {
        if ($endpoint === null) {
            $this->routeCache = [];
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
            } else {
                foreach (array_keys($this->routeCache) as $key) {
                    $parts = explode('|', $key, 3);

                    if (
                        count($parts) === 3
                        && $parts[1] === $endpoint
                    ) {
                        unset($this->routeCache[$key]);
                    }
                }
            }
        }

        if ($routeId !== null) {
            unset($this->routePermissionsCache[$routeId]);
        } else {
            $this->routePermissionsCache = [];
        }
    }

    /**
     * Mengambil route aktif berdasarkan module, endpoint,
     * dan HTTP verb.
     *
     * Hasil null juga di-cache agar pencarian yang gagal
     * tidak berulang selama instance service hidup.
     */
    private function findRoute(
        string $module,
        string $endpoint,
        string $httpVerb
    ): ?array {
        $module = trim($module);
        $endpoint = trim($endpoint);
        $httpVerb = strtoupper(trim($httpVerb));

        if (
            $module === ''
            || $endpoint === ''
            || $httpVerb === ''
        ) {
            return null;
        }

        $key = $this->routeCacheKey(
            $module,
            $endpoint,
            $httpVerb
        );

        if (array_key_exists($key, $this->routeCache)) {
            return $this->routeCache[$key];
        }

        $route = $this->db
            ->table('route_registry AS rr')
            ->select([
                'rr.id',
                'rr.module',
                'rr.endpoint',
            ])
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

    /**
     * Memeriksa apakah HTTP verb diizinkan untuk module
     * dan endpoint tertentu.
     */
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

    /**
     * Mengambil route yang aktif dan diizinkan untuk verb tertentu.
     */
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

    /**
     * Mengambil permission route satu kali, lalu menyimpan hasilnya
     * dalam cache berdasarkan route ID.
     *
     * Nilai permission adalah nama permission, bukan ID.
     */
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
            $permission = trim(
                (string) ($row['permission'] ?? '')
            );

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

    /**
     * Semua permission route harus dipenuhi.
     *
     * Route tanpa permission ditolak untuk pengguna biasa.
     */
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

    /**
     * Mengambil informasi route berdasarkan module dan endpoint.
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

        if (
            $module === ''
            || $endpoint === ''
            || $httpVerb === ''
        ) {
            return [
                'found' => false,
                'module' => $module,
                'endpoint' => $endpoint,
                'http_verb' => $httpVerb,
                'message' => 'Identitas endpoint tidak valid.',
            ];
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

        if ($route === null) {
            return [
                'found' => false,
                'module' => $module,
                'endpoint' => $endpoint,
                'http_verb' => $httpVerb,
                'message' => 'Endpoint belum terdaftar.',
            ];
        }

        $routeId = (int) $route['id'];

        $verbRegistered = $this->db
            ->table('route_registry_verbs')
            ->where('route_id', $routeId)
            ->where('http_verb', $httpVerb)
            ->countAllResults() > 0;

        return [
            'found' => true,
            'id' => $routeId,
            'module' => $route['module'],
            'endpoint' => $route['endpoint'],
            'is_discovered' => (bool) $route['is_discovered'],
            'is_routable' => (bool) $route['is_routable'],
            'is_allowed' => (bool) $route['is_allowed'],
            'verb_registered' => $verbRegistered,
            'permissions' => $this->getRoutePermissions($routeId),
        ];
    }
}
