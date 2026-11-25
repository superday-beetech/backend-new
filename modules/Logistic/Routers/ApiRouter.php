<?php

declare(strict_types=1);

namespace Modules\Logistic\Routers;

use App\Libraries\DynamicRouterBase;

class ApiRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Logistic';

    protected string $moduleNamespace = 'Modules\\Logistic\\Controllers\\API\\V1';

    protected string $defaultController = 'LogisticApiController';

}
