<?php

declare(strict_types=1);

namespace Modules\Master\Controllers;

use App\Core\BackendController;
use Throwable;

class MasterController extends BackendController
{
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
    }

    public function index()
    {
        return 'index';
    }
}
