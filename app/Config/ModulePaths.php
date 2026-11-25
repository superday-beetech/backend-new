<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ModulePaths extends BaseConfig
{
    /**
     * Root direktori modul. Bisa string atau array.
     *
     * @var string|list<string>
     */
    public string|array $paths = 'modules';
}
