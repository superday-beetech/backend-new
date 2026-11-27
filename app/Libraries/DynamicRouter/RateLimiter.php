<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\HTTP\RequestInterface;
use Throwable;

/**
 * Rate limit fixed-window per (modul, endpoint, identitas).
 *
 * - Counter dinaikkan lewat increment() cache backend (atomik di Redis/Memcached;
 *   best-effort di file cache).
 * - Window dihitung dari bucket waktu, sehingga tidak bergeser pada setiap hit.
 * - Kunci cache di-hash agar tidak bisa bertabrakan akibat karakter yang diganti.
 * - Saat cache backend gagal:
 *     - Endpoint dengan fail_closed=true (default untuk auth) -> DITOLAK.
 *     - Endpoint lain -> DIIZINKAN (fail-open) agar gangguan cache tidak melumpuhkan
 *       seluruh aplikasi.
 * - AuthenticationResolver::getAuthenticatedUser() dapat melempar RuntimeException
 *   pada programmer error. Rate limiter TIDAK boleh crash karena hal ini.
 */
class RateLimiter
{
    /**
     * Endpoint yang WAJIB fail-closed saat cache gagal, meskipun
     * RouterConfig tidak men-set fail_closed secara eksplisit.
     * Ini adalah safety net untuk endpoint yang paling sensitif.
     *
     * @var list<string>
     */
    private const CRITICAL_ENDPOINTS = [
        'Auth::loginAction',
        'Auth::login',
        'Auth::registerAction',
        'Auth::register',
        'Auth::forgotPasswordAction',
        'Auth::resetPasswordAction',
        'Auth::magicLinkAction',
    ];

    public function __construct(
        private RequestInterface $request,
        private string $moduleFolder,
        private array $rateLimits = [],
        private ?AuthenticationResolver $authenticationResolver = null,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function checkRateLimit(string $endpoint): bool
    {
        $policy = $this->rateLimits[$endpoint] ?? null;

        if ($policy === null) {
            return true;
        }

        $limit   = (int) ($policy['limit'] ?? 10);
        $minutes = (int) ($policy['minutes'] ?? 1);

        if ($limit < 1 || $minutes < 1) {
            // Konfigurasi tidak valid: fail-closed agar tidak ada
            // endpoint tanpa proteksi rate limit karena salah config.
            log_message(
                'warning',
                'RateLimiter: konfigurasi tidak valid untuk endpoint {endpoint} (limit={limit}, minutes={minutes}).',
                [
                    'endpoint' => $endpoint,
                    'limit'    => $limit,
                    'minutes'  => $minutes,
                ]
            );

            return false;
        }

        $identity = $this->resolveIdentity();

        $window = $minutes * 60;
        $bucket = intdiv(time(), $window);
        $key    = 'rl_' . hash('sha256', "{$this->moduleFolder}|{$endpoint}|{$identity}|{$bucket}");

        try {
            return $this->hit($key, $window) <= $limit;
        } catch (Throwable $e) {
            $failClosed = $this->shouldFailClosed($endpoint, $policy);

            log_message(
                $failClosed ? 'error' : 'critical',
                sprintf(
                    'RateLimiter cache gagal (%s) endpoint=%s: %s',
                    $failClosed ? 'fail-closed' : 'fail-open',
                    $endpoint,
                    $e->getMessage()
                )
            );

            // fail-closed -> tolak (false); fail-open -> izinkan (true)
            return ! $failClosed;
        }
    }

    public function safeCacheKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) ?? '';
    }

    /**
     * Resolusi identitas rate limit.
     *
     * AuthenticationResolver::getAuthenticatedUser() dapat melempar
     * RuntimeException pada programmer error (ArgumentCountError/TypeError).
     * Rate limiter TIDAK boleh crash karena hal ini — fallback ke identitas
     * IP, dan log error agar tetap terdeteksi.
     */
    private function resolveIdentity(): string
    {
        $user = null;

        if ($this->authenticationResolver !== null) {
            try {
                $user = $this->authenticationResolver->getAuthenticatedUser();
            } catch (Throwable $e) {
                // Programmer error — jangan crash request hanya karena
                // rate limiter. Fallback ke IP + log.
                log_message(
                    'error',
                    'RateLimiter: AuthenticationResolver melempar saat resolve user: {error}',
                    ['error' => $e->getMessage()]
                );

                $user = null;
            }
        }

        if ($user !== null && isset($user->id) && (int) $user->id > 0) {
            return 'user:' . (int) $user->id;
        }

        return 'ip:' . ($this->request->getIPAddress() ?: 'unknown');
    }

    /**
     * Tentukan apakah endpoint harus fail-closed saat cache error.
     *
     * Prioritas:
     * 1. Explicit config: RouterConfig['rateLimits'][endpoint]['fail_closed'].
     * 2. Safety net: endpoint di CRITICAL_ENDPOINTS.
     * 3. Default: fail-open (false).
     */
    private function shouldFailClosed(string $endpoint, array $policy): bool
    {
        if (array_key_exists('fail_closed', $policy)) {
            return (bool) $policy['fail_closed'];
        }

        return in_array($endpoint, self::CRITICAL_ENDPOINTS, true);
    }

    private function hit(string $key, int $ttl): int
    {
        $cache = $this->cache ?? cache();

        $value = $cache->increment($key);

        if (is_int($value)) {
            return $value;
        }

        // Bukan "not found" — ini kegagalan backend cache yang tidak
        // mendukung increment atomik. Gunakan RuntimeException agar
        // semantiknya benar dan tidak tertukar dengan DomainException
        // untuk resource yang tidak ditemukan.
        throw new \RuntimeException(
            'Cache backend tidak menyediakan increment atomik.'
        );
    }
}
