<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\StorageDrivers;
use FluentCart\App\Services\FileSystem\FileManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Hooks\Handlers\GlobalStorageHandler;
use FluentCart\Framework\Support\Arr;

class StorageController extends Controller
{
    public function index(Request $request, GlobalStorageHandler $globalHandler)
    {
        return ['drivers' => $globalHandler->getAll()];
    }

    public function store(Request $request)
    {
        $data = $request->settings;
        $driver = sanitize_text_field($request->driver);
        
        $driver = (new FileManager($driver,null, null, true))->getDriver();
        if (empty($driver)) {
            return $this->sendError([
                'message' => __('Invalid driver', 'fluent-cart')
            ], 404);
        }

        $data = $driver->getStorageDriver()->saveSettings($data);

        $message = Arr::get($data, 'message', __('Settings saved successfully', 'fluent-cart'));

        if (is_wp_error($data)) {
            return $this->sendError([
                'message'    => $data->get_error_message(),
                'error_data' => $data->get_error_data()
            ], 400);
        } else {
            return $this->sendSuccess([
                'message' => $message,
                'data'    => $data
            ]);
        }
    }

    public function getSettings(Request $request, $driver, GlobalStorageHandler $globalHandler)
    {
        return $globalHandler->getSettings(sanitize_text_field($driver));
    }

    public function getStatus(Request $request, GlobalStorageHandler $globalHandler)
    {
        return $globalHandler->getStatus(sanitize_text_field($request->driver));
    }

    public function getActiveDrivers(Request $request, GlobalStorageHandler $globalHandler)
    {
        return ['drivers' => $globalHandler->getAllActive()];
    }

    public function verifyConnectInfo(Request $request, GlobalStorageHandler $globalHandler)
    {
        $data = $request->settings;
        $driver = sanitize_text_field($request->get('driver'));
        $storageDriver = $this->resolveStorageDriver($driver);
        if (is_wp_error($storageDriver)) {
            return $this->sendError([
                'message'    => $storageDriver->get_error_message(),
                'error_data' => $storageDriver->get_error_data()
            ], 404);
        }

        $isVerified = $storageDriver->verifyConnectInfo($data);
        if (is_wp_error($isVerified)) {
            return $this->sendError([
                'message'    => $isVerified->get_error_message(),
                'error_data' => $isVerified->get_error_data()
            ], 400);
        } else {
            return $this->sendSuccess([
                'message' => Arr::get($isVerified, 'message', __('Connection verified successfully', 'fluent-cart'))
            ]);
        }
    }

    public function createBucket(Request $request)
    {
        $data = (array) $request->settings;
        $driver = sanitize_text_field($request->driver);

        $storageDriver = $this->resolveBucketActionDriver($driver, 'createBucket');
        if (is_wp_error($storageDriver)) {
            return $this->sendError([
                'message'    => $storageDriver->get_error_message(),
                'error_data' => $storageDriver->get_error_data()
            ], 400);
        }

        $result = $storageDriver->createBucket($data);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message'    => $result->get_error_message(),
                'error_data' => $result->get_error_data()
            ], 400);
        }

        return $this->sendSuccess([
            'message' => Arr::get($result, 'message', __('Bucket created successfully', 'fluent-cart')),
            'data'    => $result
        ]);
    }

    public function listBuckets(Request $request)
    {
        $data = (array) $request->settings;
        $driver = sanitize_text_field($request->driver);
        $query = sanitize_text_field($request->get('query', ''));

        $storageDriver = $this->resolveBucketActionDriver($driver, 'listBuckets');
        if (is_wp_error($storageDriver)) {
            return $this->sendError([
                'message'    => $storageDriver->get_error_message(),
                'error_data' => $storageDriver->get_error_data()
            ], 400);
        }

        $result = $storageDriver->listBuckets($data, [
            'query' => $query
        ]);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message'    => $result->get_error_message(),
                'error_data' => $result->get_error_data()
            ], 400);
        }

        return $this->sendSuccess([
            'options' => is_array($result) ? $result : []
        ]);
    }

    public function resetSettings(Request $request)
    {
        $driver = sanitize_text_field($request->driver);
        $storageDriver = $this->resolveStorageDriver($driver);

        if (is_wp_error($storageDriver)) {
            return $this->sendError([
                'message'    => $storageDriver->get_error_message(),
                'error_data' => $storageDriver->get_error_data()
            ], 400);
        }

        $result = $storageDriver->resetSettings();

        if (is_wp_error($result)) {
            return $this->sendError([
                'message'    => $result->get_error_message(),
                'error_data' => $result->get_error_data()
            ], 400);
        }

        $responseData = is_array($result)
            ? Arr::except($result, $storageDriver->hiddenSettingKeys())
            : $result;

        return $this->sendSuccess([
            'message' => __('Settings reset successfully', 'fluent-cart'),
            'data'    => $responseData
        ]);
    }

    public function changeStatus(Request $request)
    {
        $driver = sanitize_text_field($request->driver);
        $status = sanitize_text_field($request->status);

        if (!in_array($status, ['yes', 'no'], true)) {
            return $this->sendError([
                'message' => __('Invalid status value', 'fluent-cart')
            ], 422);
        }

        $driver = (new FileManager($driver, null, null, true))->getDriver();
        if (empty($driver)) {
            return $this->sendError([
                'message' => __('Invalid driver', 'fluent-cart')
            ], 404);
        }

        $storageDriver = $driver->getStorageDriver();

        if ($storageDriver->hasBucket()) {
            return $this->sendError([
                'message' => __('This storage driver must be managed from its settings page.', 'fluent-cart')
            ], 400);
        }

        $settings = (array) $storageDriver->getSettings();
        $settings['is_active'] = $status;

        $result = $storageDriver->saveSettings($settings);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ], 400);
        }

        return $this->sendSuccess([
            'message' => Arr::get($result, 'message', __('Status updated successfully', 'fluent-cart')),
            'data'    => $result
        ]);
    }

    private function resolveBucketActionDriver(string $driver, string $method)
    {
        $driverInstance = $this->resolveDriverInstance($driver);
        if (is_wp_error($driverInstance)) {
            return $driverInstance;
        }

        $storageDriver = $driverInstance->getStorageDriver();
        if (!method_exists($storageDriver, $method)) {
            return new \WP_Error(
                'unsupported_bucket_api',
                __('The selected storage driver does not support bucket management.', 'fluent-cart')
            );
        }

        return $storageDriver;
    }

    private function resolveStorageDriver(string $driver)
    {
        $driverInstance = $this->resolveDriverInstance($driver);
        if (is_wp_error($driverInstance)) {
            return $driverInstance;
        }

        return $driverInstance->getStorageDriver();
    }

    private function resolveDriverInstance(string $driver)
    {
        try {
            return (new FileManager($driver, null, null, true))->getDriver();
        } catch (\Throwable $e) {
            return new \WP_Error('invalid_driver', __('Invalid driver', 'fluent-cart'));
        }
    }
}
