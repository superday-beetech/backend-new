<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get(
    'print-templates/(:num)/render.pdf',
    '\Modules\Master\Controllers\API\V1\PrintTemplatesController::renderPdf/$1'
);
$routes->get(
    'print-templates/(:num)/render',
    '\Modules\Master\Controllers\API\V1\PrintTemplatesController::render/$1'
);
