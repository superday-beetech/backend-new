<?php

namespace App\Libraries;

use App\Libraries\DynamicRouter\AccessChecker;
use App\Libraries\DynamicRouter\AuthenticationResolver;
use App\Libraries\DynamicRouter\ControllerScanner;
use App\Libraries\DynamicRouter\HttpVerbPolicy;
use App\Libraries\DynamicRouter\MethodPolicy;
use App\Libraries\DynamicRouter\RateLimiter;
use App\Libraries\DynamicRouter\RouteResolver;
use App\Libraries\DynamicRouter\RouteRegistrySynchronizer;
use App\Libraries\DynamicRouter\RouterResponseHandler;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\AuthPolicy;
use Config\ModulePaths;
use Config\Rbac;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

abstract class DynamicRouterBase extends Controller
{
    protected const ENDPOINT_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*::[A-Za-z_][A-Za-z0-9_]*$/';

    protected const HTTP_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    protected const ARRAY_CONFIG_KEYS = [
        'allowedMethods',
        'publicMethods',
        'requiredPermissions',
        'requiredRoles',
        'allowedHttpVerbs',
        'httpVerbExclusions',
        'rateLimits',
        'authApiExceptions',
    ];
    private const RELAXABLE_ENVIRONMENTS = ['development', 'testing'];

    protected ModulePaths $modulePaths;
    protected AuthPolicy $authPolicy;
    protected Rbac $rbacPolicy;
    protected string $moduleFolder = '';
    protected string $moduleNamespace = '';
    protected string $defaultController = '';

    /** @var bool Aktif/nonaktif module */
    protected bool $enabled = true;

    /** @var bool Wajib login? */
    protected bool $authRequired = false;
    protected array $authApiExceptions = [];

    /** @var array Allowed controller names (tanpa namespace) */
    protected array $allowedControllers = [];

    /** @var array Allowed method names */
    protected array $allowedMethods = [];

    /** @var array endpoint => required permissions */
    protected array $requiredPermissions = [];

    /** @var array endpoint => [GET, POST, ...] */
    protected array $allowedHttpVerbs = [];

    /** @var array endpoint => ['limit' => 10, 'minutes' => 1] */
    protected array $rateLimits = [];

    protected bool $rateLimitEnabled = false;

    /** @var array endpoint => required roles */
    protected array $requiredRoles = [];

    /** @var callable|null */
    protected $fallbackHandler = null;
    protected $apiFallbackHandler;
    protected $uiFallbackHandler;

    /** @var array Hooks sebelum controller. Boleh return ResponseInterface untuk menghentikan request. */
    protected array $beforeRouteHooks = [];

    /** @var array Hooks sesudah controller */
    protected array $afterRouteHooks = [];

    protected array $discoveredMethods = [];
    protected array $controllerClasses = [];
    protected array $routerConfig = [];

    /** @var list<string> Endpoint publik tanpa permission */
    protected array $publicMethods = [];
    protected array $httpVerbExclusions = [];

    protected ControllerScanner $controllerScanner;
    protected MethodPolicy $methodPolicy;
    protected RouteResolver $routeResolver;
    protected AuthenticationResolver $authenticationResolver;
    protected AccessChecker $accessChecker;
    protected HttpVerbPolicy $httpVerbPolicy;
    protected RateLimiter $rateLimiter;
    protected RouterResponseHandler $routerResponseHandler;
    private static array $reflectionCache = [];

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);

        $this->logger = $logger;

        // Inisialisasi config SEBELUM loadRouterConfig().
        $this->modulePaths = config(ModulePaths::class);
        $this->authPolicy  = config(AuthPolicy::class);
        $this->rbacPolicy  = config(Rbac::class);

        // 1. Muat konfigurasi router.
        $this->loadRouterConfig();

        // 2. Scan controller.
        $this->controllerScanner = new ControllerScanner(
            $this->moduleFolder,
            $this->moduleNamespace
        );
        $this->scanControllersAndMethods();

        // 3. Kebijakan method.
        $this->methodPolicy = new MethodPolicy(
            $this->discoveredMethods,
            $this->allowedMethods,
            $this->moduleFolder
        );

        // 4. Resolver route.
        $this->routeResolver = new RouteResolver(
            $request,
            $this->methodPolicy,
            $this->controllerClasses,
            $this->defaultController
        );

        // 5. Autentikasi.
        $this->authenticationResolver = new AuthenticationResolver(
            $request,
            $this->authPolicy
        );

        // 6. Otorisasi.
        $this->accessChecker = new AccessChecker(
            $request,
            $this->authenticationResolver,
            $this->moduleFolder,
            $this->publicMethods,
            $this->rbacPolicy
        );

        // 7. HTTP verb.
        $this->httpVerbPolicy = new HttpVerbPolicy(
            $request,
            $this->allowedHttpVerbs,
            $this->httpVerbExclusions,
            $this->moduleFolder
        );

        // 8. Rate limit.
        $this->rateLimiter = new RateLimiter(
            $request,
            $this->moduleFolder,
            $this->rateLimits,
            $this->authenticationResolver
        );

        $this->configureResponseHandlers();
    }

    public function getRouterConfig(): array
    {
        return $this->routerConfig;
    }

    protected function scanControllersAndMethods(): void
    {
        $this->applyScanResult($this->controllerScanner->scan());
    }

    protected function scanControllersAndMethodsNoCache(): void
    {
        $this->applyScanResult($this->controllerScanner->scanWithoutCache());
    }

    protected function applyScanResult(array $result): void
    {
        $this->allowedControllers = $result['allowedControllers'];
        $this->discoveredMethods  = $result['discoveredMethods'];
        $this->controllerClasses  = $result['controllerClasses'];
    }


    public function route(
        string $seg1 = '',
        ?string $seg2 = null,
        mixed ...$params
    ): mixed {
        /*
         * 1. Pastikan seluruh komponen router sudah siap.
         */
        $this->assertRouterInitialized();

        /*
        * 2. Periksa status module/router.
        */
        if (! $this->enabled && ! $this->isSuperadmin()) {
            throw PageNotFoundException::forPageNotFound();
        }

        /*
         * 3. Validasi path policy.
         */
        $path = trim($this->request->getUri()->getPath(), '/');
        $policy = $this->rbacPolicy;

        if (strlen($path) > $policy->maxPathLength) {
            $this->rejectAsNotFound(
                'path_policy',
                'PATH_TOO_LONG',
                ['length' => strlen($path)]
            );
        }

        $segments = $path === '' ? [] : explode('/', $path);

        if (count($segments) > $policy->maxSegments) {
            $this->rejectAsNotFound(
                'path_policy',
                'TOO_MANY_SEGMENTS',
                ['count' => count($segments)]
            );
        }



        /*
         * 4. Resolve controller, method, dan parameter.
         */
        // Di route(), setelah resolve:
        [$controller, $method, $params] = $this->resolveControllerAndMethod($seg1, $seg2, $params);

        $params = $this->normalizeRouteParams($params);

        /*
         * 5. Security pipeline production.
         */
        if (ENVIRONMENT === 'production' || ENVIRONMENT === 'development') {

            /*
             * 5.1 Controller allowlist.
             */
            if (! in_array($controller, $this->allowedControllers, true)) {
                $this->rejectAsNotFound(
                    'controller_policy',
                    'CONTROLLER_NOT_ALLOWED',
                    ['controller' => $controller]
                );
            }

            /*
             * 5.2 Method discovery dan static allowlist.
             *
             * HTTP verb tidak dikirim ke MethodPolicy.
             * Pemeriksaan Route Registry ditangani AccessChecker.
             */
            $methodDecision = $this->methodPolicy->diagnoseMethod(
                $controller,
                $method
            );

            if (! $methodDecision['allowed']) {
                $this->rejectAsNotFound(
                    'method_policy',
                    $methodDecision['reason'],
                    [
                        'controller' => $controller,
                        'method'     => $method,
                    ]
                );
            }

            $resolved = $this->methodPolicy->resolveDiscoveredMethod(
                $controller,
                $method
            );

            if ($resolved === null) {
                $this->rejectAsNotFound(
                    'method_policy',
                    'METHOD_NOT_DISCOVERED',
                    [
                        'controller' => $controller,
                        'method'     => $method,
                    ]
                );
            }

            $method = $resolved;
            $endpoint = $controller . '::' . $method;

            /*
             * 5.3 Rate limiting.
             *
             * Tetap melindungi endpoint publik seperti login
             * dan register sebelum controller dijalankan.
             */
            if (
                $this->rateLimitEnabled
                && ! $this->checkRateLimit($endpoint)
            ) {
                return $this->handleTooManyRequests($endpoint);
            }

            /*
             * 5.4 Ambil security settings.
             *
             * Production tetap fail-closed melalui
             * isSecurityFeatureEnabled().
             */
            $checkAccess = $this->isSecurityFeatureEnabled(
                'Core.checkAccess'
            );

            $checkHttpVerbs = $this->isSecurityFeatureEnabled(
                'Core.httpVerbs'
            );

            /*
             * 5.5 Authentication dan authorization.
             *
             * AccessChecker merupakan pemeriksaan utama:
             * - Endpoint terdaftar
             * - is_discovered
             * - is_routable
             * - is_allowed
             * - HTTP verb terdaftar
             * - Public endpoint policy
             * - Authentication
             * - Status akun
             * - Bypass group
             * - Permission override
             * - Route Registry permission
             */
            if ($checkAccess) {
                $decision = $this->accessChecker->diagnoseAccess(
                    $endpoint
                );

                if (! $decision['allowed']) {
                    $this->auditDenied($endpoint, $decision);

                    return $this->routerResponseHandler
                        ->handleAccessDecision($decision);
                }
            }

            /*
             * 5.6 HTTP verb tambahan dari RouterConfig.
             *
             * Jika AccessChecker aktif:
             * - Validasi registry sudah dilakukan.
             * - HttpVerbPolicy hanya memeriksa konfigurasi lokal
             *   jika endpoint memilikinya.
             *
             * Jika AccessChecker tidak aktif:
             * - HttpVerbPolicy tetap melakukan pemeriksaan.
             * - Fallback registry tetap tersedia.
             *
             * Exclusion hanya melewati pemeriksaan tambahan ini,
             * bukan validasi HTTP verb di AccessChecker.
             */
            if (
                $checkHttpVerbs
                && ! $this->isHttpVerbExcluded($endpoint)
                && (
                    ! $checkAccess
                    || array_key_exists(
                        $endpoint,
                        $this->allowedHttpVerbs
                    )
                )
                && ! $this->checkHttpVerb($endpoint)
            ) {
                $this->logRejection(
                    'http_verb',
                    'HTTP_VERB_NOT_ALLOWED',
                    [
                        'endpoint' => $endpoint,
                        'verb'     => strtoupper(
                            $this->request->getMethod()
                        ),
                    ]
                );

                return $this->handleMethodNotAllowed(
                    'HTTP_VERB_NOT_ALLOWED',
                    null,
                    null,
                    $endpoint
                );
            }

            /*
             * 5.7 Before route hooks.
             */
            foreach ($this->beforeRouteHooks as $hook) {
                $this->assertHookCallable(
                    $hook,
                    'beforeRouteHooks'
                );

                $override = $hook(
                    $controller,
                    $method,
                    $params
                );

                if ($override instanceof ResponseInterface) {
                    return $override;
                }
            }
        }

        /*
         * 6. Jalankan controller.
         */
        $result = $this->runController(
            $controller,
            $method,
            $params
        );

        /*
         * 7. After route hooks.
         */
       if (ENVIRONMENT === 'production' || ENVIRONMENT === 'development') {
            foreach ($this->afterRouteHooks as $hook) {
                $this->assertHookCallable(
                    $hook,
                    'afterRouteHooks'
                );

                $hook(
                    $controller,
                    $method,
                    $params,
                    $result
                );
            }
        }

        return $result;
    }

    /**
 * Pastikan parameter route berupa list positional (0..n-1) berisi string.
 * Array asosiatif diubah berdasarkan urutan insertion, BUKAN nama.
 * Jika ada kunci non-sequential, log & tolak (fail-closed).
 */
    protected function normalizeRouteParams(array $params): array
    {
        if (array_is_list($params)) {
            return array_map(static fn ($v) => is_scalar($v) ? (string) $v : $v, $params);
        }

        // Bukan list: ambil nilai sesuai urutan, tapi catat warning.
        log_message('debug', 'DynamicRouter: parameter route bukan list, dinormalisasi posisional.');

        return array_values(array_map(
            static fn ($v) => is_scalar($v) ? (string) $v : $v,
            $params
        ));
    }


    protected function auditDenied(string $endpoint, array $decision): void
    {
        $context = [
            'reason'   => $decision['reason'] ?? '',
            'status'   => $decision['status'] ?? 0,
            'user_id'  => $decision['user_id'] ?? null,
            'module'   => $this->moduleFolder,
            'endpoint' => $endpoint,
            'method'   => strtoupper($this->request->getMethod()),
            'ip'       => $this->request->getIPAddress(),
        ];

        log_message(($decision['status'] ?? 0) === 401 ? 'debug' : 'warning', 'DynamicRouter denied: ' . json_encode($context));

        if (($decision['status'] ?? 0) !== 401) {
            try {
                \CodeIgniter\Events\Events::trigger('rbac_denied', $context);
            } catch (\Throwable $e) {
                log_message('error', 'Event audit gagal: ' . $e->getMessage());
            }
        }
    }



    /**
     * Setting keamanan: default AKTIF (fail-closed).
     * Di production, setting tidak bisa mematikan fitur ini.
     */
    protected function isSecurityFeatureEnabled(string $settingKey): bool
    {
        $raw = service('settings')->get($settingKey);

        if ($raw === null) {
            return true; // fail-closed
        }

        $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            log_message('warning', "DynamicRouter: nilai setting {$settingKey} tidak dikenali, dianggap AKTIF.");
            return true;
        }

        if ($enabled === true) {
            return true;
        }

        // $enabled === false
        if (! in_array(ENVIRONMENT, self::RELAXABLE_ENVIRONMENTS, true)) {
            log_message('critical', "DynamicRouter: setting {$settingKey} dimatikan di " . ENVIRONMENT . ', diabaikan.');
            return true;
        }

        return false;
    }

    protected function assertHookCallable(mixed $hook, string $name): void
    {
        if (! is_callable($hook)) {
            throw new RuntimeException("Hook pada {$name} harus callable.");
        }
    }

    protected function logRejection(string $stage, string $reason, array $context = []): void
    {
        $bucket = 'dr_log_' . md5($this->request->getIPAddress() . '|' . $stage . '|' . $reason);
        $key    = $this->safeCacheKey($bucket);

        if (cache($key) !== null) {
            return; // sudah dicatat dalam jendela ini
        }

        cache()->save($key, 1, 60); // 1 menit

        log_message('warning', 'DynamicRouter rejected: ' . json_encode([
            'stage'  => $stage,
            'reason' => $reason,
            'ip'     => $this->request->getIPAddress(),
        ] + $context));
    }
    /**
     * Catat lalu samarkan sebagai 404.
     *
     * @return never
     */
    protected function rejectAsNotFound(string $stage, string $reason, array $context = [])
    {
        $this->logRejection($stage, $reason, $context);

        throw PageNotFoundException::forPageNotFound();
    }

    protected function handleTooManyRequests(string $endpoint)
    {
        if (! is_callable($this->fallbackHandler)) {
            throw new RuntimeException('fallbackHandler belum dikonfigurasi. Panggil configureResponseHandlers() dulu.');
        }
        $minutes = (int) ($this->rateLimits[$endpoint]['minutes'] ?? 1);

        $this->response->setHeader('Retry-After', (string) (max(1, $minutes) * 60));

        return ($this->fallbackHandler)(
            'Terlalu banyak permintaan. Silakan coba lagi nanti.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }

    /**
     * Pastikan semua komponen sudah diinisialisasi sebelum route() dipanggil.
     * Mencegah null-reference error yang sulit dilacak.
     */
    protected function assertRouterInitialized(): void
    {
        $components = [
            'modulePaths'            => $this->modulePaths ?? null,
            'authPolicy'             => $this->authPolicy ?? null,
            'rbacPolicy'             => $this->rbacPolicy ?? null,
            'controllerScanner'      => $this->controllerScanner ?? null,
            'methodPolicy'           => $this->methodPolicy ?? null,
            'routeResolver'          => $this->routeResolver ?? null,
            'authenticationResolver' => $this->authenticationResolver ?? null,
            'accessChecker'          => $this->accessChecker ?? null,
            'httpVerbPolicy'         => $this->httpVerbPolicy ?? null,
            'rateLimiter'            => $this->rateLimiter ?? null,
            'routerResponseHandler'  => $this->routerResponseHandler ?? null,
            'fallbackHandler'        => $this->fallbackHandler ?? null,
            'logger'                 => $this->logger ?? null,
        ];

        $missing = array_keys(array_filter($components, static fn ($v) => $v === null));

        if ($missing !== []) {
            throw new RuntimeException(
                'DynamicRouterBase: komponen belum diinisialisasi: ' . implode(', ', $missing)
                . '. Pastikan initController() dipanggil framework.'
            );
        }
    }

    protected function isSuperadmin(): bool
    {
        return $this->accessChecker->isSuperadmin();
    }

    protected function normalizeMethodName(string $method): string
    {
        return $this->methodPolicy->normalizeMethodName($method);
    }

    protected function resolveDiscoveredMethod(string $controller, string $requestedMethod): ?string
    {
        return $this->methodPolicy->resolveDiscoveredMethod($controller, $requestedMethod);
    }

    protected function isMethodAllowed(string $controller, string $method): bool
    {
        return $this->methodPolicy->isMethodAllowed($controller, $method);
    }

    public function resolveControllerAndMethod(string $seg1 = '', ?string $seg2 = null, array $params = []): array
    {

        return $this->routeResolver->resolveControllerAndMethod($seg1, $seg2, $params);
    }

    protected function resolveMethodByVerb(string $controller, string $requestedMethod): ?string
    {
        return $this->routeResolver->resolveMethodByVerb($controller, $requestedMethod);
    }

    protected function normalizeControllerName(string $controller): string
    {
        return $this->controllerScanner->normalizeControllerName($controller);
    }

    protected function isAjax(): bool
    {
        return $this->request->isAJAX();
    }

    protected function isApiRequest(): bool
    {
        return $this->authenticationResolver->isApiRequest();
    }

    protected function isAuthenticated(): bool
    {
        return $this->authenticationResolver->isAuthenticated();
    }

    protected function getAuthenticatedUser()
    {
        return $this->authenticationResolver->getAuthenticatedUser();
    }

    protected function checkAccess(string $endpoint): bool
    {
        return $this->accessChecker->checkAccess($endpoint);
    }

    protected function checkHttpVerb(string $endpoint): bool
    {
        return $this->httpVerbPolicy->checkHttpVerb($endpoint);
    }

    protected function isHttpVerbExcluded(string $endpoint): bool
    {
        return $this->httpVerbPolicy->isHttpVerbExcluded($endpoint);
    }

    protected function checkRateLimit(string $endpoint): bool
    {
        return $this->rateLimiter->checkRateLimit($endpoint);
    }

    protected function safeCacheKey(string $key): string
    {
        return $this->rateLimiter->safeCacheKey($key);
    }



    protected function getMethodReflection(string $className, string $method): ?ReflectionMethod
    {
        $key = $className . '::' . $method;
        if (array_key_exists($key, self::$reflectionCache)) {
            return self::$reflectionCache[$key];
        }

        try {
            $reflection = new ReflectionMethod($className, $method);
            if (! $reflection->isPublic() || $reflection->isStatic()) {
                return self::$reflectionCache[$key] = null;
            }
            return self::$reflectionCache[$key] = $reflection;
        } catch (ReflectionException) {
            return self::$reflectionCache[$key] = null;
        }
    }

    protected function runController(
        string $controller,
        string $method,
        array $params = []
    ): mixed {
        $className = $this->controllerClasses[$controller] ?? null;

        $notFound = fn () => $this->handleNotFound(
            'NOT_FOUND',
            'Halaman tidak ditemukan.'
        );

        /*
         * 1. Validasi class hasil ControllerScanner.
         */
        if (
            ! is_string($className)
            || $className === ''
            || ! str_starts_with(
                $className,
                trim($this->moduleNamespace, '\\') . '\\'
            )
            || ! class_exists($className)
            || ! is_subclass_of($className, Controller::class)
        ) {
            $this->logRejection(
                'run_controller',
                'INVALID_CONTROLLER_CLASS',
                [
                    'controller' => $controller,
                    'class'      => $className,
                ]
            );

            return $notFound();
        }

        /*
         * 2. DynamicRouter tidak boleh menjadi target controller.
         * Periksa sebelum instance dibuat.
         */
        if (is_a($className, self::class, true)) {
            $this->logRejection(
                'run_controller',
                'NESTED_DYNAMIC_ROUTER',
                [
                    'controller' => $controller,
                    'class'      => $className,
                ]
            );

            return $notFound();
        }

        /*
         * 3. Validasi method berdasarkan MethodPolicy.
         */
        if (! $this->isMethodAllowed($controller, $method)) {
            $this->logRejection(
                'run_controller',
                'METHOD_NOT_ALLOWED',
                [
                    'controller' => $controller,
                    'method'     => $method,
                ]
            );

            return $notFound();
        }

        /*
         * 4. Validasi class dan constructor.
         */
        $classReflection = new \ReflectionClass($className);

        if (! $classReflection->isInstantiable()) {
            $this->logRejection(
                'run_controller',
                'CONTROLLER_NOT_INSTANTIABLE',
                [
                    'controller' => $controller,
                    'class'      => $className,
                ]
            );

            return $notFound();
        }

        $constructor = $classReflection->getConstructor();

        /*
         * DynamicRouter mengikuti pola controller CI4
         * yang digunakan repository saat ini: new Controller()
         * kemudian initController().
         *
         * Constructor dengan parameter wajib tidak didukung
         * oleh pola instansiasi ini.
         */
        if (
            $constructor !== null
            && $constructor->getNumberOfRequiredParameters() > 0
        ) {
            $this->logRejection(
                'run_controller',
                'UNSUPPORTED_REQUIRED_CONSTRUCTOR_ARGUMENTS',
                [
                    'controller' => $controller,
                    'class'      => $className,
                    'required'   => $constructor->getNumberOfRequiredParameters(),
                ]
            );

            return $notFound();
        }

        /*
         * 5. Validasi method melalui Reflection.
         */
        $reflection = $this->getMethodReflection(
            $className,
            $method
        );

        if (
            $reflection === null
            || ! $reflection->isPublic()
            || $reflection->isStatic()
        ) {
            return $notFound();
        }

        /*
         * 6. Normalisasi parameter route.
         */
        $params = array_values($params);

        if (! $this->paramsMatchSignature($reflection, $params)) {
            $this->logRejection(
                'run_controller',
                'INVALID_METHOD_ARGUMENTS',
                [
                    'controller' => $controller,
                    'method'     => $method,
                    'count'      => count($params),
                ]
            );

            return $notFound();
        }

        /*
         * 7. Buat instance controller.
         */
        $instance = $classReflection->newInstance();

        /*
         * 8. Jalankan lifecycle CI4.
         *
         * Wajib dilakukan karena controller modul menggunakan
         * initController() untuk menyiapkan service, model,
         * autentikasi, helper, dan response.
         */
        $instance->initController(
            $this->request,
            $this->response,
            $this->logger
        );

        /*
         * 9. Eksekusi method.
         *
         * Exception dari method tidak ditelan di sini.
         * Biarkan exception handler CI4 menangani kegagalan
         * eksekusi controller.
         */
        return $instance->{$method}(...$params);
    }

    /** Parameter URL selalu string: tolak yang tidak bisa menjadi tipe skalar yang diminta (hindari TypeError 500). */
    protected function paramsMatchSignature(ReflectionMethod $method, array $params): bool
    {
        foreach ($method->getParameters() as $i => $parameter) {
            if (! array_key_exists($i, $params)) {
                continue;
            }

            $value = $params[$i];
            $type  = $parameter->getType();

            // Tanpa type declaration: tidak ada yang perlu divalidasi.
            if ($type === null) {
                continue;
            }

            // Parameter bertipe union: cukup salah satu tipe cocok.
            if ($type instanceof \ReflectionUnionType) {
                $ok = false;

                foreach ($type->getTypes() as $unionType) {
                    if ($this->matchesBuiltinType($unionType, $value)) {
                        $ok = true;
                        break;
                    }
                }

                if (! $ok) {
                    return false;
                }

                continue;
            }

            if (! $this->matchesBuiltinType($type, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cocokkan satu nilai dengan satu ReflectionType.
     * Tipe non-builtin (class/interface) dianggap cocok karena
     * route parameter selalu string dan akan di-resolve oleh controller.
     */
    private function matchesBuiltinType(\ReflectionType $type, mixed $value): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($this->matchesBuiltinType($t, $value)) {
                    return true;
                }
            }
            return false;
        }

        if (! $type instanceof \ReflectionNamedType) {
            return true; // intersection type: serahkan ke controller
        }

        if (! $type->isBuiltin()) {
            return true; // class/interface: route param string, biar controller resolve
        }

        $name = $type->getName();

        if ($value === null) {
            return $type->allowsNull();
        }

        // Route param selalu string; hanya terima string.
        if (! is_string($value)) {
            return false;
        }

        return match ($name) {
            'string' => true,
            'int'    => filter_var($value, FILTER_VALIDATE_INT) !== false
                        && (string) filter_var($value, FILTER_VALIDATE_INT) === ltrim($value, '+'),
            'float'  => filter_var($value, FILTER_VALIDATE_FLOAT) !== false,
            'bool'   => in_array(strtolower($value), ['1', '0', 'true', 'false'], true),
            'mixed'  => true,
            default  => true, // iterable/object/callable: serahkan ke controller
        };
    }

    protected function handleNotFound(string $codeOrMessage = 'NOT_FOUND', ?string $customMessage = null)
    {
        return $this->routerResponseHandler->handleNotFound($codeOrMessage, $customMessage);
    }

    protected function handleMethodNotAllowed(
        string $codeOrMessage = 'HTTP_VERB_NOT_ALLOWED',
        ?string $customMessage = null,
        ?string $prefix = null,
        ?string $endpoint = null,
    ) {
        if ($endpoint !== null) {
            $verbs = $this->allowedHttpVerbs[$endpoint] ?? [];
            if (! empty($verbs)) {
                $this->response->setHeader('Allow', implode(', ', array_map('strtoupper', $verbs)));
            }
        }

        return $this->routerResponseHandler->handleMethodNotAllowed($codeOrMessage, $customMessage, $prefix);
    }

    /**
     * Inisialisasi default response handlers. Modul turunan boleh menyediakan handler sendiri.
     */
    protected function configureResponseHandlers(): void
    {
        $isApiRequest = fn (): bool => $this->isApiRequest();

        $uiFallbackHandler = function (string $message, int $status, string $code) {
            $html = view('errors/response', [
                'status'  => $status,
                'code'    => $code,
                'title'   => $this->getErrorTitle($status),
                'message' => $message,
            ]);

            return $this->response
                ->setStatusCode($status)
                ->setContentType('text/html', 'UTF-8')
                ->setBody($html);
        };

        $apiFallbackHandler = function (string $message, int $status, string $code) {
            $requestId = $this->request->getHeaderLine('X-Request-ID')
                ?: bin2hex(random_bytes(8));

            $this->response->setHeader('X-Request-ID', $requestId);

            $payload = [
                'status'     => 'error',
                'error'      => true,
                'code'       => $code,
                'message'    => $message,
                'data'       => null,
                'request_id' => $requestId,
            ];
            return $this->response
                ->setStatusCode($status)
                ->setContentType('application/json', 'UTF-8')
                ->setJSON($payload);
        };

        $fallbackHandler = function (string $message, int $status, string $code) use (
            $isApiRequest,
            $apiFallbackHandler,
            $uiFallbackHandler
        ) {
            return $isApiRequest()
                ? $apiFallbackHandler($message, $status, $code)
                : $uiFallbackHandler($message, $status, $code);
        };

        $this->apiFallbackHandler = $apiFallbackHandler;
        $this->uiFallbackHandler  = $uiFallbackHandler;
        $this->fallbackHandler    = $fallbackHandler;

        $this->routerResponseHandler = new RouterResponseHandler(
            $this->response,
            $isApiRequest,
            $fallbackHandler,
            $apiFallbackHandler,
            $uiFallbackHandler
        );
    }

    protected function getErrorTitle(int $status): string
    {
        return match ($status) {
            400     => 'Permintaan Tidak Valid',
            401     => 'Autentikasi Diperlukan',
            403     => 'Akses Ditolak',
            404     => 'Halaman Tidak Ditemukan',
            405     => 'Metode Tidak Diizinkan',
            429     => 'Terlalu Banyak Permintaan',
            500     => 'Kesalahan Internal Server',
            503     => 'Layanan Tidak Tersedia',
            default => 'Terjadi Kesalahan',
        };
    }

    protected function handleAccessDenied(string $codeOrMessage = 'ACCESS_DENIED', ?string $customMessage = null)
    {
        return $this->routerResponseHandler->handleAccessDenied($codeOrMessage, $customMessage);
    }

    protected function handleUnauthenticated(string $codeOrMessage = 'AUTHENTICATION_REQUIRED', ?string $customMessage = null)
    {
        return $this->routerResponseHandler->handleUnauthenticated($codeOrMessage, $customMessage);
    }

    public function getDefaultController(): string
    {
        return $this->defaultController;
    }

    public function getAllowedControllers(): array
    {
        return $this->allowedControllers;
    }

    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    protected function loadRouterConfig(): void
    {
        $configFile = $this->getRouterConfigPath();

        $this->routerConfig = [];

        if (is_file($configFile)) {
            $config = require $configFile;

            if (! is_array($config)) {
                throw new RuntimeException("RouterConfig harus mengembalikan array: {$configFile}");
            }

            if (ENVIRONMENT !== 'production') {
                $this->validateRouterConfig($config, $configFile);
                $this->validateAgainstShield($config, $configFile);
            }
            $this->routerConfig = $config;

            // Menimpa nilai modul.
            $this->allowedMethods      = $config['allowedMethods'] ?? $this->allowedMethods;
            $this->publicMethods       = $config['publicMethods'] ?? $this->publicMethods;
            $this->requiredPermissions = $config['requiredPermissions'] ?? $this->requiredPermissions;
            $this->requiredRoles       = $config['requiredRoles'] ?? $this->requiredRoles;
            $this->authApiExceptions   = $config['authApiExceptions'] ?? $this->authApiExceptions;
            $this->authRequired        = $config['authRequired'] ?? $this->authRequired;

            // Per endpoint: file menimpa modul.
            $this->allowedHttpVerbs = array_replace(
                $this->allowedHttpVerbs,
                $config['allowedHttpVerbs'] ?? []
            );

            $this->rateLimits = array_replace(
                $this->rateLimits,
                $config['rateLimits'] ?? []
            );

            // Pengecualian bersifat kumulatif.
            $this->httpVerbExclusions = array_values(array_unique(array_merge(
                $this->httpVerbExclusions,
                $config['httpVerbExclusions'] ?? []
            )));
        }

        // Status dihitung dari konfigurasi efektif, file ada ataupun tidak.
        $this->rateLimitEnabled = ! empty($this->rateLimits);
    }

    protected function validateRouterConfig(array $config, string $configFile): void
    {
        foreach (self::ARRAY_CONFIG_KEYS as $key) {
            if (array_key_exists($key, $config) && ! is_array($config[$key])) {
                throw new RuntimeException("RouterConfig['{$key}'] harus berupa array: {$configFile}");
            }
        }

        $this->assertEndpointList($config['allowedMethods'] ?? [], 'allowedMethods', $configFile);
        $this->assertEndpointList($config['httpVerbExclusions'] ?? [], 'httpVerbExclusions', $configFile);

        foreach ($config['publicMethods'] ?? [] as $endpoint) {
            if (! is_string($endpoint) || trim($endpoint) === '') {
                throw new RuntimeException("Setiap publicMethods harus berupa string endpoint: {$configFile}");
            }
        }

        $this->assertStringListMap($config['requiredPermissions'] ?? [], 'requiredPermissions', $configFile);
        $this->assertStringListMap($config['requiredRoles'] ?? [], 'requiredRoles', $configFile);

        foreach ($config['allowedHttpVerbs'] ?? [] as $endpoint => $verbs) {
            if (! is_string($endpoint) || ! is_array($verbs)) {
                throw new RuntimeException("Format allowedHttpVerbs tidak valid: {$configFile}");
            }

            foreach ($verbs as $verb) {
                if (! is_string($verb) || ! in_array(strtoupper($verb), self::HTTP_VERBS, true)) {
                    throw new RuntimeException("HTTP verb tidak valid pada {$endpoint}: {$configFile}");
                }
            }
        }

        foreach ($config['rateLimits'] ?? [] as $endpoint => $policy) {
            if (
                ! is_string($endpoint)
                || ! is_array($policy)
                || ! isset($policy['limit'], $policy['minutes'])
                || ! is_numeric($policy['limit'])
                || ! is_numeric($policy['minutes'])
                || (int) $policy['limit'] < 1
                || (int) $policy['minutes'] < 1
            ) {
                throw new RuntimeException("Konfigurasi rate limit tidak valid pada {$endpoint}: {$configFile}");
            }
        }

        foreach ($config['authApiExceptions'] ?? [] as $exception) {
            if (! is_string($exception) || trim($exception, '/') === '') {
                throw new RuntimeException("Setiap authApiExceptions harus berupa path yang valid: {$configFile}");
            }
        }

        if (array_key_exists('authRequired', $config) && ! is_bool($config['authRequired'])) {
            throw new RuntimeException("RouterConfig['authRequired'] harus berupa boolean: {$configFile}");
        }
    }

    protected function assertEndpointList(array $endpoints, string $name, string $configFile): void
    {
        foreach ($endpoints as $endpoint) {
            if (! is_string($endpoint) || ! preg_match(self::ENDPOINT_PATTERN, $endpoint)) {
                throw new RuntimeException("Format {$name} tidak valid: {$configFile}");
            }
        }
    }

    protected function assertStringListMap(array $map, string $name, string $configFile): void
    {
        foreach ($map as $endpoint => $values) {
            if (! is_string($endpoint) || trim($endpoint) === '' || ! is_array($values)) {
                throw new RuntimeException("Format {$name} tidak valid: {$configFile}");
            }

            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new RuntimeException("Nilai tidak valid pada {$name} untuk {$endpoint}: {$configFile}");
                }
            }
        }
    }

    /**
     * Cocokkan permission dan role di RouterConfig dengan konfigurasi Shield.
     * Hanya di luar production (jalankan di test/CI agar typo ketahuan).
     */
    protected function validateAgainstShield(array $config, string $configFile): void
    {
        $authGroups = config('AuthGroups');

        if ($authGroups === null) {
            return;
        }

        $isProduction = ENVIRONMENT === 'production';

        $knownPermissions = array_keys($authGroups->permissions ?? []);
        $knownGroups      = array_keys($authGroups->groups ?? []);

        foreach ($config['requiredPermissions'] ?? [] as $endpoint => $permissions) {
            foreach ($permissions as $permission) {
                if (! in_array($permission, $knownPermissions, true)) {
                    $this->handleShieldMismatch(
                        "Permission '{$permission}' pada {$endpoint} tidak ada di AuthGroups::\$permissions: {$configFile}",
                        $isProduction
                    );
                }
            }
        }

        foreach ($config['requiredRoles'] ?? [] as $endpoint => $roles) {
            foreach ($roles as $role) {
                if (! in_array($role, $knownGroups, true)) {
                    $this->handleShieldMismatch(
                        "Role '{$role}' pada {$endpoint} tidak ada di AuthGroups::\$groups: {$configFile}",
                        $isProduction
                    );
                }
            }
        }
    }

    /**
     * Di production: log warning (tidak throw agar app tetap jalan).
     * Di luar production: throw agar typo ketahuan di CI/test.
     */
    private function handleShieldMismatch(string $message, bool $isProduction): void
    {
        if ($isProduction) {
            log_message('warning', 'DynamicRouter config mismatch: ' . $message);

            return;
        }

        throw new RuntimeException($message);
    }

    protected function getRouterConfigPath(): string
    {
        if ($this->moduleFolder === '') {
            return APPPATH . 'Config/RouterConfig.php';
        }

        $basePath = $this->resolveModuleBasePath();

        return $basePath . '/'
            . trim($this->moduleFolder, '/\\')
            . '/Config/RouterConfig.php';
    }

    private function resolveModuleBasePath(): string
    {
        if (! isset($this->modulePaths)) {
            throw new RuntimeException('DynamicRouterBase: $modulePaths belum diinisialisasi.');
        }

        $paths = $this->modulePaths->paths ?? null;
        $paths = is_array($paths) ? $paths : [$paths];

        $candidates = [];

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $normalized = $this->normalizePath($path);

            // Prioritas: path yang benar-benar memuat moduleFolder ini.
            if ($this->moduleFolder !== '' && is_dir($normalized . '/' . trim($this->moduleFolder, '/\\'))) {
                return $normalized;
            }
            $candidates[] = $normalized;
        }

        if ($candidates !== []) {
            return $candidates[0]; // fallback: path pertama valid
        }

        throw new RuntimeException(
            'Config\ModulePaths::$paths tidak berisi path valid. Diterima: '
            . json_encode($paths, JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
    }

    private function normalizePath(string $path): string
    {
        $path = rtrim(trim($path), '/\\');

        // Path kosong setelah trim: tidak valid.
        if ($path === '') {
            throw new RuntimeException(
                'Config\ModulePaths::$paths berisi path kosong.'
            );
        }

        // Path absolut Unix: /var/www/modules
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // Path absolut Windows: C:\laragon\www\modules
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        // Path UNC Windows: \\server\share\modules
        if (str_starts_with($path, '\\\\')) {
            return $path;
        }

        // Path relatif: resolve terhadap ROOTPATH.
        return rtrim(ROOTPATH, '/\\') . '/' . $path;
    }

    /** @deprecated Gunakan RouteRegistrySynchronizer::scanAllModules(). */
    public static function scanAllModules(): array
    {
        return RouteRegistrySynchronizer::scanAllModules();
    }

    /** @deprecated Gunakan RouteRegistrySynchronizer::syncRouteRegistry(). */
    public static function syncRouteRegistry(): int
    {
        return RouteRegistrySynchronizer::syncRouteRegistry();
    }
}
