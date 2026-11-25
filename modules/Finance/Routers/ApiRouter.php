<?php

declare(strict_types=1);

namespace Modules\Finance\Routers;

use App\Libraries\DynamicRouterBase;

class ApiRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Finance';

    protected string $moduleNamespace = 'Modules\\Finance\\Controllers\\API\\V1';

    protected string $defaultController = 'FinanceApiController';

}
