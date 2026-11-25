<?php

declare(strict_types=1);

namespace App\Enum;

enum ApiErrorCode: string
{
    // 400 / 413 / 415 / 422 - Request & Validasi Input
    case INVALID_ID               = 'INVALID_ID';
    case EMPTY_PAYLOAD            = 'EMPTY_PAYLOAD';
    case BAD_REQUEST              = 'BAD_REQUEST';
    case PAYLOAD_TOO_LARGE        = 'PAYLOAD_TOO_LARGE';
    case UNSUPPORTED_MEDIA_TYPE   = 'UNSUPPORTED_MEDIA_TYPE';
    case VALIDATION_ERROR         = 'VALIDATION_ERROR';

    // 401 / 403 - Autentikasi & Otorisasi
    case UNAUTHENTICATED          = 'UNAUTHENTICATED';
    case TOKEN_EXPIRED            = 'TOKEN_EXPIRED';
    case FORBIDDEN                = 'FORBIDDEN';

    // 404 / 405 / 409 / 410 - Resource & Konflik Data
    case NOT_FOUND                = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED       = 'METHOD_NOT_ALLOWED';
    case CONFLICT                 = 'CONFLICT';
    case GONE                     = 'GONE';

    // 422 / 429 - Aturan Bisnis & Rate Limit
    case DOMAIN_ERROR             = 'DOMAIN_ERROR';
    case TOO_MANY_REQUESTS        = 'TOO_MANY_REQUESTS';

    // 500 / 502 / 503 / 504 - Server & Infrastruktur
    case DATABASE_ERROR           = 'DATABASE_ERROR';
    case INTERNAL_SERVER_ERROR    = 'INTERNAL_SERVER_ERROR';
    case BAD_GATEWAY              = 'BAD_GATEWAY';
    case SERVICE_UNAVAILABLE      = 'SERVICE_UNAVAILABLE';
    case GATEWAY_TIMEOUT          = 'GATEWAY_TIMEOUT';

    public function defaultHttpStatus(): int
    {
        return match ($this) {
            self::INVALID_ID,
            self::EMPTY_PAYLOAD,
            self::BAD_REQUEST              => 400,

            self::UNAUTHENTICATED,
            self::TOKEN_EXPIRED            => 401,

            self::FORBIDDEN                => 403,
            self::NOT_FOUND                => 404,
            self::METHOD_NOT_ALLOWED       => 405,
            self::CONFLICT                 => 409,
            self::GONE                     => 410,

            self::PAYLOAD_TOO_LARGE        => 413,
            self::UNSUPPORTED_MEDIA_TYPE   => 415,

            self::VALIDATION_ERROR,
            self::DOMAIN_ERROR             => 422,

            self::TOO_MANY_REQUESTS        => 429,

            self::DATABASE_ERROR,
            self::INTERNAL_SERVER_ERROR    => 500,

            self::BAD_GATEWAY              => 502,
            self::SERVICE_UNAVAILABLE      => 503,
            self::GATEWAY_TIMEOUT          => 504,
        };
    }
}
