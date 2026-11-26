<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Api extends BaseConfig
{
    /**
     * Basis URI untuk field `type` pada RFC 9457 Problem Details.
     * Di-override via .env per environment.
     */
    public string $problemTypeBaseUri = 'https://api.cakra.test/problems';
}
