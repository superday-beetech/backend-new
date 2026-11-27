<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Path URL ternormalisasi (tanpa leading/trailing slash).
 * Satu-satunya domain yang dipakai untuk pattern matching.
 */
final class RequestPath
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromRequest(RequestInterface $request): self
    {
        return new self(trim($request->getUri()->getPath(), '/'));
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }
}
