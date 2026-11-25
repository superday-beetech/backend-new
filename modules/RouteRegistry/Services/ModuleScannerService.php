<?php

declare(strict_types=1);

namespace Modules\RouteRegistry\Services;

use Config\ModulePaths;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

class ModuleScannerService
{
    /** @var ModulePaths */
    protected ModulePaths $modulePaths;

    /**
     * Controller yang tidak boleh dipindai.
     *
     * @var list<string>
     */
    private array $ignoredControllers = [
        'MagicController',
        'ApiRouter',
        'DynamicRouter',
        'BaseController',
    ];

    /**
     * Module yang tidak boleh dipindai.
     *
     * @var list<string>
     */
    private array $ignoredModules = [
        // 'RouteRegistry',
        // 'Auth',
    ];

    public function __construct()
    {
        $this->modulePaths = config(ModulePaths::class);
    }

    /**
     * Scan seluruh module tanpa mengubah database.
     *
     * @return list<array{
     *     module: string,
     *     controller: string,
     *     method: string,
     *     endpoint: string,
     *     namespace: string,
     *     permission: string,
     *     url_route: string|null,
     *     has_interface: bool
     * }>
     */
    public function scan(): array
    {
        $basePath = $this->resolveModuleBasePath();
        $modulesPath = $basePath . '/';

        if (! is_dir($modulesPath)) {
            return [];
        }

        $results = [];

        foreach (new \DirectoryIterator($modulesPath) as $moduleDir) {
            if ($moduleDir->isDot() || ! $moduleDir->isDir()) {
                continue;
            }

            $module = $moduleDir->getFilename();

            if (in_array($module, $this->ignoredModules, true)) {
                continue;
            }

            $controllerPath = $moduleDir->getPathname()
                . DIRECTORY_SEPARATOR . 'Controllers';

            if (! is_dir($controllerPath)) {
                continue;
            }

            $namespace = 'Modules\\' . $module . '\\Controllers';

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $controllerPath,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (
                    ! $file->isFile()
                    || strtolower($file->getExtension()) !== 'php'
                ) {
                    continue;
                }

                $controllerName = $file->getBasename('.php');

                if (in_array($controllerName, $this->ignoredControllers, true)) {
                    continue;
                }

                $relativePath = substr(
                    $file->getPathname(),
                    strlen($controllerPath) + 1
                );

                $relativeClass = str_replace(
                    DIRECTORY_SEPARATOR,
                    '\\',
                    substr($relativePath, 0, -4)
                );

                $className = $namespace . '\\' . $relativeClass;

                if (! class_exists($className)) {
                    continue;
                }

                try {
                    $reflection = new ReflectionClass($className);
                } catch (ReflectionException) {
                    continue;
                }

                if (
                    ! $reflection->isInstantiable()
                    || $reflection->isAbstract()
                ) {
                    continue;
                }

                foreach (
                    $reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method
                ) {
                    if (
                        $method->getDeclaringClass()->getName()
                        !== $reflection->getName()
                        || $method->isStatic()
                        || $method->isConstructor()
                        || $method->isDestructor()
                        || str_starts_with($method->getName(), '_')
                    ) {
                        continue;
                    }

                    $controller = $reflection->getShortName();
                    $methodName = $method->getName();
                    $renderInfo = $this->detectRender($method);

                    $moduleKey = strtolower($module);

                    $controllerKey = strtolower(
                        preg_replace('/Controller$/i', '', $controller)
                    );

                    $moduleControllerKey = strtolower(
                        preg_replace('/Controller$/i', '', $module)
                    );

                    $parts = [$moduleKey];

                    if ($controllerKey !== $moduleControllerKey) {
                        $parts[] = $controllerKey;
                    }

                    $parts[] = $this->normalizePermissionAction($methodName);

                    $permission = implode('.', $parts);

                    $menuUrl = $renderInfo['has_interface']
                        ? $this->buildMenuUrl([
                            'module'     => $module,
                            'controller' => $controller,
                            'method'     => $methodName,
                        ])
                        : null;

                    $results[] = [
                        'module'        => $module,
                        'controller'    => $controller,
                        'method'        => $methodName,
                        'endpoint'      => $controller . '::' . $methodName,
                        'namespace'     => $className,
                        'permission'    => $permission,
                        'url_route'     => $menuUrl,
                        'has_interface' => $renderInfo['has_interface'],
                    ];
                }
            }
        }

        usort(
            $results,
            static fn (array $a, array $b): int => [
                $a['module'],
                $a['controller'],
                $a['method'],
            ] <=> [
                $b['module'],
                $b['controller'],
                $b['method'],
            ]
        );

        return $results;
    }

    private function resolveModuleBasePath(): string
    {
        $paths = $this->modulePaths->paths;

        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (is_string($path) && trim($path) !== '') {
                    return $this->normalizePath($path);
                }
            }

            throw new RuntimeException(
                'Config\ModulePaths::$paths tidak berisi path valid.'
            );
        }

        if (! is_string($paths) || trim($paths) === '') {
            throw new RuntimeException(
                'Config\ModulePaths::$paths harus string atau array path.'
            );
        }

        return $this->normalizePath($paths);
    }

    private function normalizePath(string $path): string
    {
        $path = rtrim(trim($path), '/\\');

        if (
            str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return rtrim(ROOTPATH, '/\\') . '/' . $path;
    }

    /**
     * @return array{has_interface: bool}
     */
    private function detectRender(ReflectionMethod $method): array
    {
        $filePath = $method->getFileName();

        if ($filePath === false || ! is_file($filePath)) {
            return ['has_interface' => false];
        }

        $lines = file($filePath);

        if ($lines === false) {
            return ['has_interface' => false];
        }

        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $tokens = token_get_all('<?php ' . $source);
        $total  = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            if (
                ! is_array($tokens[$i])
                || $tokens[$i][0] !== T_VARIABLE
                || $tokens[$i][1] !== '$this'
            ) {
                continue;
            }

            $j = $i + 1;

            while (
                $j < $total
                && is_array($tokens[$j])
                && in_array($tokens[$j][0], [
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                ], true)
            ) {
                $j++;
            }

            if (
                $j >= $total
                || ! is_string($tokens[$j])
                || $tokens[$j] !== '->'
            ) {
                continue;
            }

            $j++;

            while (
                $j < $total
                && is_array($tokens[$j])
                && in_array($tokens[$j][0], [
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                ], true)
            ) {
                $j++;
            }

            if (
                $j < $total
                && is_array($tokens[$j])
                && $tokens[$j][0] === T_STRING
                && in_array(strtolower($tokens[$j][1]), [
                    'render',
                    'view',
                ], true)
            ) {
                return ['has_interface' => true];
            }
        }

        return ['has_interface' => false];
    }

    private function normalizePermissionAction(string $method): string
    {
        // Pisahkan camelCase: updateStatus -> update Status
        $method = strtolower(
            preg_replace('/(?<!^)[A-Z]/', ' $0', $method)
        );

        $words = preg_split('/\s+/', trim($method));

        if ($this->containsAny($words, ['view', 'index', 'show', 'detail', 'dashboard', 'edit'])) {
            return 'view';
        }

        if ($this->containsAny($words, ['delete', 'destroy', 'remove'])) {
            return 'delete';
        }

        if ($this->containsAny($words, ['create', 'store'])) {
            return 'create';
        }

        if ($this->containsAny($words, ['update', 'action', 'save'])) {
            return 'update';
        }

        return implode('', $words);
    }

    /**
     * @param list<string> $words
     * @param list<string> $keywords
     */
    private function containsAny(array $words, array $keywords): bool
    {
        return count(array_intersect($words, $keywords)) > 0;
    }

    /**
     * Bentuk URL menu dari metadata endpoint hasil scan.
     *
     * @param array{module?: string, controller?: string, method?: string} $row
     */
    private function buildMenuUrl(array $row): ?string
    {
        $toSlug = static function (string $value): string {
            $value = trim($value);
            $value = preg_replace('/Controller$/i', '', $value);
            $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value);

            return strtolower($value);
        };

        $module     = $toSlug((string) ($row['module'] ?? ''));
        $controller = $toSlug((string) ($row['controller'] ?? ''));
        $method     = strtolower(trim((string) ($row['method'] ?? '')));

        if ($module === '' || $controller === '') {
            return null;
        }

        if ($module === $controller) {
            return $method === 'index' ? $module : $module . '/' . $method;
        }

        return $method === 'index'
            ? $module . '/' . $controller
            : $module . '/' . $controller . '/' . $method;
    }
}
