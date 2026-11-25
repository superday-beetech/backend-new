<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Satu-satunya sumber kebijakan autentikasi aplikasi.
 * Web/Vue browser memakai session; API menerima session untuk Vue
 * dan Shield access token untuk mobile/integrasi eksternal.
 */
class AuthPolicy extends BaseConfig
{
    public string $loginRoute = 'auth/login';
    public string $apiPrefix = 'api';

    public array $webAuthenticators = ['session'];

    /** Session diprioritaskan; token menjadi jalur klien non-browser. */
    public array $apiAuthenticators = ['session', 'tokens'];
}
