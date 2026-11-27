<?php

declare(strict_types=1);

namespace App\Core;

use App\Enum\ApiErrorCode;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\InvalidArgumentException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Modules\Core\Exceptions\DomainException;
use App\Libraries\Pagination\PaginationRequest;
use App\Libraries\Pagination\PaginationResult;
use Psr\Log\LoggerInterface;
use Throwable;

class ApiController extends ResourceController
{
    protected $format = 'json';
    /** Default per_page jika klien tidak mengirim. */
    protected int $defaultPerPage = 20;

    /** Cap maksimum per_page. */
    protected int $maxPerPage = 100;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
    }

    /**
     * Mengambil user ID dari CodeIgniter Shield.
     */
    protected function getUserId(): int|ResponseInterface
    {
        $userId = (int) auth()->id();

        if ($userId < 1) {
            return $this->apiFail(
                ApiErrorCode::UNAUTHENTICATED,
                'User tidak terautentikasi.'
            );
        }

        return $userId;
    }

    protected function requireUserId(): int
    {
        $userId = (int) auth()->id();

        if ($userId < 1) {
            throw DomainException::unauthorized('User tidak terautentikasi.');
        }

        return $userId;
    }

    /**
     * Mengambil parameter pagination dari HTTP Request.
     */
    protected function getPaginationRequest(): PaginationRequest
    {
        $rawPage    = $this->request->getGet('page');
        $rawPerPage = $this->request->getGet('per_page');

        $page    = $rawPage === null
            ? 1
            : max(1, (int) $rawPage);

        $perPage = $rawPerPage === null
            ? $this->defaultPerPage
            : (int) $rawPerPage;

        $perPage = min(max($perPage, 1), $this->maxPerPage);

        return PaginationRequest::fromArray([
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Response sukses standar untuk data berpaginasi.
     *
     * @param array<int, mixed>    $items
     * @param array<string, mixed> $extraMeta
     */
    protected function jsonPaginated(
        array $items,
        PaginationResult $pagination,
        string $message = 'Data berhasil dimuat.',
        int $status = 200,
        array $extraMeta = []
    ): ResponseInterface {
        $meta = array_merge(
            ['pagination' => $pagination->toArray()],
            $extraMeta
        );

        return $this->apiOk(
            data: $items,
            message: $message,
            status: $status,
            meta: $meta
        );
    }

    /**
    * Response sukses standar REST API.
    *
    * @param mixed  $data    Payload utama (array/objek/null).
    * @param string $message Pesan manusiawi.
    * @param int    $status  HTTP status (default 200).
    * @param array  $meta    Metadata tambahan (pagination, dsb).
    */
    protected function apiOk(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = []
    ): ResponseInterface {
        $payload = [
            'error'   => false,
            'message' => $message,
            'data'    => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $this->response
            ->setStatusCode($status)
            ->setJSON($payload);
    }

    /**
 * Response error standar REST API (RFC 9457 Problem Details).
 *
 * Pemakaian:
 *   $this->apiFail(ApiErrorCode::INTERNAL_SERVER_ERROR, 'Gagal memuat pengaturan.');
 *   $this->apiFail(ApiErrorCode::INVALID_ID, 'ID tidak valid.', instance: '/organizations/abc123');
 *   $this->apiFail(
 *       ApiErrorCode::VALIDATION_ERROR,
 *       'Validasi gagal.',
 *       extensions: ['errors' => $errors]
 *   );
 */
    protected function apiFail(
        ApiErrorCode|string $code,
        string $detail,
        string|int|null $instanceOrStatus = null,   // fleksibel: instance (string) atau status (int)
        array $extensions = [],
        ?int $statusOverride = null
    ): ResponseInterface {
        // 1. Resolusi kode: enum atau string → enum
        $resolvedCode = $code instanceof ApiErrorCode
            ? $code
            : (ApiErrorCode::tryFrom($code) ?? ApiErrorCode::DOMAIN_ERROR);

        // 2. Resolusi instance vs status (kompatibilitas posisi ke-3 lama)
        $instance = null;

        if (is_string($instanceOrStatus)) {
            $instance = $instanceOrStatus;
        } elseif (is_int($instanceOrStatus) && $statusOverride === null) {
            // Call-site lama: apiFail('CODE', 'pesan', 401)
            $statusOverride = $instanceOrStatus;
        }

        $status = $statusOverride ?? $resolvedCode->defaultHttpStatus();

        // 3. Payload RFC 9457
        $payload = [
            'type'   => $resolvedCode->typeUri(),
            'title'  => $resolvedCode->defaultTitle(),
            'status' => $status,
            'detail' => $detail,
        ];

        if ($instance !== null) {
            $payload['instance'] = $instance;
        }

        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/problem+json')
            ->setJSON(array_merge($payload, $extensions));
    }

    /**
     * Validasi ID bilangan bulat positif.
     */
    protected function validId(mixed $id): ?int
    {
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        if (is_string($id) && ($id === '' || !ctype_digit($id))) {
            return null;
        }

        $validated = filter_var($id, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
            ],
        ]);

        return $validated !== false ? $validated : null;
    }

    /**
     * Mengambil data request berdasarkan Content-Type.
     */
    protected function getRequestData(): ?array
    {
        $contentType = strtolower(
            $this->request->getHeaderLine('Content-Type')
        );

        if (str_contains($contentType, 'application/json')) {
            try {
                $data = $this->request->getJSON(true);

                return is_array($data) ? $data : null;
            } catch (Throwable) {
                return null;
            }
        }

        $method = strtolower($this->request->getMethod());

        if (
            str_contains($contentType, 'application/x-www-form-urlencoded')
            && in_array($method, ['put', 'patch', 'delete'], true)
        ) {
            $data = $this->request->getRawInput();

            return is_array($data) && $data !== [] ? $data : null;
        }

        $data = $this->request->getPost();

        return $data !== [] ? $data : null;
    }


    /**
     * Penanganan exception terpusat.
     */
    protected function handleException(
        Throwable $e,
        string $fallback = 'Terjadi kesalahan pada server.'
    ): ResponseInterface {
        // 1. Exception aturan bisnis / domain aplikasi.
        if ($e instanceof DomainException) {
            $errors = $e->getErrors();

            return $this->apiFail(
                code: $e->getErrorCode(),
                detail: $e->getMessage(),
                extensions: $errors !== [] ? ['errors' => $errors] : [],
                statusOverride: $e->getStatusCode()
            );
        }

        // 2. Exception validasi CodeIgniter & PHP global.
        if ($e instanceof InvalidArgumentException) {
            return $this->apiFail(
                ApiErrorCode::VALIDATION_ERROR,
                $e->getMessage()
            );
        }

        // 3. Kesalahan database.
        if ($e instanceof DatabaseException) {
            log_message(
                'error',
                '[DATABASE ERROR] {message}',
                ['message' => $e->getMessage()]
            );

            return $this->apiFail(
                ApiErrorCode::DATABASE_ERROR,
                $fallback
            );
        }

        // 4. Kesalahan tak terduga.
        log_message(
            'error',
            '[API ERROR] ' . $e::class . ': ' . $e->getMessage()
        );

        return $this->apiFail(
            ApiErrorCode::INTERNAL_SERVER_ERROR,
            $fallback
        );
    }
}
