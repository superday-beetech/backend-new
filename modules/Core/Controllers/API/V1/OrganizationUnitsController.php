<?php

declare(strict_types=1);

namespace Modules\Core\Controllers\API\V1;

use App\Core\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Services\OrganizationContextService;
use Modules\Core\Services\OrganizationService;
use RuntimeException;
use Throwable;

class OrganizationUnitsController extends ApiController
{
    private OrganizationService $organizationService;
    private OrganizationContextService $contextService;

    public function __construct()
    {
        $this->organizationService = new OrganizationService();
        $this->contextService = new OrganizationContextService();
    }

    /**
     * GET /organization-units?organization_id=1
     */
    public function index(): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $data = $this->getRequestData() ?? [];

        $organizationId = $this->validId(
            $this->request->getGet('organization_id')
            ?? ($data['organization_id'] ?? null)
        );

        if ($organizationId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID wajib diisi.',
                422
            );
        }

        if (! $this->hasPermission('core.organization_units.view')) {
            return $this->jsonError(
                'FORBIDDEN',
                'Tidak memiliki izin melihat unit organisasi.',
                403
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $includeInactive = $this->request->getGet('include_inactive') === '1';

            $units = $this->organizationService->listUnits(
                $userId,
                $organizationId,
                $includeInactive
            );

            return $this->jsonSuccess(
                $units,
                'Daftar unit organisasi berhasil diambil.'
            );
        } catch (Throwable $e) {
            return $this->handleUnitException(
                $e,
                'Gagal mengambil unit organisasi.'
            );
        }
    }

    /**
     * GET /organization-units/show-units
     *
     * Parameter:
     * organization_id
     * unit_id
     */
    public function showUnits(): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $data = $this->getRequestData() ?? [];

        $organizationId = $this->validId(
            $this->request->getGet('organization_id')
            ?? ($data['organization_id'] ?? null)
        );

        $unitId = $this->validId(
            $this->request->getGet('unit_id')
            ?? ($data['unit_id'] ?? null)
        );

        if ($organizationId === null || $unitId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID dan Unit ID wajib valid.',
                422
            );
        }

        if (! $this->hasPermission('core.organization_units.view')) {
            return $this->jsonError(
                'FORBIDDEN',
                'Tidak memiliki izin melihat unit organisasi.',
                403
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $unit = $this->organizationService->findUnit(
                $organizationId,
                $unitId
            );

            if ($unit === null) {
                return $this->jsonError(
                    'UNIT_NOT_FOUND',
                    'Unit organisasi tidak ditemukan.',
                    404
                );
            }

            return $this->jsonSuccess(
                $unit,
                'Detail unit organisasi berhasil diambil.'
            );
        } catch (Throwable $e) {
            return $this->handleUnitException(
                $e,
                'Gagal mengambil detail unit organisasi.'
            );
        }
    }

    /**
     * POST /organization-units/create-units/{id}
     */
    public function createUnits(int $id): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        if ($id <= 0) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID tidak valid.',
                422
            );
        }

        if (! $this->hasPermission('core.organization_units.create')) {
            return $this->jsonError(
                'FORBIDDEN',
                'Tidak memiliki izin membuat unit organisasi.',
                403
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $id)) {
            return $error;
        }

        $input = $this->getRequestData();

        if (! is_array($input) || $input === []) {
            return $this->jsonError(
                'INVALID_PAYLOAD',
                'Body request harus berupa objek JSON.',
                400
            );
        }

        try {
            $unitId = $this->organizationService->createUnit(
                $userId,
                $id,
                $input
            );

            $unit = $this->organizationService->findUnit($id, $unitId);

            return $this->jsonSuccess(
                $unit,
                'Unit organisasi berhasil dibuat.',
                201
            );
        } catch (Throwable $e) {
            return $this->handleUnitException(
                $e,
                'Gagal membuat unit organisasi.'
            );
        }
    }

    /**
     * PUT/PATCH /organization-units/update-units/{id}/{unitId}
     *
     * ID dari URL diutamakan.
     * Body masih diterima untuk kompatibilitas pemanggil lama.
     */
    public function updateUnits(
        ?int $id = null,
        ?int $unitId = null
    ): ResponseInterface {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $input = $this->getRequestData();

        if (! is_array($input) || $input === []) {
            return $this->jsonError(
                'INVALID_PAYLOAD',
                'Body request tidak valid.',
                400
            );
        }

        $organizationId = $this->validId(
            $id ?? ($input['organization_id'] ?? null)
        );

        $resolvedUnitId = $this->validId(
            $unitId ?? ($input['unit_id'] ?? null)
        );

        if ($organizationId === null || $resolvedUnitId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID dan Unit ID wajib valid.',
                422
            );
        }

        if (! $this->hasPermission('core.organization_units.update')) {
            return $this->jsonError(
                'FORBIDDEN',
                'Tidak memiliki izin mengubah unit organisasi.',
                403
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $updated = $this->organizationService->updateUnit(
                $userId,
                $organizationId,
                $resolvedUnitId,
                $input
            );

            if (! $updated) {
                return $this->jsonError(
                    'UPDATE_FAILED',
                    'Unit organisasi gagal diperbarui.',
                    500
                );
            }

            return $this->jsonSuccess(
                $this->organizationService->findUnit(
                    $organizationId,
                    $resolvedUnitId
                ),
                'Unit organisasi berhasil diperbarui.'
            );
        } catch (Throwable $e) {
            return $this->handleUnitException(
                $e,
                'Gagal memperbarui unit organisasi.'
            );
        }
    }

    /**
     * DELETE /organization-units/delete-units/{id}/{unitId}
     */
    public function deleteUnits(
        int $id,
        int $unitId
    ): ResponseInterface {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        if ($id <= 0 || $unitId <= 0) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID dan Unit ID tidak valid.',
                422
            );
        }

        if (! $this->hasPermission('core.organization_units.delete')) {
            return $this->jsonError(
                'FORBIDDEN',
                'Tidak memiliki izin menonaktifkan unit organisasi.',
                403
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $id)) {
            return $error;
        }

        try {
            $deleted = $this->organizationService->deactivateUnit(
                $userId,
                $id,
                $unitId
            );

            if (! $deleted) {
                return $this->jsonError(
                    'DEACTIVATE_FAILED',
                    'Unit organisasi gagal dinonaktifkan.',
                    500
                );
            }

            return $this->jsonSuccess(
                [
                    'id' => $unitId,
                    'organization_id' => $id,
                    'status' => 'inactive',
                ],
                'Unit organisasi berhasil dinonaktifkan.'
            );
        } catch (Throwable $e) {
            return $this->handleUnitException(
                $e,
                'Gagal menonaktifkan unit organisasi.'
            );
        }
    }

    /**
     * Validasi organisasi terhadap konteks aktif.
     */
    private function requireActiveOrganization(
        int $userId,
        int $organizationId
    ): ?ResponseInterface {
        try {
            $context = $this->contextService->getCurrentContext($userId);

            if (
                $context === null
                || empty($context['organization']['id'])
            ) {
                return $this->jsonError(
                    'ORGANIZATION_CONTEXT_REQUIRED',
                    'Pilih organisasi aktif terlebih dahulu.',
                    409
                );
            }

            if (
                (int) $context['organization']['id'] !== $organizationId
            ) {
                return $this->jsonError(
                    'ORGANIZATION_CONTEXT_MISMATCH',
                    'Organisasi request tidak sesuai dengan organisasi aktif.',
                    409
                );
            }

            return null;
        } catch (Throwable $e) {
            log_message(
                'error',
                '[OrganizationUnitsController] Context validation failed: {message}',
                ['message' => $e->getMessage()]
            );

            return $this->jsonError(
                'ORGANIZATION_CONTEXT_UNAVAILABLE',
                'Konteks organisasi tidak dapat divalidasi.',
                503
            );
        }
    }

    /**
     * Pemeriksaan permission RBAC.
     */
    private function hasPermission(string $permission): bool
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return false;
        }

        return service('accessControl')->hasPermission(
            $userId,
            $permission
        );
    }

    /**
     * Penanganan error unit organisasi.
     */
    private function handleUnitException(
        Throwable $e,
        string $fallback
    ): ResponseInterface {
        if ($e instanceof RuntimeException) {
            $message = strtolower($e->getMessage());

            if (str_contains($message, 'tidak ditemukan')) {
                return $this->jsonError(
                    'NOT_FOUND',
                    $e->getMessage(),
                    404
                );
            }

            if (
                str_contains($message, 'tidak memiliki kewenangan')
                || str_contains($message, 'akses organisasi')
            ) {
                return $this->jsonError(
                    'FORBIDDEN',
                    $e->getMessage(),
                    403
                );
            }

            return $this->jsonError(
                'VALIDATION_ERROR',
                $e->getMessage(),
                422
            );
        }

        log_message(
            'error',
            '[OrganizationUnitsController] {message}',
            ['message' => $e->getMessage()]
        );

        return $this->handleException($e, $fallback);
    }
}
