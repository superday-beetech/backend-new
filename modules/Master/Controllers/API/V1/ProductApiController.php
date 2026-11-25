<?php

declare(strict_types=1);

namespace Modules\Master\Controllers\API\V1;

use App\Core\ApiController;
use Modules\Master\Services\ProductCatalogService;
use Modules\Master\Services\ProductImageService;
use Modules\Master\Services\ProductUnitService;
use Throwable;

class ProductApiController extends ApiController
{
    private ProductCatalogService $catalogService;
    private ProductImageService $imageService;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);

        $this->catalogService = new ProductCatalogService();
        $this->imageService = new ProductImageService();
    }

    // ─────────────────────────────────────────────
    // GET /products
    // ─────────────────────────────────────────────

    public function index()
    {
        $filters = [
            'search' => trim((string) ($this->request->getGet('search') ?? '')),
            'category_id' => (int) ($this->request->getGet('category_id') ?? 0),
            'is_active' => $this->request->getGet('is_active'),
        ];

        if (
            $filters['is_active'] !== null &&
            $filters['is_active'] !== '' &&
            !in_array((string) $filters['is_active'], ['0', '1'], true)
        ) {
            return $this->errorResponse('Status produk tidak valid.', 400);
        }

        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 10)));

        try {
            $result = $this->catalogService->list($filters, $page, $perPage);

            return $this->successResponse([
                'items' => $result['items'],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $result['total'],
                    'total_pages' => (int) ceil($result['total'] / $perPage),
                ],
            ], 'Data katalog berhasil diambil.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal mengambil katalog produk.');
        }
    }

    // ─────────────────────────────────────────────
    // GET /products/{id}
    // ─────────────────────────────────────────────

    public function show($id = null)
    {
        $productId = $this->validId($id);

        if (!$productId) {
            return $this->errorResponse('ID product tidak valid.', 400);
        }

        try {
            return $this->successResponse(
                $this->catalogService->detail($productId),
                'Detail produk berhasil diambil.'
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal mengambil detail produk.');
        }
    }

    // ─────────────────────────────────────────────
    // POST /products
    // ─────────────────────────────────────────────

    public function create()
    {
        $data = $this->getRequestData();

        if (!is_array($data)) {
            return $this->errorResponse('Format request tidak valid.', 400);
        }

        $rules = [
            'sku' => 'required|min_length[1]|max_length[50]',
            'name' => 'required|min_length[2]|max_length[180]',
            'category_id' => 'required|is_natural_no_zero',
            'unit_id' => 'required|is_natural_no_zero',
            'purchase_price' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'selling_price' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'barcode' => 'permit_empty|max_length[100]',
            'description' => 'permit_empty',
            'is_active' => 'permit_empty|in_list[0,1]',
        ];

        if (!$this->validateData($data, $rules)) {
            return $this->errorResponse(
                'Validasi produk gagal.',
                422,
                $this->validator->getErrors()
            );
        }

        try {
            return $this->successResponse(
                $this->catalogService->create($data, (int) auth()->id()),
                'Produk berhasil dibuat.',
                201
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal membuat produk.');
        }
    }

    // ─────────────────────────────────────────────
    // PUT/PATCH /products/{id}
    // ─────────────────────────────────────────────

    public function update($id = null)
    {
        $data = $this->getRequestData();

        if (!is_array($data) || !$data) {
            return $this->errorResponse('Data perubahan wajib diisi.', 400);
        }

        $rules = [
            'sku' => 'permit_empty|min_length[1]|max_length[50]',
            'name' => 'permit_empty|min_length[2]|max_length[180]',
            'category_id' => 'permit_empty|is_natural_no_zero',
            'unit_id' => 'permit_empty|is_natural_no_zero',
            'purchase_price' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'selling_price' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'barcode' => 'permit_empty|max_length[100]',
            'is_active' => 'permit_empty|in_list[0,1]',
        ];

        if (!$this->validateData($data, $rules)) {
            return $this->errorResponse(
                'Validasi perubahan produk gagal.',
                422,
                $this->validator->getErrors()
            );
        }

        try {
            return $this->successResponse(
                $this->catalogService->update((int) $id, $data, (int) auth()->id()),
                'Produk berhasil diperbarui.'
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal memperbarui produk.');
        }
    }

    // ─────────────────────────────────────────────
    // DELETE /products/{id}
    // ─────────────────────────────────────────────

    public function delete($id = null)
    {
        try {
            $productId = $this->validId($id);

            if (!$productId) {
                return $this->errorResponse('ID product tidak valid.', 400);
            }

            $this->catalogService->delete($productId);

            return $this->successResponse(
                ['id' => $productId],
                'Produk berhasil dihapus.'
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal menghapus produk.');
        }
    }

    // ─────────────────────────────────────────────
    // GET /products/{id}/purchase-units
    // ─────────────────────────────────────────────


    public function purchaseUnits(int $id)
    {
        try {
            $units = (new ProductUnitService())->getProductUnits($id);

            return $this->successResponse(
                $units,
                'Data satuan produk berhasil diambil.'
            );
        } catch (Throwable $e) {
            return $this->handleException(
                $e,
                'Gagal mengambil satuan produk.'
            );
        }
    }




    // ─────────────────────────────────────────────
    // GET /products/{id}/attributes
    // ─────────────────────────────────────────────

    public function attributes($id = null)
    {
        try {
            $attrId = $this->validId($id);

            if (!$attrId) {
                return $this->errorResponse('ID attributes tidak valid.', 400);
            }
            return $this->successResponse(
                $this->catalogService->attributes($attrId),
                'Atribut produk berhasil diambil.'
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal mengambil atribut produk.');
        }
    }

    // ─────────────────────────────────────────────
    // GET /products/{id}/prices
    // ─────────────────────────────────────────────

    public function prices($id = null)
    {
        try {
            $priceId = $this->validId($id);

            if (!$priceId) {
                return $this->errorResponse('ID Harga tidak valid.', 400);
            }
            return $this->successResponse(
                $this->catalogService->prices($priceId),
                'Harga produk berhasil diambil.'
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal mengambil harga produk.');
        }
    }

    // ─────────────────────────────────────────────
    // POST /products/upload-image
    // ─────────────────────────────────────────────

    public function uploadImage()
    {
        try {
            return $this->successResponse(
                $this->imageService->upload($this->request->getFile('image')),
                'Gambar berhasil diunggah.',
                201
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal mengunggah gambar.');
        }
    }

    // ─────────────────────────────────────────────
    // POST /products/delete-image
    // ─────────────────────────────────────────────

    public function deleteImage()
    {
        try {
            $data = $this->request->getJSON(true);

            $this->imageService->delete((string) ($data['filename'] ?? ''));

            return $this->successResponse(null, 'Gambar sementara dihapus.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Gagal menghapus gambar.');
        }
    }


    /**
     * GET /Master/product-api/generateSku
     *
     * Query:
     * category_id
     * dosage_form
     */

    public function generateSku()
    {
        $categoryId = (int) $this->request->getGet('category_id');
        $dosageForm = trim(
            (string) $this->request->getGet('dosage_form')
        );

        if ($categoryId <= 0) {
            return $this->errorResponse(
                'Kategori produk wajib dipilih.',
                422
            );
        }

        try {
            $sku = $this->catalogService->previewSku(
                $categoryId,
                $dosageForm
            );

            return $this->successResponse(
                ['sku' => $sku],
                'SKU berhasil dibuat.'
            );
        } catch (Throwable $e) {
            return $this->handleException(
                $e,
                'Gagal membuat SKU.'
            );
        }
    }


}
