<?php

declare(strict_types=1);

namespace Modules\Distribution\Routers;

use App\Libraries\DynamicRouterBase;

class DynamicRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Distribution';

    protected string $moduleNamespace = 'Modules\\Distribution\\Controllers\\API\\V1';

    protected string $defaultController = 'DistributionApiController';

    protected array $requiredRoles = [];

    protected array $requiredPermissions = [];

}
