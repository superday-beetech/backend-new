<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Shield\Entities\User;
use Config\AuthPolicy;
use RuntimeException;

class AuthenticationResolver
{
    private const ACCESS_COOKIE = 'acn_access_token';

    private ?User $resolvedUser = null;
    private bool $userResolved = false;

    private AuthPolicy $policy;

    public function __construct(
        private RequestInterface $request,
        ?AuthPolicy $policy = null,
    ) {
        $this->policy = $policy ?? config(AuthPolicy::class);

        $this->assertPolicySanity();
    }

    private function assertPolicySanity(): void
    {
        $api = array_map('strtolower', (array) $this->policy->apiAuthenticators);
        $web = array_map('strtolower', (array) $this->policy->webAuthenticators);

        if ($api === []) {
            throw new RuntimeException('AuthPolicy::$apiAuthenticators kosong — API tidak dapat diautentikasi.');
        }
        if ($web === []) {
            throw new RuntimeException('AuthPolicy::$webAuthenticators kosong — web tidak dapat diautentikasi.');
        }

        $known = ['session', 'tokens', 'bearer', 'cookie'];
        foreach (array_merge($api, $web) as $a) {
            if (! in_array($a, $known, true)) {
                throw new RuntimeException("AuthPolicy: authenticator tidak dikenal '{$a}'.");
            }
        }

        if (in_array('session', $api, true)) {
            log_message('warning', 'AuthPolicy: session dipakai untuk API — pastikan ini disengaja.');
        }
    }

    public function isApiRequest(): bool
    {
        $path = trim($this->request->getUri()->getPath(), '/');
        $prefix = trim((string) $this->policy->apiPrefix, '/');
        if ($prefix !== '' && preg_match('#(^|/)\.\.(/|$)#', $prefix)) {
            throw new RuntimeException('AuthPolicy::$apiPrefix mengandung path traversal.');
        }

        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            return true;
        }

        // Endpoint non-prefix yang tetap harus JSON (webhook, health, oauth)
        foreach ($this->policy->apiNonPrefixedPaths ?? [] as $exception) {
            $exception = trim($exception, '/');
            if ($exception !== '' && ($path === $exception || str_starts_with($path, $exception . '/'))) {
                return true;
            }
        }

        return false;
    }

    public function isAuthenticated(): bool
    {
        return $this->getAuthenticatedUser() !== null;
    }

    public function getAuthenticatedUser(): ?User
    {
        if ($this->userResolved) {
            return $this->resolvedUser;
        }
        $this->userResolved = true;

        try {
            return $this->resolvedUser = $this->isApiRequest()
                ? $this->resolveApiUser()
                : $this->resolveWebUser();
        } catch (\ArgumentCountError | \TypeError $e) {
            throw new RuntimeException(
                'AuthenticationResolver: kesalahan programmer saat resolve user: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (\Throwable $e) {
            log_message('error', 'AuthenticationResolver: gagal resolve user — ' . $e->getMessage());
            return $this->resolvedUser = null;
        }
    }

    private function resolveApiUser(): ?User
    {
        foreach ($this->policy->apiAuthenticators as $authenticator) {
            $user = match (strtolower((string) $authenticator)) {
                'session' => $this->getSessionUser(),
                'tokens', 'bearer' => $this->authenticateToken($this->getBearerToken()),
                default => null,
            };

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    private function resolveWebUser(): ?User
    {
        foreach ($this->policy->webAuthenticators as $authenticator) {
            $user = match (strtolower($authenticator)) {
                'session' => $this->getSessionUser(),
                'cookie'  => $this->authenticateToken(
                    $this->getCookieValue(self::ACCESS_COOKIE)
                ),
                default => null,
            };

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    public function getSessionUser(): ?User
    {
        try {
            $authenticator = auth('session');
            if (! $authenticator->loggedIn()) {
                return null;
            }
            $user = $authenticator->user();
            return $user instanceof User ? $user : null;
        } catch (\Throwable $e) {
            log_message('error', 'AuthenticationResolver: gagal resolve session user — ' . $e->getMessage());
            return null;
        }
    }

    private function authenticateToken(string $rawToken): ?User
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return null;
        }

        try {
            // API resmi Shield: verifikasi token mentah.
            $result = service('tokens')->check($rawToken);

            if (! $result->isOK()) {
                return null;
            }

            $user = $result->extraInfo();

            return $user instanceof User ? $user : null;
        } catch (\Throwable $e) {
            log_message('error', 'AuthenticationResolver: token check gagal — ' . $e->getMessage());
            return null;
        }
    }

    private function getBearerToken(): string
    {
        $header = trim(
            $this->request->getHeaderLine('Authorization')
        );

        if (
            $header === ''
            || ! preg_match('/^Bearer\s+(.+)$/i', $header, $matches)
        ) {
            return '';
        }

        return trim($matches[1]);
    }

    private function getCookieValue(string $name): string
    {
        if (! $this->request instanceof IncomingRequest) {
            return '';
        }

        $value = $this->request->getCookie($name);

        return is_string($value) ? trim($value) : '';
    }
}
