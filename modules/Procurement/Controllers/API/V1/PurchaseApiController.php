<?php

declare(strict_types=1);

namespace Modules\Procurement\Controllers\API\V1;

use App\Core\ApiController;
use Config\Database;
use Modules\Core\Config\DatabaseConnect;
use Modules\Procurement\Models\PurchaseOrderItemModel;
use Modules\Procurement\Models\PurchaseOrderModel;
use Modules\Inventory\Services\InventoryService;
use Modules\Procurement\Services\ProcurementService;
use RuntimeException;
use Throwable;

class PurchaseApiController extends ApiController
{
    protected $db;

    protected ProcurementService $procurementService;
    protected InventoryService $inventoryService;

    protected PurchaseOrderModel $purchaseOrderModel;
    protected PurchaseOrderItemModel $purchaseOrderItemModel;

    protected DatabaseConnect $dbGroup;

    public function __construct()
    {
        $this->dbGroup = config(DatabaseConnect::class);

        $this->db = Database::connect($this->dbGroup->con);

        $this->procurementService = new ProcurementService();
        $this->inventoryService = new InventoryService();

        $this->purchaseOrderModel = new PurchaseOrderModel();
        $this->purchaseOrderItemModel = new PurchaseOrderItemModel();

    }

    /*
    |--------------------------------------------------------------------------
    | PURCHASE ORDER
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/v1/pharmacy/purchase-orders
     *
     * Query:
     * - search
     * - status
     * - supplier_id
     * - page
     * - per_page
     */
    public function index()
    {
        try {
            $page = max(1, (int) $this->request->getGet('page'));

            $perPage = (int) ($this->request->getGet('per_page') ?: 10);
            $perPage = min(100, max(1, $perPage));

            $builder = $this->db->table('purchase_orders po');

            $builder->select([
                'po.*',
                's.name AS supplier_name',
            ]);

            $builder->join(
                'suppliers s',
                's.id = po.supplier_id',
                'left'
            );

            // Search
            if ($search = trim((string) $this->request->getGet('search'))) {
                $builder->groupStart()
                    ->like('po.order_number', $search)
                    ->orLike('s.name', $search)
                    ->groupEnd();
            }

            // Filter status
            if ($status = $this->request->getGet('status')) {
                $builder->where('po.status', $status);
            }

            // Filter supplier
            if ($supplierId = $this->request->getGet('supplier_id')) {
                $builder->where('po.supplier_id', (int) $supplierId);
            }

            // Count total records without changing the original builder
            $countBuilder = clone $builder;
            $total = $countBuilder->countAllResults();

            // Calculate pagination
            $totalPages = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;

            // Fetch current page
            $orders = $builder
                ->orderBy('po.id', 'DESC')
                ->limit($perPage, $offset)
                ->get()
                ->getResultArray();

            return $this->successResponse(
                [
                    'items' => $orders,
                    'pagination' => [
                        'page'        => $page,
                        'per_page'    => $perPage,
                        'total'       => $total,
                        'total_pages' => $totalPages,
                        'has_next'    => $page < $totalPages,
                        'has_prev'    => $page > 1,
                    ],
                ],
                'Data Purchase Order berhasil diambil.'
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                '[ProcurementApiController::purchaseOrders] ' . $e->getMessage()
            );

            return $this->errorResponse(
                'Gagal mengambil data Purchase Order.',
                500
            );
        }
    }

    /**
     * GET /api/v1/pharmacy/purchase-orders/{id}
     */

    public function detail(int $id)
    {

        $id = filter_var($id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

        if ($id === false) {
            return $this->errorResponse('ID Goods Receipt tidak valid.', 400);
        }

        try {
            $order = $this->db->table('purchase_orders po')
                ->select([
                    'po.*',
                    's.name AS supplier_name',
                    's.phone AS supplier_phone',
                    's.email AS supplier_email',
                    's.address AS supplier_address',
                ])
                ->join('suppliers s', 's.id = po.supplier_id', 'left')
                ->where('po.id', $id)
                ->get()
                ->getRowArray();

            if (!$order) {
                return $this->errorResponse(
                    'Purchase Order tidak ditemukan.',
                    404
                );
            }

            $items = $this->db->table('purchase_order_items poi')
                ->select([
                    'poi.*',
                    'p.sku',
                    'p.name AS product_name',
                    'p.barcode',

                    // Informasi satuan transaksi.
                    'u.name AS unit_name',
                    'u.abbreviation AS unit_abbreviation',

                    // Konfigurasi satuan produk.
                    'pu.is_base',
                    'pu.is_purchase_unit',
                    'pu.is_sale_unit',
                ])
                ->join(
                    'products p',
                    'p.id = poi.product_id',
                    'left'
                )
                ->join(
                    'units u',
                    'u.id = poi.unit_id',
                    'left'
                )
                ->join(
                    'product_units pu',
                    'pu.product_id = poi.product_id
                 AND pu.unit_id = poi.unit_id',
                    'left'
                )
                ->where('poi.purchase_order_id', $id)
                ->orderBy('poi.id', 'ASC')
                ->get()
                ->getResultArray();

            $order['items'] = $items;

            return $this->successResponse(
                $order,
                'Detail Purchase Order berhasil diambil.'
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                '[ProcurementApiController::purchaseOrderDetail] ' . $e->getMessage()
            );

            return $this->errorResponse(
                'Gagal mengambil detail Purchase Order.',
                500
            );
        }
    }

    /**
     * POST /api/v1/pharmacy/purchase-orders
     */
    public function create()
    {
        try {
            $data = $this->getRequestData();

            if ($data === null) {
                return $this->errorResponse('Payload JSON tidak valid.', 400);
            }

            $orderId = $this->procurementService->createPurchaseOrder(
                $data,
                (int) auth()->id()
            );

            $order = $this->purchaseOrderModel->find($orderId);

            return $this->successResponse(
                $order,
                'Purchase Order berhasil dibuat.',
                201
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        } catch (Throwable $e) {
            log_message('error', '[ProcurementApiController::createPurchaseOrder] ' . $e->getMessage());

            return $this->errorResponse(
                'Terjadi kesalahan saat membuat Purchase Order.',
                500
            );
        }
    }

    /**
     * PUT /api/v1/pharmacy/procurement-api/updatePurchaseOrder/{id}
     */
    public function update($id = null)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

        if ($id === false) {
            return $this->errorResponse('ID Goods Receipt tidak valid.', 400);
        }

        try {
            $data = $this->getRequestData();

            if ($data === null) {
                return $this->errorResponse('Payload JSON tidak valid.', 400);
            }

            $this->procurementService->updatePurchaseOrder(
                $id,
                $data,
                (int) auth()->id()
            );

            $order = $this->purchaseOrderModel->find($id);

            return $this->successResponse(
                $order,
                'Purchase Order berhasil diperbarui.'
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                '[ProcurementApiController::updatePurchaseOrder] ' . $e->getMessage()
            );

            return $this->errorResponse(
                'Terjadi kesalahan saat memperbarui Purchase Order.',
                500
            );
        }
    }

    /**
     * DELETE /api/v1/pharmacy/procurement-api/deletePurchaseOrder/{id}
     */
    public function delete($id = null)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

        if ($id === false) {
            return $this->errorResponse('ID Goods Receipt tidak valid.', 400);
        }

        try {
            $this->procurementService->deletePurchaseOrder($id);

            return $this->successResponse(
                null,
                'Purchase Order berhasil dihapus.'
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                '[ProcurementApiController::deletePurchaseOrder] ' . $e->getMessage()
            );

            return $this->errorResponse(
                'Terjadi kesalahan saat menghapus Purchase Order.',
                500
            );
        }
    }

}
