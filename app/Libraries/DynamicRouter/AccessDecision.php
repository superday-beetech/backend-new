<?php

declare(strict_types=1);

namespace App\Libraries\DynamicRouter;

/**
 * Hasil diagnosis akses. Immutable value object.
 *
 * Reason code bersifat stabil (jangan diubah tanpa versi mayor)
 * karena dipakai oleh pemanggil untuk branching.
 */
final class AccessDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly string $message,
        public readonly int $status,
    ) {
    }

    public static function allow(string $reason, string $message = 'Akses diizinkan.'): self
    {
        return new self(true, $reason, $message, 200);
    }

    public static function deny(string $reason, string $message, int $status): self
    {
        return new self(false, $reason, $message, $status);
    }

    /** @return array{allowed: bool, reason: string, message: string, status: int} */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason'  => $this->reason,
            'message' => $this->message,
            'status'  => $this->status,
        ];
    }
}
