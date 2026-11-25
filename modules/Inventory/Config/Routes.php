<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */


$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '(:segment)/(:segment)/(:any)',
    '\Modules\Inventory\Routers\DynamicRouter::route/$1/$2/$3'
);

$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '(:segment)/(:segment)',
    '\Modules\Inventory\Routers\DynamicRouter::route/$1/$2'
);

$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '(:segment)',
    '\Modules\Inventory\Routers\DynamicRouter::route/$1'
);

$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '/',
    '\Modules\Inventory\Routers\DynamicRouter::route'
);
