<?php

declare(strict_types=1);

namespace Modules\Backend\Routers;

use App\Libraries\DynamicRouterBase;

class ApiRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Backend';

    protected string $moduleNamespace = 'Modules\\Backend\\Controllers\\API\\V1';

    protected string $defaultController = 'BackendController';
    protected array $publicMethods = [
        'AuthTokenController::index',
        'AuthTokenController::login',
    ];
}
