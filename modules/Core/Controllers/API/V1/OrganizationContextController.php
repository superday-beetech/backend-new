<?php

declare(strict_types=1);

namespace Modules\Core\Controllers\API\V1;

use App\Core\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Modules\Core\Services\OrganizationContextService;
use RuntimeException;
use Throwable;

class OrganizationContextController extends ApiController
{
    private OrganizationContextService $contextService;

    public function __construct()
    {
        $this->contextService = new OrganizationContextService();
    }

    public function index(): ResponseInterface
    {
        return $this->indexContext();
    }

    /**
     * GET organization/context
     *
     * Mengambil konteks organisasi yang sedang aktif.
     */
    public function indexContext()
    {
        $userId = $this->authenticatedUserId();

        if ($userId === 0) {
            return $this->jsonError(
                'UNAUTHENTICATED',
                'Sesi pengguna tidak ditemukan.',
                401
            );
        }

        try {
            $context = $this->contextService->getCurrentContext($userId);

            return $this->jsonSuccess(
                $context,
                $context === null
                    ? 'Belum ada organisasi aktif.'
                    : 'Konteks organisasi berhasil dimuat.'
            );
        } catch (Throwable $e) {
            log_message('error', '[Organization Context] ' . $e->getMessage());

            return $this->jsonError(
                'CONTEXT_LOAD_FAILED',
                'Gagal memuat konteks organisasi.',
                500
            );
        }
    }

    /**
     * GET organization/available
     *
     * Mengambil daftar organisasi yang dapat diakses pengguna.
     */
    public function indexAvailable()
    {
        $userId = $this->authenticatedUserId();

        if ($userId === 0) {
            return $this->jsonError(
                'UNAUTHENTICATED',
                'Sesi pengguna tidak ditemukan.',
                401
            );
        }

        try {
            $organizations = $this->contextService
                ->getAvailableOrganizations($userId);

            return $this->jsonSuccess(
                $organizations,
                'Daftar organisasi berhasil dimuat.'
            );
        } catch (Throwable $e) {
            log_message('error', '[Organization Available] ' . $e->getMessage());

            return $this->jsonError(
                'ORGANIZATION_LIST_FAILED',
                'Gagal memuat daftar organisasi.',
                500
            );
        }
    }

    /**
     * POST organization/switch
     *
     * Body:
     * {
     *     "organization_id": 2
     * }
     */
    public function switchOrganization()
    {
        $userId = $this->authenticatedUserId();

        if ($userId === 0) {
            return $this->jsonError(
                'UNAUTHENTICATED',
                'Sesi pengguna tidak ditemukan.',
                401
            );
        }

        $data = $this->getRequestData();

        $organizationId = $data['organization_id'] ?? null;
        $unitId = $data['unit_id'] ?? null;

        // Validasi organization_id harus integer positif.
        if (
            !is_int($organizationId)
            && !(
                is_string($organizationId)
                && ctype_digit($organizationId)
            )
        ) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID harus berupa integer positif.',
                422
            );
        }

        $organizationId = (int) $organizationId;

        if ($organizationId <= 0) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID tidak valid.',
                422
            );
        }

        // unit_id bersifat opsional.
        if ($unitId !== null) {
            if (
                !is_int($unitId)
                && !(
                    is_string($unitId)
                    && ctype_digit($unitId)
                )
            ) {
                return $this->jsonError(
                    'VALIDATION_ERROR',
                    'Unit ID harus berupa integer positif.',
                    422
                );
            }

            $unitId = (int) $unitId;

            if ($unitId <= 0) {
                return $this->jsonError(
                    'VALIDATION_ERROR',
                    'Unit ID tidak valid.',
                    422
                );
            }
        }

        try {
            $context = $this->contextService->switchOrganization(
                $userId,
                $organizationId,
                $unitId
            );

            return $this->jsonSuccess(
                $context,
                'Organisasi dan unit aktif berhasil diganti.'
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonError(
                'ORGANIZATION_ACCESS_DENIED',
                $e->getMessage(),
                403
            );
        } catch (Throwable $e) {
            log_message(
                'error',
                '[Organization Switch] ' . $e->getMessage()
            );

            return $this->jsonError(
                'ORGANIZATION_SWITCH_FAILED',
                'Gagal mengganti organisasi aktif.',
                500
            );
        }
    }

    /**
     * POST organization/clear
     *
     * Menghapus konteks organisasi aktif dari session.
     */
    public function clearContext()
    {
        $userId = $this->authenticatedUserId();

        if ($userId === 0) {
            return $this->jsonError(
                'UNAUTHENTICATED',
                'Sesi pengguna tidak ditemukan.',
                401
            );
        }

        try {
            $this->contextService->clearContext();

            return $this->jsonSuccess(
                null,
                'Konteks organisasi berhasil dihapus.'
            );
        } catch (Throwable $e) {
            log_message('error', '[Organization Clear Context] ' . $e->getMessage());

            return $this->jsonError(
                'CONTEXT_CLEAR_FAILED',
                'Gagal menghapus konteks organisasi.',
                500
            );
        }
    }

    /**
     * Mengambil ID pengguna dari Shield.
     */
    private function authenticatedUserId(): int
    {
        $user = auth()->user();

        if ($user === null) {
            return 0;
        }

        return (int) $user->id;
    }

}
