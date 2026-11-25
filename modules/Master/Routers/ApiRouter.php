<?php

declare(strict_types=1);

namespace Modules\Master\Routers;

use App\Libraries\DynamicRouterBase;

class ApiRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Master';

    protected string $moduleNamespace = 'Modules\\Master\\Controllers\\API\\V1';

    protected string $defaultController = 'HomesController';
    protected array $customActions = [
        'PrintTemplatesController' => ['render', 'renderPdf'],
        // atau kalau pakai short name:
        'PrintTemplates' => ['render', 'renderPdf'],
    ];
}
