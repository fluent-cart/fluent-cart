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
                'message' => $data->get_error_message()
            ], 401);
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
        $driver = (new FileManager($driver))->getDriver();
        if (empty($driver)) {
            return $this->sendError([
                'message' => __('Invalid driver', 'fluent-cart')
            ], 404);
        }
        $isVerified = $driver->getStorageDriver()->verifyConnectInfo($data);
        if (is_wp_error($isVerified)) {
            return $this->sendError([
                'message' => $isVerified->get_error_message()
            ], 401);
        } else {
            return $this->sendSuccess([
                'message' => Arr::get($isVerified, 'message', __('Connection verified successfully', 'fluent-cart'))
            ]);
        }
    }
}
