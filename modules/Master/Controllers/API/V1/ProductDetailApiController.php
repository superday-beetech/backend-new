<?php

declare(strict_types=1);

namespace Modules\Master\Controllers\API\V1;

use App\Core\ApiController;
use Modules\Master\Models\ProductDetailModel;
use Modules\Master\Models\ProductModel;
use Throwable;

class ProductDetailApiController extends ApiController
{
    private ProductDetailModel $detailModel;
    private ProductModel $productModel;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->detailModel  = new ProductDetailModel();
        $this->productModel = new ProductModel();
    }

    public function show($productId = null)
    {
        try {
            $productId = (int) $productId;
            if ($productId <= 0) {
                return $this->apiFail('ID produk tidak valid.', 400);
            }

            $detail = $this->detailModel->where('product_id', $productId)->first();

            if (!$detail) {
                return $this->apiFail('Detail farmasi belum ada.', 404);
            }

            return $this->apiOk($detail, 'Detail farmasi berhasil diambil.');
        } catch (Throwable $e) {
            log_message('error', '[ProductDetailApiController::show] ' . $e->getMessage());
            return $this->apiFail('Gagal mengambil detail farmasi.', 500);
        }
    }

    public function create()
    {
        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data) || empty($data['product_id'])) {
                return $this->apiFail('product_id wajib diisi.', 400);
            }

            $productId = (int) $data['product_id'];

            if (!$this->productModel->find($productId)) {
                return $this->apiFail('Produk tidak ditemukan.', 404);
            }

            if ($this->detailModel->where('product_id', $productId)->first()) {
                return $this->apiFail('Detail farmasi sudah ada untuk produk ini.', 409);
            }

            $id = $this->detailModel->insert([
                'product_id'            => $productId,
                'generic_name'          => $data['generic_name'] ?? null,
                'active_ingredient'     => $data['active_ingredient'] ?? null,
                'dosage_form'           => $data['dosage_form'] ?? null,
                'strength'              => $data['strength'] ?? null,
                'drug_class'            => $data['drug_class'] ?? null,
                'bpom_number'           => $data['bpom_number'] ?? null,
                'requires_prescription' => (int) ($data['requires_prescription'] ?? 0),
                'min_stock'             => (int) ($data['min_stock'] ?? 0),
                'max_stock'             => $data['max_stock'] ?? null,
                'rack_location'         => $data['rack_location'] ?? null,
                'manufacturer'          => $data['manufacturer'] ?? null,
            ]);

            if (!$id) {
                return $this->apiFail('Gagal menyimpan detail farmasi.', 500);
            }

            return $this->apiOk($this->detailModel->find($id), 'Detail farmasi berhasil ditambahkan.', 201);
        } catch (Throwable $e) {
            log_message('error', '[ProductDetailApiController::create] ' . $e->getMessage());
            return $this->apiFail('Gagal menambahkan detail farmasi.', 500);
        }
    }

    public function update($productId = null)
    {
        try {
            $productId = (int) $productId;
            if ($productId <= 0) {
                return $this->apiFail('ID produk tidak valid.', 400);
            }

            $detail = $this->detailModel->where('product_id', $productId)->first();
            if (!$detail) {
                return $this->apiFail('Detail farmasi tidak ditemukan.', 404);
            }

            $data = $this->request->getJSON(true);
            if (!is_array($data)) {
                return $this->apiFail('Format request tidak valid.', 400);
            }

            $allowed = [
                'generic_name', 'active_ingredient', 'dosage_form', 'strength',
                'drug_class', 'bpom_number', 'requires_prescription',
                'min_stock', 'max_stock', 'rack_location', 'manufacturer',
            ];

            $update = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $data)) {
                    $update[$f] = $data[$f];
                }
            }

            if (!$update) {
                return $this->apiFail('Tidak ada data yang diperbarui.', 400);
            }

            $this->detailModel->update($detail['id'], $update);

            return $this->apiOk($this->detailModel->find($detail['id']), 'Detail farmasi berhasil diperbarui.');
        } catch (Throwable $e) {
            log_message('error', '[ProductDetailApiController::update] ' . $e->getMessage());
            return $this->apiFail('Gagal memperbarui detail farmasi.', 500);
        }
    }

    public function delete($productId = null)
    {
        try {
            $productId = (int) $productId;
            if ($productId <= 0) {
                return $this->apiFail('ID produk tidak valid.', 400);
            }

            $detail = $this->detailModel->where('product_id', $productId)->first();
            if (!$detail) {
                return $this->apiFail('Detail farmasi tidak ditemukan.', 404);
            }

            $this->detailModel->delete($detail['id']);

            return $this->apiOk(['product_id' => $productId], 'Detail farmasi berhasil dihapus.');
        } catch (Throwable $e) {
            log_message('error', '[ProductDetailApiController::delete] ' . $e->getMessage());
            return $this->apiFail('Gagal menghapus detail farmasi.', 500);
        }
    }
}
