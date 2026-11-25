<?php

declare(strict_types=1);

namespace Modules\Core\Routers;

use App\Libraries\DynamicRouterBase;

class DynamicRouter extends DynamicRouterBase
{
    protected string $moduleFolder = 'Core';

    protected string $moduleNamespace = 'Modules\\Core\\Controllers';

    protected string $defaultController = 'OrganizationController';

    protected array $requiredRoles = [];

    protected array $requiredPermissions = [];

    /*     protected array $httpVerbExclusions = [
            'LoginController::index',
            'LoginController::login',
            'LoginController::action',
            'LoginController::logout',
            'MagicController::login',
            'RegisterController::index',
            'RegisterController::action',
        ]; */
}
