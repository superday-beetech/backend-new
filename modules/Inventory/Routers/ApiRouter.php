<?php

declare(strict_types=1);

namespace Modules\Inventory\Routers;

use App\Libraries\DynamicRouterBase;

class ApiRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Inventory';

    protected string $moduleNamespace = 'Modules\\Inventory\\Controllers\\API\\V1';

    protected string $defaultController = 'InventoryApiController';

}
