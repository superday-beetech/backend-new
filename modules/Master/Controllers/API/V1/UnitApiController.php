<?php

declare(strict_types=1);

namespace Modules\Master\Controllers\API\V1;

use App\Core\ApiController;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Modules\Master\Models\UnitModel;
use Throwable;

class UnitApiController extends ApiController
{
    private UnitModel $unitModel;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);

        $this->unitModel = new UnitModel();
    }

    /**
     * GET /api/v1/Master/unit-api
     */
    public function index()
    {
        try {
            $page = max(1, (int) $this->request->getGet('page'));
            $perPage = (int) ($this->request->getGet('per_page') ?? 10);
            $perPage = max(1, min(100, $perPage));

            $search = trim((string) $this->request->getGet('search'));

            $model = clone $this->unitModel;

            if ($search !== '') {
                $model->groupStart()
                    ->like('name', $search)
                    ->orLike('abbreviation', $search)
                    ->groupEnd();
            }

            $total = $model->countAllResults(false);

            $items = $model
                ->orderBy('name', 'ASC')
                ->findAll($perPage, ($page - 1) * $perPage);

            return $this->successResponse([
                'items' => $items,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ], 'Data satuan berhasil diambil.');
        } catch (Throwable $e) {
            log_message('error', '[UnitApiController::index] ' . $e->getMessage());

            return $this->errorResponse(
                'Gagal mengambil data satuan.',
                500
            );
        }
    }

    /**
     * GET /api/v1/master/unit-api/show/{id}
     */
    public function show($id = null)
    {
        try {
            $satuanId = $this->validId($id);

            if (!$satuanId) {
                return $this->errorResponse('ID Satuan tidak valid.', 400);
            }

            if ($satuanId <= 0) {
                return $this->errorResponse('ID satuan tidak valid.', 400);
            }

            $unit = $this->unitModel->find($satuanId);

            if (!$unit) {
                return $this->errorResponse('Satuan tidak ditemukan.', 404);
            }

            return $this->successResponse(
                $unit,
                'Detail satuan berhasil diambil.'
            );
        } catch (Throwable $e) {
            log_message('error', '[UnitApiController::show] ' . $e->getMessage());

            return $this->errorResponse(
                'Gagal mengambil detail satuan.',
                500
            );
        }
    }

    /**
     * POST /api/v1/master/unit-api/create
     */
    public function create()
    {
        try {
            $data = $this->getRequestData();

            if (empty($data)) {
                return $this->errorResponse('Tidak ada data yang diperbarui.', 400);
            }


            $name = trim((string) ($data['name'] ?? ''));
            $abbreviation = trim((string) ($data['abbreviation'] ?? ''));

            if (!$this->validateData([
                'name' => $name,
                'abbreviation' => $abbreviation,
            ], [
                'name' => 'required|min_length[2]|max_length[60]',
                'abbreviation' => 'required|min_length[1]|max_length[20]',
            ])) {
                return $this->errorResponse(
                    'Validasi satuan gagal.',
                    422,
                    $this->validator->getErrors()
                );
            }

            if ($this->unitModel->where('name', $name)->first()) {
                return $this->errorResponse(
                    'Nama satuan sudah digunakan.',
                    409
                );
            }

            if ($this->unitModel->where('abbreviation', $abbreviation)->first()) {
                return $this->errorResponse(
                    'Singkatan satuan sudah digunakan.',
                    409
                );
            }

            $id = $this->unitModel->insert([
                'name' => $name,
                'abbreviation' => $abbreviation,
            ]);

            if (!$id) {
                return $this->errorResponse(
                    'Gagal menyimpan satuan.',
                    422,
                    $this->unitModel->errors()
                );
            }

            return $this->successResponse(
                $this->unitModel->find($id),
                'Satuan berhasil ditambahkan.',
                201
            );
        } catch (Throwable $e) {
            log_message('error', '[UnitApiController::create] ' . $e->getMessage());

            return $this->handleException($e, 'create');
        }
    }

    /**
     * PUT /api/v1/master/unit-api/update/{id}
     */
    public function update($id = null)
    {
        try {
            $satuanId = $this->validId($id);

            if (!$satuanId) {
                return $this->errorResponse('ID Satuan tidak valid.', 400);
            }

            if ($satuanId <= 0) {
                return $this->errorResponse('ID satuan tidak valid.', 400);
            }

            $existing = $this->unitModel->find($satuanId);

            if (!$existing) {
                return $this->errorResponse('Satuan tidak ditemukan.', 404);
            }

            $data = $this->getRequestData();

            if (empty($data)) {
                return $this->errorResponse('Tidak ada data yang diperbarui.', 400);
            }

            if (!is_array($data) || !$data) {
                return $this->errorResponse(
                    'Data perubahan wajib diisi.',
                    400
                );
            }

            $rules = [
                'name' => 'permit_empty|min_length[2]|max_length[60]',
                'abbreviation' => 'permit_empty|min_length[1]|max_length[20]',
            ];

            if (!$this->validateData($data, $rules)) {
                return $this->errorResponse(
                    'Validasi perubahan satuan gagal.',
                    422,
                    $this->validator->getErrors()
                );
            }

            $update = [];

            foreach (['name', 'abbreviation'] as $field) {
                if (array_key_exists($field, $data)) {
                    if (!is_string($data[$field])) {
                        return $this->errorResponse(
                            "Field {$field} harus berupa teks.",
                            422
                        );
                    }

                    $update[$field] = trim($data[$field]);
                }
            }

            if (!$update) {
                return $this->errorResponse(
                    'Tidak ada data yang diperbarui.',
                    400
                );
            }

            if (
                isset($update['name']) &&
                $this->unitModel
                ->where('name', $update['name'])
                ->where('id !=', $satuanId)
                ->first()
            ) {
                return $this->errorResponse(
                    'Nama satuan sudah digunakan.',
                    409
                );
            }

            if (
                isset($update['abbreviation']) &&
                $this->unitModel
                ->where('abbreviation', $update['abbreviation'])
                ->where('id !=', $satuanId)
                ->first()
            ) {
                return $this->errorResponse(
                    'Singkatan satuan sudah digunakan.',
                    409
                );
            }

            if (!$this->unitModel->update($satuanId, $update)) {
                return $this->errorResponse(
                    'Gagal memperbarui satuan.',
                    422,
                    $this->unitModel->errors()
                );
            }

            return $this->successResponse(
                $this->unitModel->find($satuanId),
                'Satuan berhasil diperbarui.'
            );
        } catch (Throwable $e) {
            log_message('error', '[UnitApiController::update] ' . $e->getMessage());

            return $this->handleException($e, 'update');
        }
    }

    /**
     * DELETE /api/v1/master/unit-api/delete/{id}
     */
    public function delete($id = null)
    {
        try {
            $satuanId = $this->validId($id);

            if (!$satuanId) {
                return $this->errorResponse('ID Satuan tidak valid.', 400);
            }

            if ($satuanId <= 0) {
                return $this->errorResponse('ID satuan tidak valid.', 400);
            }

            if (!$this->unitModel->find($satuanId)) {
                return $this->errorResponse('Satuan tidak ditemukan.', 404);
            }

            if (!$this->unitModel->delete($satuanId)) {
                return $this->errorResponse(
                    'Gagal menghapus satuan.',
                    422,
                    $this->unitModel->errors()
                );
            }

            return $this->successResponse(
                ['id' => $satuanId],
                'Satuan berhasil dihapus.'
            );
        } catch (Throwable $e) {
            log_message('error', '[UnitApiController::delete] ' . $e->getMessage());

            return $this->handleException($e, 'delete');
        }
    }


}
