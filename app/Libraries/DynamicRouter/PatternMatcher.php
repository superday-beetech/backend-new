<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use App\Libraries\DynamicRouter\Exceptions\InvalidPolicyException;

/**
 * Matching pattern RBAC dengan dukungan:
 *   - prefix HTTP verb opsional: "GET /foo/*"
 *   - "*"  = satu segmen
 *   - "**" = nol atau lebih segmen
 *
 * Spesifisitas dihitung berbasis token, bukan substr_count,
 * sehingga "**" dihitung sebagai SATU wildcard sesuai semantiknya.
 */
final class PatternMatcher
{
    private const TOKEN_LITERAL = 'literal';
    private const TOKEN_STAR    = 'star';     // *
    private const TOKEN_GLOB    = 'globstar'; // **

    /**
     * @var array<int, array{
     *     tokens: list<array{type: string, value: string}>,
     *     verb: ?string,
     *     raw: string
     * }>
     */
    private array $cache = [];

    /**
     * @return bool True jika $path cocok dengan $pattern untuk $httpVerb.
     */
    public function matches(string $path, string $pattern, string $httpVerb): bool
    {
        $compiled = $this->compile($pattern);

        if ($compiled['verb'] !== null && $compiled['verb'] !== $httpVerb) {
            return false;
        }

        $path = trim($path, '/');

        if ($path === '' && $compiled['tokens'] === []) {
            return true;
        }

        return $this->matchTokens(
            $this->splitPath($path),
            $compiled['tokens'],
        );
    }

    /**
     * Skor spesifisitas: makin kecil makin spesifik.
     *
     * @return array{wildcards: int, literal_length: int}
     */
    public function specificity(string $pattern): array
    {
        $compiled = $this->compile($pattern);

        $wildcards     = 0;
        $literalLength = 0;

        foreach ($compiled['tokens'] as $token) {
            if ($token['type'] === self::TOKEN_LITERAL) {
                $literalLength += strlen($token['value']);
                continue;
            }

            $wildcards++;
        }

        return [
            'wildcards'      => $wildcards,
            'literal_length' => $literalLength,
        ];
    }

    /**
     * @return array{
     *     tokens: list<array{type: string, value: string}>,
     *     verb: ?string,
     *     raw: string
     * }
     */
    private function compile(string $pattern): array
    {
        $key = $pattern;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $raw  = $pattern;
        $verb = null;

        if (preg_match('/^([A-Z]+)\s+(.+)$/i', $pattern, $m)) {
            $verb    = strtoupper($m[1]);
            $pattern = trim($m[2]);
        }

        $pattern = trim($pattern, '/');

        $tokens = [];

        if ($pattern !== '') {
            foreach (explode('/', $pattern) as $segment) {
                if ($segment === '**') {
                    $tokens[] = ['type' => self::TOKEN_GLOB, 'value' => '**'];
                } elseif ($segment === '*') {
                    $tokens[] = ['type' => self::TOKEN_STAR, 'value' => '*'];
                } elseif (str_contains($segment, '*')) {
                    throw new InvalidPolicyException(
                        "Wildcard harus menempati satu segmen penuh: '{$segment}' dalam pola '{$raw}'."
                    );
                } else {
                    $tokens[] = ['type' => self::TOKEN_LITERAL, 'value' => $segment];
                }
            }
        }

        return $this->cache[$key] = [
            'tokens' => $tokens,
            'verb'   => $verb,
            'raw'    => $raw,
        ];
    }

    /** @return list<string> */
    private function splitPath(string $path): array
    {
        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * @param list<string> $pathSegments
     * @param list<array{type: string, value: string}> $tokens
     */
    private function matchTokens(array $pathSegments, array $tokens): bool
    {
        // Dynamic programming: dp[i][j] = apakah tokens[j..] match path[i..]
        $n = count($pathSegments);
        $m = count($tokens);

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, false));
        $dp[$n][$m] = true;

        for ($j = $m - 1; $j >= 0; $j--) {
            if ($tokens[$j]['type'] === self::TOKEN_GLOB) {
                // "**" boleh match nol segmen.
                $dp[$n][$j] = $dp[$n][$j + 1];
            }
        }

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $token = $tokens[$j];

                if ($token['type'] === self::TOKEN_GLOB) {
                    // Match nol segmen (skip token) atau satu segmen (konsumsi).
                    $dp[$i][$j] = $dp[$i][$j + 1] || $dp[$i + 1][$j];
                } elseif ($token['type'] === self::TOKEN_STAR) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1];
                } else {
                    $dp[$i][$j] = $pathSegments[$i] === $token['value']
                        && $dp[$i + 1][$j + 1];
                }
            }
        }

        return $dp[0][0];
    }
}
