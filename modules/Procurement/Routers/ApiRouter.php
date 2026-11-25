<?php

declare(strict_types=1);

namespace Modules\Procurement\Routers;

use App\Libraries\DynamicRouterBase;

class ApiRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Procurement';

    protected string $moduleNamespace = 'Modules\\Procurement\\Controllers\\API\\V1';

    protected string $defaultController = 'ProcurementApiController';
    protected array $customActions = [
        'PrintTemplatesController' => ['render', 'renderPdf'],
        // atau kalau pakai short name:
        'PrintTemplates' => ['render', 'renderPdf'],
    ];
}
