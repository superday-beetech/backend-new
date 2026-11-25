<?php

declare(strict_types=1);

namespace Modules\Return\Routers;

use App\Libraries\DynamicRouterBase;

class DynamicRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Return';

    protected string $moduleNamespace = 'Modules\\Return\\Controllers\\API\\V1';

    protected string $defaultController = 'ReturnApiController';

    protected array $requiredRoles = [];

    protected array $requiredPermissions = [];

}
