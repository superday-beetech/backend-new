<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enum\ApiErrorCode;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class ProblemsController extends Controller
{
    public function show(string $code): ResponseInterface
    {
        $errorCode = ApiErrorCode::tryFrom(strtoupper($code));

        if ($errorCode === null) {
            return $this->response
                ->setStatusCode(404)
                ->setContentType('application/problem+json')
                ->setJSON([
                    'type'   => 'https://api.cakra.test/problems/unknown_problem',
                    'title'  => 'Unknown Problem Type',
                    'status' => 404,
                    'detail' => "Kode error '{$code}' tidak dikenal.",
                ]);
        }

        // Sajikan dokumentasi (bisa dari file Markdown, DB, atau view)
        return $this->response
            ->setContentType('text/html')
            ->setBody(view('problems/' . strtolower($errorCode->value), [
                'code'  => $errorCode,
                'title' => $errorCode->defaultTitle(),
            ]));
    }
}
