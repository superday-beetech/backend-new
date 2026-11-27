<?php

declare(strict_types=1);

namespace Modules\Master\Controllers\API\V1;

use App\Core\ApiController;
use Modules\Master\Models\CategoryTypeModel;
use Throwable;

class CategoryTypeApiController extends ApiController
{
    private CategoryTypeModel $typeModel;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->typeModel = new CategoryTypeModel();
    }

    /**
     * GET /master/category-type-api?module=pharmacy
     *
     * Mengembalikan daftar tipe kategori berdasarkan module.
     */
    public function index()
    {
        try {
            $module = trim((string) ($this->request->getGet('module') ?? ''));

            if ($module === '') {
                return $this->apiFail('Parameter module wajib diisi.', 400);
            }

            if (!in_array($module, ['pharmacy', 'minimarket', 'general'], true)) {
                return $this->apiFail('Module tidak valid.', 422);
            }

            $items = $this->typeModel->getOptionsByModule($module);

            return $this->apiOk($items, 'Daftar tipe kategori berhasil diambil.');
        } catch (Throwable $e) {
            log_message('error', '[CategoryTypeApiController::index] ' . $e->getMessage());
            return $this->apiFail('Gagal mengambil tipe kategori.', 500);
        }
    }
}
