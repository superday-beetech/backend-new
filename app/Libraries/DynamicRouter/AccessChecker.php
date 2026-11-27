<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use App\Libraries\DynamicRouter\Exceptions\AmbiguousOverrideException;
use App\Libraries\DynamicRouter\Exceptions\InvalidPolicyException;
use CodeIgniter\HTTP\RequestInterface;
use Config\Rbac;
use Throwable;

class AccessChecker
{
    private Rbac $policy;
    private RequestPath $requestPath;
    private PatternMatcher $matcher;
    private GroupMembershipResolver $groupResolver;

    /**
     * Cache user per instance — AuthenticationResolver sudah memoized,
     * tapi kita simpan juga agar tidak memanggil method-nya berulang.
     */
    private bool $userResolved = false;
    private ?object $resolvedUser = null;

    public function __construct(
        private RequestInterface $request,
        private AuthenticationResolver $authenticationResolver,
        private string $module,
        private array $publicMethods = [],
        ?Rbac $policy = null,
        ?PatternMatcher $matcher = null,
        ?GroupMembershipResolver $groupResolver = null,
    ) {
        $this->policy        = $policy ?? config(Rbac::class);
        $this->requestPath   = RequestPath::fromRequest($request);
        $this->matcher       = $matcher ?? new PatternMatcher();
        $this->groupResolver = $groupResolver ?? new DbGroupMembershipResolver();
    }

    /**
     * @return array{allowed: bool, reason: string, message: string, status: int}
     */
    public function diagnoseAccess(string $endpoint): array
    {
        return $this->evaluate($endpoint)->toArray();
    }

    public function checkAccess(string $endpoint): bool
    {
        return $this->evaluate($endpoint)->allowed;
    }

    public function isSuperadmin(): bool
    {
        $userId = $this->resolveUserId();

        if ($userId <= 0) {
            return false;
        }

        try {
            $accessControl = service('accessControl');

            return $accessControl->isActiveUser($userId)
                && $accessControl->isSuperadmin($userId);
        } catch (Throwable $e) {
            log_message('error', 'Gagal memeriksa status superadmin: {error}', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // -----------------------------------------------------------------
    // Pipeline
    // -----------------------------------------------------------------

    private function evaluate(string $endpoint): AccessDecision
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            return $this->deny('invalid_endpoint', 'Endpoint yang diminta tidak valid.', 404, $endpoint);
        }

        $httpVerb = strtoupper($this->request->getMethod());

        // 1. Route Registry.
        $info = $this->inspectRoute($endpoint, $httpVerb);

        if ($info instanceof AccessDecision) {
            return $info;
        }

        // 2. Route flags.
        $flagDecision = $this->validateRouteFlags($info, $endpoint);

        if ($flagDecision !== null) {
            return $flagDecision;
        }

        // 3. HTTP verb.
        if (($info['verb_registered'] ?? false) !== true) {
            return $this->deny(
                'http_verb_not_allowed',
                'Metode akses untuk fitur ini belum dikonfigurasi.',
                405,
                $endpoint
            );
        }

        // 4. Route ID.
        $routeId = (int) ($info['id'] ?? 0);

        if ($routeId <= 0) {
            log_message('error', 'Route ID tidak valid untuk endpoint {endpoint}.', ['endpoint' => $endpoint]);

            return $this->deny(
                'invalid_route_id',
                'Konfigurasi fitur tidak valid. Silakan hubungi administrator.',
                500,
                $endpoint
            );
        }

        // 5. Public endpoint.
        $publicDecision = $this->checkPublic($endpoint, $httpVerb);

        if ($publicDecision !== null) {
            return $publicDecision;
        }

        // 6. Authentication.
        $authDecision = $this->requireAuthenticatedUser($endpoint);

        if ($authDecision !== null) {
            return $authDecision;
        }

        $userId = $this->resolveUserId();

        // 7. Account state + bypass membership.
        $accountResult = $this->validateAccount($userId, $endpoint);

        if ($accountResult instanceof AccessDecision) {
            return $accountResult;
        }

        // 8. Bypass group (verb-limited).
        if ($accountResult === true) {
            return $this->handleBypassGroup($userId, $endpoint, $httpVerb);
        }

        // 9. Permission override.
        $overrideDecision = $this->resolveOverrideDecision($userId, $endpoint, $httpVerb);

        if ($overrideDecision !== null) {
            return $overrideDecision;
        }

        // 10–11. Route permissions.
        return $this->resolveRoutePermission($userId, $routeId, $endpoint, $info);
    }

    // -----------------------------------------------------------------
    // Steps
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|AccessDecision
     */
    private function inspectRoute(string $endpoint, string $httpVerb): array|AccessDecision
    {
        try {
            $registry = service('routeRegistryAccess');
            $info     = $registry->inspectEndpoint($this->module, $endpoint, $httpVerb);
        } catch (Throwable $e) {
            log_message('error', 'Gagal memeriksa route registry. Endpoint: {endpoint}; Error: {error}', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            return $this->deny(
                'route_registry_error',
                'Status fitur tidak dapat diverifikasi. Silakan coba kembali atau hubungi administrator.',
                500,
                $endpoint
            );
        }

        if (($info['found'] ?? false) !== true) {
            return $this->deny(
                'endpoint_not_registered',
                'Fitur ini belum terdaftar di sistem. Silakan hubungi administrator.',
                404,
                $endpoint
            );
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function validateRouteFlags(array $info, string $endpoint): ?AccessDecision
    {
        $flags = [
            'is_discovered' => ['endpoint_not_discovered', 'Endpoint belum terdeteksi oleh sistem. Silakan hubungi administrator.'],
            'is_routable'   => ['route_not_routable', 'Fitur ini belum diaktifkan untuk routing. Silakan hubungi administrator.'],
            'is_allowed'    => ['route_not_allowed', 'Akses ke fitur ini belum diizinkan oleh administrator.'],
        ];

        foreach ($flags as $flag => [$reason, $message]) {
            if (($info[$flag] ?? false) !== true) {
                return $this->deny($reason, $message, 403, $endpoint);
            }
        }

        return null;
    }

    private function checkPublic(string $endpoint, string $httpVerb): ?AccessDecision
    {
        try {
            if ($this->isPolicyPublicEndpoint($endpoint, $httpVerb)) {
                return AccessDecision::allow('public_endpoint');
            }
        } catch (Throwable $e) {
            log_message('error', 'Gagal memeriksa public policy: {error}', ['error' => $e->getMessage()]);

            return $this->deny(
                'public_policy_error',
                'Kebijakan akses tidak dapat diverifikasi.',
                500,
                $endpoint
            );
        }

        return null;
    }

    private function requireAuthenticatedUser(string $endpoint): ?AccessDecision
    {
        if (! $this->isAuthenticated()) {
            return $this->deny(
                'authentication_required',
                'Silakan masuk terlebih dahulu untuk mengakses fitur ini.',
                401,
                $endpoint
            );
        }

        return null;
    }

    /**
     * @return AccessDecision|bool True jika bypass group, false jika bukan,
     *                          AccessDecision jika ada kegagalan.
     */
    private function validateAccount(int $userId, string $endpoint): AccessDecision|bool
    {
        try {
            $accessControl = service('accessControl');

            if (! $accessControl->isActiveUser($userId)) {
                return $this->deny(
                    'user_inactive',
                    'Akun tidak aktif atau tidak dapat digunakan. Silakan hubungi administrator.',
                    403,
                    $endpoint
                );
            }

            return $this->isPolicyBypassGroup($userId);
        } catch (Throwable $e) {
            log_message('error', 'Gagal memvalidasi akun {userId}: {error}', [
                'userId' => $userId,
                'error'  => $e->getMessage(),
            ]);

            return $this->deny(
                'account_validation_error',
                'Status akun tidak dapat diverifikasi. Silakan coba kembali.',
                500,
                $endpoint
            );
        }
    }

    private function handleBypassGroup(int $userId, string $endpoint, string $httpVerb): AccessDecision
    {
        $allowedVerbs = ['GET', 'POST', 'HEAD'];

        if (! in_array($httpVerb, $allowedVerbs, true)) {
            log_message('warning', 'Bypass group ditolak untuk verb {verb}, endpoint {endpoint}, user {userId}.', [
                'verb'     => $httpVerb,
                'endpoint' => $endpoint,
                'userId'   => $userId,
            ]);

            return $this->deny(
                'bypass_http_verb_denied',
                'Metode akses ini tidak diizinkan untuk grup bypass.',
                403,
                $endpoint
            );
        }

        return AccessDecision::allow('bypass_group');
    }

    private function resolveOverrideDecision(int $userId, string $endpoint, string $httpVerb): ?AccessDecision
    {
        try {
            $override = $this->resolvePolicyOverride($httpVerb);
        } catch (AmbiguousOverrideException $e) {
            log_message('error', 'Permission override ambigu: {error}', ['error' => $e->getMessage()]);

            return $this->deny(
                'ambiguous_permission_override',
                'Konfigurasi hak akses ambigu. Silakan hubungi administrator.',
                500,
                $endpoint
            );
        } catch (Throwable $e) {
            log_message('error', 'Permission override tidak valid. Endpoint: {endpoint}; Error: {error}', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            return $this->deny(
                'invalid_permission_override',
                'Konfigurasi hak akses tidak valid. Silakan hubungi administrator.',
                500,
                $endpoint
            );
        }

        if ($override === null) {
            return null;
        }

        if ($override === Rbac::AUTHENTICATED) {
            return AccessDecision::allow('authenticated_access_granted');
        }

        try {
            $accessControl = service('accessControl');
            $allowed       = $accessControl->hasAllPermissions($userId, [$override]);
        } catch (Throwable $e) {
            log_message('error', 'Override permission check gagal. Endpoint: {endpoint}; User: {userId}; Error: {error}', [
                'endpoint' => $endpoint,
                'userId'   => $userId,
                'error'    => $e->getMessage(),
            ]);

            return $this->deny(
                'authorization_error',
                'Izin akses tidak dapat diverifikasi.',
                500,
                $endpoint
            );
        }

        return $allowed
            ? AccessDecision::allow('permission_override_granted')
            : $this->deny(
                'permission_denied',
                'Anda tidak memiliki izin untuk mengakses fitur ini.',
                403,
                $endpoint
            );
    }

    /**
     * @param array<string, mixed> $info
     */
    private function resolveRoutePermission(int $userId, int $routeId, string $endpoint, array $info): AccessDecision
    {
        $permissions = $this->normalizePermissions($info['permissions'] ?? null, $endpoint, $routeId);

        if ($permissions instanceof AccessDecision) {
            return $permissions;
        }

        try {
            $registry = service('routeRegistryAccess');
            $allowed  = $registry->allows($userId, $routeId);
        } catch (Throwable $e) {
            log_message('error', 'Pemeriksaan permission gagal. Endpoint: {endpoint}; User: {userId}; Error: {error}', [
                'endpoint' => $endpoint,
                'userId'   => $userId,
                'error'    => $e->getMessage(),
            ]);

            return $this->deny(
                'authorization_error',
                'Izin akses tidak dapat diverifikasi. Silakan coba kembali atau hubungi administrator.',
                500,
                $endpoint
            );
        }

        return $allowed
            ? AccessDecision::allow('permission_granted')
            : $this->deny(
                'permission_denied',
                'Anda tidak memiliki izin untuk mengakses fitur ini.',
                403,
                $endpoint
            );
    }

    /**
     * @return list<string>|AccessDecision
     */
    private function normalizePermissions(mixed $raw, string $endpoint, int $routeId): array|AccessDecision
    {
        if (! is_array($raw)) {
            log_message('error', 'Format permission route tidak valid untuk endpoint {endpoint}.', ['endpoint' => $endpoint]);

            return $this->deny(
                'invalid_route_permissions',
                'Konfigurasi hak akses fitur tidak valid. Silakan hubungi administrator.',
                500,
                $endpoint
            );
        }

        $normalized = [];

        foreach ($raw as $permission) {
            if (! is_string($permission) || trim($permission) === '') {
                log_message('error', 'Permission route tidak valid. Endpoint: {endpoint}; Route ID: {routeId}.', [
                    'endpoint' => $endpoint,
                    'routeId'  => $routeId,
                ]);

                return $this->deny(
                    'invalid_route_permissions',
                    'Konfigurasi hak akses fitur tidak valid. Silakan hubungi administrator.',
                    500,
                    $endpoint
                );
            }

            $permission = trim($permission);

            // Dedup case-sensitive (permission code dianggap case-sensitive).
            $normalized[$permission] = $permission;
        }

        if ($normalized === []) {
            log_message('error', 'Permission belum dikonfigurasi untuk endpoint {endpoint}, route ID {routeId}.', [
                'endpoint' => $endpoint,
                'routeId'  => $routeId,
            ]);

            return $this->deny(
                'route_permission_not_configured',
                'Hak akses untuk fitur ini belum dikonfigurasi. Silakan hubungi administrator.',
                403,
                $endpoint
            );
        }

        return array_values($normalized);
    }

    // -----------------------------------------------------------------
    // Policy helpers
    // -----------------------------------------------------------------

    private function isPolicyPublicEndpoint(string $endpoint, string $httpVerb): bool
    {
        foreach ($this->policy->publicPatterns as $pattern) {
            if (is_string($pattern) && $this->matcher->matches($this->requestPath->value, $pattern, $httpVerb)) {
                return true;
            }
        }

        // Deprecated: publicMethods berisi NAMA endpoint (bukan path).
        // Dipertahankan untuk kompatibilitas; akan dihapus di versi berikutnya.
        if ($endpoint !== '' && in_array($endpoint, $this->publicMethods, true)) {
            log_message('debug', 'AccessChecker: publicMethods deprecated, gunakan publicPatterns. Endpoint: ' . $endpoint);

            return true;
        }

        return false;
    }

    /**
     * @throws AmbiguousOverrideException
     * @throws InvalidPolicyException
     */
    private function resolvePolicyOverride(string $httpVerb): ?string
    {
        $path = $this->requestPath->value;

        $matched = [];

        foreach ($this->policy->overrides as $pattern => $permission) {
            if (! is_string($pattern) || ! is_string($permission)) {
                throw new InvalidPolicyException('Format permission override tidak valid.');
            }

            $pattern    = trim($pattern);
            $permission = trim($permission);

            if ($pattern === '' || $permission === '') {
                throw new InvalidPolicyException("Permission override kosong: '{$pattern}'");
            }

            if (! $this->matcher->matches($path, $pattern, $httpVerb)) {
                continue;
            }

            $specificity = $this->matcher->specificity($pattern);

            $matched[] = [
                'pattern'        => $pattern,
                'permission'     => $permission,
                'wildcards'      => $specificity['wildcards'],
                'literal_length' => $specificity['literal_length'],
            ];
        }

        if ($matched === []) {
            return null;
        }

        usort($matched, static function (array $a, array $b): int {
            // Wildcard lebih sedikit = lebih spesifik.
            $cmp = $a['wildcards'] <=> $b['wildcards'];

            if ($cmp !== 0) {
                return $cmp;
            }

            // Literal lebih panjang = lebih spesifik.
            return $b['literal_length'] <=> $a['literal_length'];
        });

        $best = $matched[0];

        foreach (array_slice($matched, 1) as $candidate) {
            $sameSpecificity = $candidate['wildcards'] === $best['wildcards']
                && $candidate['literal_length'] === $best['literal_length'];

            if (! $sameSpecificity) {
                break;
            }

            if ($candidate['permission'] !== $best['permission']) {
                throw new AmbiguousOverrideException(
                    "Permission override ambigu untuk path '{$path}': "
                    . "'{$best['pattern']}' ({$best['permission']}) vs "
                    . "'{$candidate['pattern']}' ({$candidate['permission']})."
                );
            }
        }

        return $best['permission'];
    }

    private function isPolicyBypassGroup(int $userId): bool
    {
        /** @var list<string> $groups */
        $groups = array_values(array_filter(
            $this->policy->bypassGroups ?? [],
            static fn ($group): bool => is_string($group) && trim($group) !== ''
        ));

        if ($groups === []) {
            return false;
        }

        return $this->groupResolver->userHasAnyGroup($userId, $groups);
    }

    // -----------------------------------------------------------------
    // User resolution (delegasi ke AuthenticationResolver yang sudah memoized)
    // -----------------------------------------------------------------

    /**
     * @throws Throwable Jika AuthenticationResolver melempar RuntimeException
     *                   akibat programmer error (ArgumentCountError/TypeError).
     *                   Caller harus menangani ini.
     */
    private function resolveAuthenticatedUser(): ?object
    {
        if ($this->userResolved) {
            return $this->resolvedUser;
        }

        $this->userResolved = true;

        try {
            return $this->resolvedUser = $this->authenticationResolver->getAuthenticatedUser();
        } catch (Throwable $e) {
            // AuthenticationResolver hanya melempar untuk programmer error;
            // log dan anggap tidak terautentikasi agar tidak meng-crash request.
            log_message('error', 'AuthenticationResolver melempar saat resolve user: {error}', [
                'error' => $e->getMessage(),
            ]);

            return $this->resolvedUser = null;
        }
    }

    private function resolveUserId(): int
    {
        return (int) ($this->resolveAuthenticatedUser()?->id ?? 0);
    }

    private function isAuthenticated(): bool
    {
        return $this->resolveUserId() > 0;
    }

    // -----------------------------------------------------------------
    // Decision helper + logging
    // -----------------------------------------------------------------

    private function deny(string $reason, string $message, int $status, string $endpoint): AccessDecision
    {
        // Log semua denial untuk audit keamanan.
        // 5xx di level error, 4xx di level info.
        $level = $status >= 500 ? 'error' : 'info';

        log_message($level, 'AccessChecker deny: reason={reason}; status={status}; path={path}; endpoint={endpoint}', [
            'reason'   => $reason,
            'status'   => $status,
            'path'     => $this->requestPath->value,
            'endpoint' => $endpoint,
        ]);

        return AccessDecision::deny($reason, $message, $status);
    }
}
