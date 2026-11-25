<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Kebijakan otorisasi/RBAC.
 * Konfigurasi autentikasi (session/token) hanya berada di AuthPolicy.
 */
class Rbac extends BaseConfig
{
    public const AUTHENTICATED = '@authenticated';

    /** Grup yang melewati grant permission setelah status akun divalidasi. */
    public array $bypassGroups = ['superadmin'];

    /** Endpoint publik yang tidak membutuhkan autentikasi maupun permission. */
    public array $publicPatterns = [
        'acn',
        'acn/**',
        'POST api/v1/auth/login',
        'login',
        'login/*',
        'logout',
        'auth/*',
        'health',
        'webhook/**',
    ];

    /**
      * Pemetaan path -> permission untuk route non-standar.
      *
      * Navigation dan Settings tidak memiliki segmen resource
      * yang cukup untuk permission API otomatis.
      */
    public array $overrides = [
        // Backend Navigation
        'GET api/v1/navigation'  => 'backend.navigation.view',
        'HEAD api/v1/navigation' => 'backend.navigation.view',

        // Backend Settings
        'GET api/v1/settings'  => 'backend.settings.view',
        'HEAD api/v1/settings' => 'backend.settings.view',
    ];

    public int $maxPathLength = 2048;
    public int $maxSegments = 12;
}
