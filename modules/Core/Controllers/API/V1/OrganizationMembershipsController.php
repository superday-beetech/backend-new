<?php

declare(strict_types=1);

namespace Modules\Core\Controllers\API\V1;

use App\Core\ApiController;
use App\Libraries\Pagination\PaginationRequest;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Modules\Core\Services\OrganizationContextService;
use Modules\Core\Services\OrganizationMembershipService;
use RuntimeException;
use Throwable;

class OrganizationMembershipsController extends ApiController
{
    private OrganizationMembershipService $membershipService;
    private OrganizationContextService $contextService;

    public function __construct()
    {
        $this->membershipService = new OrganizationMembershipService();
        $this->contextService = new OrganizationContextService();
    }

    /**
     * GET /organization-memberships?organization_id=1
     */
    public function index(): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $organizationId = $this->validId(
            $this->request->getGet('organization_id')
        );

        if ($organizationId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID wajib diisi.',
                422
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $pagination = $this->getPaginationRequest();

            $search = $this->request->getGet('q');
            $status = $this->request->getGet('status');

            $result = $this->membershipService->listForOrganization(
                $organizationId,
                $pagination,
                $search !== null ? (string) $search : null,
                $status !== null ? (string) $status : null
            );

            return $this->jsonSuccess(
                $result,
                'Data membership berhasil dimuat.'
            );
        } catch (Throwable $e) {
            return $this->handleMembershipException($e);
        }
    }

    /**
     * GET /organization-memberships/{id}?organization_id=1
     */
    public function show($id = null): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $membershipId = $this->validId($id);
        $organizationId = $this->validId(
            $this->request->getGet('organization_id')
        );

        if ($membershipId === null || $organizationId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Membership ID dan Organization ID wajib valid.',
                422
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $membership = $this->membershipService->findForOrganization(
                $organizationId,
                $membershipId
            );

            if ($membership === null) {
                return $this->jsonError(
                    'NOT_FOUND',
                    'Membership tidak ditemukan.',
                    404
                );
            }

            return $this->jsonSuccess(
                $membership,
                'Detail membership berhasil dimuat.'
            );
        } catch (Throwable $e) {
            return $this->handleMembershipException($e);
        }
    }

    /**
     * POST /organization-memberships
     */
    public function create(): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $data = $this->getRequestData();

        if ($data === null) {
            return $this->jsonError(
                'BAD_REQUEST',
                'Body request tidak valid atau kosong.',
                400
            );
        }

        $organizationId = $this->validId(
            $data['organization_id'] ?? null
        );

        if ($organizationId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID wajib diisi.',
                422
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $membershipId = $this->membershipService->create(
                $organizationId,
                $data
            );

            $membership = $this->membershipService->findForOrganization(
                $organizationId,
                $membershipId
            );

            return $this->jsonSuccess(
                $membership,
                'Membership berhasil dibuat.',
                201
            );
        } catch (Throwable $e) {
            return $this->handleMembershipException($e);
        }
    }

    /**
     * PUT/PATCH /organization-memberships/{id}
     */
    public function update($id = null): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $membershipId = $this->validId($id);
        $data = $this->getRequestData();

        if ($membershipId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Membership ID tidak valid.',
                422
            );
        }

        if ($data === null) {
            return $this->jsonError(
                'BAD_REQUEST',
                'Body request tidak valid atau kosong.',
                400
            );
        }

        $organizationId = $this->validId(
            $data['organization_id'] ?? null
        );

        if ($organizationId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Organization ID wajib diisi.',
                422
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $this->membershipService->update(
                $organizationId,
                $membershipId,
                $data
            );

            $membership = $this->membershipService->findForOrganization(
                $organizationId,
                $membershipId
            );

            return $this->jsonSuccess(
                $membership,
                'Membership berhasil diperbarui.'
            );
        } catch (Throwable $e) {
            return $this->handleMembershipException($e);
        }
    }

    /**
     * DELETE /organization-memberships/{id}?organization_id=1
     */
    public function delete($id = null): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        $membershipId = $this->validId($id);
        $organizationId = $this->validId(
            $this->request->getGet('organization_id')
        );

        if ($membershipId === null || $organizationId === null) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                'Membership ID dan Organization ID wajib valid.',
                422
            );
        }

        if ($error = $this->requireActiveOrganization($userId, $organizationId)) {
            return $error;
        }

        try {
            $this->membershipService->deactivate(
                $organizationId,
                $membershipId
            );

            return $this->jsonSuccess(
                ['id' => $membershipId],
                'Membership berhasil dinonaktifkan.'
            );
        } catch (Throwable $e) {
            return $this->handleMembershipException($e);
        }
    }

    /**
     * GET /organization-memberships/index-users
     */
    public function indexUsers(): ResponseInterface
    {
        $userId = $this->getUserId();

        if ($userId instanceof ResponseInterface) {
            return $userId;
        }

        if ($error = $this->requireActiveOrganization($userId)) {
            return $error;
        }

        try {
            $pagination = $this->getPaginationRequest();

            $search = $this->request->getGet('q');

            $result = $this->membershipService->listUsers(
                $pagination,
                $search !== null ? (string) $search : null
            );

            return $this->jsonSuccess(
                $result,
                'Daftar user berhasil dimuat.'
            );
        } catch (Throwable $e) {
            return $this->handleMembershipException($e);
        }
    }

    /**
     * Memastikan request berada pada organisasi aktif.
     *
     * Jika organizationId null, hanya memastikan konteks tersedia.
     */
    private function requireActiveOrganization(
        int $userId,
        ?int $organizationId = null
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
                $organizationId !== null
                && (int) $context['organization']['id'] !== $organizationId
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
                '[OrganizationMembershipsController] Context validation failed: {message}',
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
     * Penanganan exception Membership.
     */
    private function handleMembershipException(
        Throwable $e
    ): ResponseInterface {
        if ($e instanceof InvalidArgumentException) {
            return $this->jsonError(
                'VALIDATION_ERROR',
                $e->getMessage(),
                422
            );
        }

        if ($e instanceof RuntimeException) {
            $message = strtolower($e->getMessage());

            if (
                str_contains($message, 'tidak ditemukan')
                || str_contains($message, 'tidak aktif')
            ) {
                return $this->jsonError(
                    'NOT_FOUND',
                    $e->getMessage(),
                    404
                );
            }

            return $this->jsonError(
                'BAD_REQUEST',
                $e->getMessage(),
                422
            );
        }

        return $this->handleException(
            $e,
            'Gagal memproses data membership.'
        );
    }
}
