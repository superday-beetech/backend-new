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

    /**
     * Petakan HTTP status ke ApiErrorCode.
     *
     * Digunakan sebagai fallback ketika pemanggil hanya mengetahui
     * HTTP status (mis. dari exception handler) dan tidak menyebut
     * kode domain secara eksplisit.
     *
     * Fallback:
     *  - 5xx → INTERNAL_SERVER_ERROR
     *  - lainnya → DOMAIN_ERROR
     */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            400 => self::BAD_REQUEST,
            401 => self::UNAUTHENTICATED,
            403 => self::FORBIDDEN,
            404 => self::NOT_FOUND,
            405 => self::METHOD_NOT_ALLOWED,
            409 => self::CONFLICT,
            410 => self::GONE,
            413 => self::PAYLOAD_TOO_LARGE,
            415 => self::UNSUPPORTED_MEDIA_TYPE,
            422 => self::VALIDATION_ERROR,
            429 => self::TOO_MANY_REQUESTS,
            502 => self::BAD_GATEWAY,
            503 => self::SERVICE_UNAVAILABLE,
            504 => self::GATEWAY_TIMEOUT,
            default => $status >= 500
                ? self::INTERNAL_SERVER_ERROR
                : self::DOMAIN_ERROR,
        };
    }

    public function typeUri(): string
    {
        $base = rtrim(config('Api')->problemTypeBaseUri, '/');
        return $base . '/' . strtolower($this->value);
    }

    public function defaultTitle(): string
    {
        return match ($this) {
            self::INVALID_ID             => 'Invalid ID',
            self::EMPTY_PAYLOAD          => 'Empty Payload',
            self::BAD_REQUEST            => 'Bad Request',
            self::PAYLOAD_TOO_LARGE      => 'Payload Too Large',
            self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
            self::VALIDATION_ERROR       => 'Validation Failed',
            self::UNAUTHENTICATED        => 'Authentication Required',
            self::TOKEN_EXPIRED          => 'Token Expired',
            self::FORBIDDEN              => 'Forbidden',
            self::NOT_FOUND              => 'Resource Not Found',
            self::METHOD_NOT_ALLOWED     => 'Method Not Allowed',
            self::CONFLICT               => 'Conflict',
            self::GONE                   => 'Resource Gone',
            self::DOMAIN_ERROR           => 'Domain Error',
            self::TOO_MANY_REQUESTS      => 'Too Many Requests',
            self::DATABASE_ERROR         => 'Database Error',
            self::INTERNAL_SERVER_ERROR  => 'Internal Server Error',
            self::BAD_GATEWAY            => 'Bad Gateway',
            self::SERVICE_UNAVAILABLE    => 'Service Unavailable',
            self::GATEWAY_TIMEOUT        => 'Gateway Timeout',
        };
    }
}
