<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use App\Enum\ApiErrorCode;
use RuntimeException;
use Throwable;

class DomainException extends RuntimeException
{
    private readonly ApiErrorCode|string $errorCode;

    public function __construct(
        string $message,
        private readonly int $statusCode = 422,
        private readonly array $errors = [],
        ApiErrorCode|string|null $errorCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException(
                'DomainException harus menggunakan HTTP status 400–599.'
            );
        }

        $this->errorCode = $errorCode
            ?? self::inferErrorCode($statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorCode(): ApiErrorCode|string
    {
        return $this->errorCode;
    }

    public static function notFound(
        string $message,
        array $errors = [],
        ApiErrorCode|string $errorCode = ApiErrorCode::NOT_FOUND
    ): self {
        return new self($message, 404, $errors, $errorCode);
    }

    public static function conflict(
        string $message,
        array $errors = [],
        ApiErrorCode|string $errorCode = ApiErrorCode::CONFLICT
    ): self {
        return new self($message, 409, $errors, $errorCode);
    }

    public static function gone(
        string $message,
        array $errors = [],
        ApiErrorCode|string $errorCode = ApiErrorCode::GONE
    ): self {
        return new self($message, 410, $errors, $errorCode);
    }

    public static function validation(
        string $message,
        array $errors = [],
        ApiErrorCode|string $errorCode = ApiErrorCode::VALIDATION_ERROR
    ): self {
        return new self(
            $message,
            422,
            $errors !== [] ? $errors : [$message],
            $errorCode
        );
    }

    public static function badRequest(
        string $message,
        array $errors = [],
        ApiErrorCode|string $errorCode = ApiErrorCode::BAD_REQUEST
    ): self {
        return new self($message, 400, $errors, $errorCode);
    }

    public static function unauthorized(
        string $message = 'User tidak terautentikasi.',
        ApiErrorCode|string $errorCode = ApiErrorCode::UNAUTHENTICATED
    ): self {
        return new self($message, 401, [], $errorCode);
    }

    public static function forbidden(
        string $message = 'Anda tidak memiliki izin untuk melakukan tindakan ini.',
        ApiErrorCode|string $errorCode = ApiErrorCode::FORBIDDEN
    ): self {
        return new self($message, 403, [], $errorCode);
    }

    public static function tooManyRequests(
        string $message = 'Terlalu banyak permintaan.',
        ApiErrorCode|string $errorCode = ApiErrorCode::TOO_MANY_REQUESTS
    ): self {
        return new self($message, 429, [], $errorCode);
    }

    public static function serviceUnavailable(
        string $message = 'Layanan sementara tidak tersedia.',
        ApiErrorCode|string $errorCode = ApiErrorCode::SERVICE_UNAVAILABLE
    ): self {
        return new self($message, 503, [], $errorCode);
    }

    private static function inferErrorCode(int $statusCode): ApiErrorCode
    {
        return match ($statusCode) {
            400 => ApiErrorCode::BAD_REQUEST,
            401 => ApiErrorCode::UNAUTHENTICATED,
            403 => ApiErrorCode::FORBIDDEN,
            404 => ApiErrorCode::NOT_FOUND,
            409 => ApiErrorCode::CONFLICT,
            410 => ApiErrorCode::GONE,
            422 => ApiErrorCode::VALIDATION_ERROR,
            429 => ApiErrorCode::TOO_MANY_REQUESTS,
            503 => ApiErrorCode::SERVICE_UNAVAILABLE,
            default => $statusCode >= 500
                ? ApiErrorCode::INTERNAL_SERVER_ERROR
                : ApiErrorCode::DOMAIN_ERROR,
        };
    }
}
