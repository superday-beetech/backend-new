<?php

namespace Config;

use App\Libraries\SettingLibrary;
use App\Libraries\Uploader;
use App\Repositories\StorageFileRepository;
use App\Repositories\StorageRelationRepository;
use App\Services\AccessControlService;
use App\Services\RouteRegistryAccessService;
use CodeIgniter\Config\BaseService;
use App\Services\Storage\FileHandler;
use App\Services\Storage\ImageHandler;
use App\Services\Storage\StorageFacade;
use App\Services\Storage\StorageManager;
use App\Services\Storage\StorageMetadataService;
use App\Services\Storage\StorageService;
use App\Services\Storage\StorageValidationService;
use Modules\Core\Services\OrganizationContextService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
    public static function app_settings($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('app_settings');
        }

        return new SettingLibrary();
    }


    public static function routeRegistryAccess(
        bool $getShared = true
    ): RouteRegistryAccessService {
        if ($getShared) {
            return static::getSharedInstance('routeRegistryAccess');
        }

        return new RouteRegistryAccessService(
            null,
            static::accessControl()
        );
    }

    public static function accessControl(
        bool $getShared = true
    ): AccessControlService {
        if ($getShared) {
            return static::getSharedInstance('accessControl');
        }

        return new AccessControlService();
    }

    /** Shared active organization context for all ERP modules. */
    public static function organizationContext(
        bool $getShared = true
    ): OrganizationContextService {
        if ($getShared) {
            return static::getSharedInstance('organizationContext');
        }

        return new OrganizationContextService();
    }

    /* public static function storage(bool $getShared = true): StorageFacade
    {
        if ($getShared) {
            return static::getSharedInstance('storage');
        }

        return new StorageFacade();
    }


    public static function fileHandler(bool $getShared = true): FileHandler
    {
        if ($getShared) {
            return static::getSharedInstance('fileHandler');
        }

        return new FileHandler();
    }

    public static function imageHandler(bool $getShared = true): ImageHandler
    {
        if ($getShared) {
            return static::getSharedInstance('imageHandler');
        }

        return new ImageHandler();
    } */

    public static function storageManager(bool $getShared = true): StorageManager
    {
        if ($getShared) {
            return static::getSharedInstance('storageManager');
        }

        return new StorageManager(env('storage.defaultDriver', 'local'));
    }

    public static function storageService(bool $getShared = true): StorageService
    {
        if ($getShared) {
            return static::getSharedInstance('storageService');
        }

        return new StorageService(
            static::storageManager(),
            new StorageValidationService()
        );
    }

    /** Dipakai oleh FileHandler, ImageHandler, dan service('storage') di mana pun. */
    public static function storage(bool $getShared = true): StorageFacade
    {
        if ($getShared) {
            return static::getSharedInstance('storage');
        }

        $files = new StorageFileRepository();

        return new StorageFacade(
            new StorageMetadataService(static::storageService(), $files),
            static::storageService(),
            new StorageRelationRepository(),
            $files
        );
    }

    public static function fileHandler(bool $getShared = true): FileHandler
    {
        return $getShared
            ? static::getSharedInstance('fileHandler')
            : new FileHandler(static::storage());
    }

    public static function imageHandler(bool $getShared = true): ImageHandler
    {
        return $getShared
            ? static::getSharedInstance('imageHandler')
            : new ImageHandler(static::storage());
    }

    public static function uploader(bool $getShared = true): Uploader
    {
        return $getShared
            ? static::getSharedInstance('uploader')
            : new Uploader();
    }
}
