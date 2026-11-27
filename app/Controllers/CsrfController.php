<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

final class CsrfController extends ResourceController
{
    public function token(): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setJSON([
                'status'     => 'success',
                'csrf_token' => csrf_hash(),
            ]);
    }
}
