<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Kebijakan otorisasi/RBAC.
 * Konfigurasi autentikasi (session/token) hanya berada di AuthPolicy.
 *
 * CATATAN PENTING (refactor AccessChecker):
 * - Pattern di publicPatterns dan overrides dicocokkan ke PATH URL
 *   (bukan nama endpoint), dinormalisasi tanpa leading/trailing slash.
 * - Wildcard:
 *     "*"  = tepat SATU segmen (mis. "auth/*" match "auth/login",
 *            TIDAK match "auth/login/callback").
 *     "**" = NOL atau lebih segmen (mis. "acn/**" match "acn",
 *            "acn/login", "acn/dashboard/settings").
 * - "**" HARUS menempati segmen penuh. Pattern seperti "foo/**bar"
 *   akan melempar InvalidPolicyException saat runtime.
 * - Prefix HTTP verb opsional: "POST api/v1/auth/login".
 *   Jika ada, hanya verb tersebut yang match.
 * - Overrides dicocokkan ke path URL yang SAMA dengan publicPatterns.
 *   Pastikan pattern overrides sesuai dengan path aktual di Routes.php.
 */
class Rbac extends BaseConfig
{
    public const AUTHENTICATED = '@authenticated';

    /** Grup yang melewati grant permission setelah status akun divalidasi. */
    public array $bypassGroups = ['superadmin'];

    /**
     * Endpoint publik yang tidak membutuhkan autentikasi maupun permission.
     *
     * Format: path URL (tanpa leading/trailing slash), opsional prefix verb.
     *
     * @var list<string>
     */
    public array $publicPatterns = [
        // SPA entrypoints
        'acn',
        'acn/**',

        // Auth (Shield)
        'POST api/v1/auth/login',
        'login',
        'login/*',
        'logout',
        'auth/**',           // F5: dukung auth/login/callback, auth/reset-password/confirm, dll.

        // Health & monitoring
        'health',

        // Webhook (validasi signature via filter terpisah)
        'webhook/**',

        // F3: Product image publik (GET). Temp image TIDAK publik.
        'uploads/products/*',
        // 'uploads/products/temp/*' — TIDAK publik (butuh RBAC)

        // F4: Halaman problems publik.
        // Hapus jika route /problems/* seharusnya butuh autentikasi.
        'problems/*',
    ];

    /**
     * Pemetaan path -> permission untuk route non-standar.
     *
     * Navigation dan Settings tidak memiliki segmen resource
     * yang cukup untuk permission API otomatis.
     *
     * F1: Path HARUS sesuai dengan Routes.php.
     * Route aktual:
     *   GET  api/v1/erp/navigation
     *   GET  api/v1/erp/settings
     *   POST api/v1/erp/settings
     *
     * @var array<string, string>
     */
    public array $overrides = [
        // Backend Navigation
        'GET api/v1/erp/navigation'  => 'backend.navigation.view',
        'HEAD api/v1/erp/navigation' => 'backend.navigation.view',

        // Backend Settings
        'GET api/v1/erp/settings'  => 'backend.settings.view',
        'HEAD api/v1/erp/settings' => 'backend.settings.view',
        // POST settings ditangani oleh permission registry (bukan override),
        // karena memerlukan permission berbeda (mis. backend.settings.update).
    ];

    public int $maxPathLength = 2048;
    public int $maxSegments = 12;

    /**
     * Konstruktor: tambahkan pattern khusus environment.
     *
     * F2: Vite dev server hanya relevan di development/testing.
     * Di production, route /vite tidak tersedia.
     */
    public function __construct()
    {
        parent::__construct();

        if (in_array(ENVIRONMENT, ['development', 'testing'], true)) {
            $this->publicPatterns[] = 'vite';
        }
    }
}
