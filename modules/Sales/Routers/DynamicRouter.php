<?php

declare(strict_types=1);

namespace Modules\Sales\Routers;

use App\Libraries\DynamicRouterBase;

class DynamicRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Sales';

    protected string $moduleNamespace = 'Modules\\Sales\\Controllers\\API\\V1';

    protected string $defaultController = 'SalesApiController';

    protected array $requiredRoles = [];

    protected array $requiredPermissions = [];

}
