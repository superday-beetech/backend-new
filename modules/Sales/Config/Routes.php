<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */


$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '(:segment)/(:segment)/(:any)',
    '\Modules\Sales\Routers\DynamicRouter::route/$1/$2/$3'
);

$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '(:segment)/(:segment)',
    '\Modules\Sales\Routers\DynamicRouter::route/$1/$2'
);

$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '(:segment)',
    '\Modules\Sales\Routers\DynamicRouter::route/$1'
);

$routes->match(
    ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '/',
    '\Modules\Sales\Routers\DynamicRouter::route'
);
