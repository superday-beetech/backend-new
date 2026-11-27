<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers\API\V1;

use App\Core\ApiController;
use App\Enum\ApiErrorCode;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Result;
use Modules\Auth\Services\UserService;
use Modules\Auth\Config\Services as AuthServices;

class AuthController extends ApiController
{
    private const LOGIN_RULES = [
        'identity' => 'required|string|max_length[254]',
        'password' => 'required|string',
    ];

    protected UserService $userService;

    public function __construct()
    {
        $this->userService = AuthServices::userService();
    }

    public function login(): ResponseInterface
    {
        if (auth()->loggedIn()) {
            return $this->apiOk(
                [
                    'authenticated' => true,
                    'mode'          => 'session',
                    'redirect'      => '/dashboard',
                ],
                'Anda sudah login.'
            );
        }

        $data = $this->getRequestData();

        if ($data === null) {
            return $this->apiFail(
                ApiErrorCode::BAD_REQUEST,
                'Body request tidak valid atau kosong.'
            );
        }

        $input = $this->extractLoginPayload($data);

        if (! $this->passesLoginValidation($input)) {
            return $this->apiFail(
                ApiErrorCode::VALIDATION_ERROR,
                'Username/Email dan Password wajib diisi.'
            );
        }

        [$authenticator, $result] = $this->attemptLogin(
            $this->buildCredentials($input['identity'], $input['password']),
            $input['remember']
        );

        if (! $result->isOK()) {
            return $this->apiFail(
                ApiErrorCode::UNAUTHENTICATED,
                'Username/Email atau Password tidak valid.'
            );
        }

        $userPayload = $authenticator->hasAction()
            ? $result['user']
            : $result;

        return $this->apiOk(
            ['user' => $userPayload],
            'Login berhasil.'
        );
    }

    public function action(): ResponseInterface
    {
        $input = $this->extractLoginPayload(
            $this->request->getPost() ?? []
        );

        if (! $this->passesLoginValidation($input)) {
            return $this->apiFail(
                ApiErrorCode::VALIDATION_ERROR,
                'Terjadi kesalahan pada input.',
                extensions: ['errors' => $this->validator->getErrors()]
            );
        }

        [$authenticator, $result] = $this->attemptLogin(
            $this->buildCredentials($input['identity'], $input['password']),
            $input['remember']
        );

        if (! $result->isOK()) {
            return $this->errorResponse(
                'Username/Email atau Password tidak valid.',
                401
            );
        }

        if ($authenticator->hasAction()) {
            return redirect()
                ->route('auth-action-show')
                ->withCookies();
        }

        return redirect()
            ->to(config('Auth')->loginRedirect())
            ->withCookies();
    }

    public function me(): ResponseInterface
    {
        $user = auth()->user();

        if (! $user) {
            return $this->apiFail(
                ApiErrorCode::UNAUTHENTICATED,
                'Session tidak ditemukan pada request API.'
            );
        }

        $profile = $this->userService->getProfile($user);

        $accessControl = service('accessControl');

        $profile['permissions']    = $accessControl->getEffectivePermissions((int) $user->id);
        $profile['is_superadmin']  = $accessControl->isSuperadmin((int) $user->id);
        $profile['groups']         = $user->getGroups();
        $profile['identities']     = $user->identities;

        return $this->apiOk(
            $profile,
            'Profil pengguna berhasil diambil.'
        );
    }

    public function logout(): ResponseInterface
    {
        auth()->logout();

        return $this->apiOk(null, lang('Auth.successLogout'));
    }

    private function extractLoginPayload(array $payload): array
    {
        return [
            'identity' => trim((string) ($payload['identity'] ?? '')),
            // Jangan trim password.
            'password' => (string) ($payload['password'] ?? ''),
            'remember' => filter_var(
                $payload['remember'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    private function passesLoginValidation(array $input): bool
    {
        return $this->validateData(
            [
                'identity' => $input['identity'],
                'password' => $input['password'],
            ],
            self::LOGIN_RULES
        );
    }

    private function buildCredentials(string $identity, string $password): array
    {
        $field = filter_var($identity, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'username';

        return [
            $field      => $field === 'email' ? mb_strtolower($identity) : $identity,
            'password'  => $password,
        ];
    }

    /**
     * @return array{0: Session, 1: Result}
     */
    private function attemptLogin(array $credentials, bool $remember): array
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        $result = $authenticator
            ->remember($remember)
            ->attempt($credentials);

        return [$authenticator, $result];
    }

    private function payload(): array
    {
        $json = $this->request->getJSON(true);

        if (is_array($json)) {
            return array_merge(
                $this->request->getPost() ?? [],
                $json
            );
        }

        return $this->request->getPost() ?? [];
    }
}
