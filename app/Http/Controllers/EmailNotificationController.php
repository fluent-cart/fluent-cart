<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Helpers\EditorShortCodeHelper;
use FluentCart\App\Http\Requests\EmailNotificationRequest;
use FluentCart\App\Http\Requests\EmailSettingsRequest;
use FluentCart\App\Models\Meta;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class EmailNotificationController extends Controller
{
    public function index(Request $request): \WP_REST_Response
    {

        $getNotifications = EmailNotifications::getNotifications();

        if ($getNotifications) {
            return $this->sendSuccess([
                'data' => $getNotifications
            ]);
        }
        return $this->sendError([
            'data' => []
        ]);
    }

    public function find($notification): \WP_REST_Response
    {
        $name = sanitize_text_field($notification);
        $notification = EmailNotifications::getNotification($name);


        if ($notification) {
            return $this->sendSuccess([
                'data'       => $notification,
                'shortcodes' => EditorShortCodeHelper::getEmailNotificationShortcodes(),
            ]);
        }

        return $this->sendError([
            'message' => __('Notification Details not found', 'fluent-cart')
        ]);
    }

    public function update(EmailNotificationRequest $request, $notification): \WP_REST_Response
    {
        $data = $request->getSafe($request->sanitize());

        $settings = Arr::get($data, 'settings', []);

        $updated = EmailNotifications::updateNotification($notification, $settings);
        if ($updated) {
            return $this->sendSuccess([
                'message' => __('Notification updated successfully', 'fluent-cart')
            ]);
        } else {
            return $this->sendError([
                'message' => __('Failed to update notification', 'fluent-cart')
            ]);
        }

    }

    public function enableNotification(Request $request, $name): \WP_REST_Response
    {
        $enabledValue = sanitize_text_field(Arr::get($request->all(), 'active'));

        $notification = EmailNotifications::updateNotification(
            $name,
            ['active' => $enabledValue]
        );

        if ($notification) {
            return $this->sendSuccess([
                'message' => __('Notification updated successfully', 'fluent-cart')
            ]);
        }
        return $this->sendError([
            'message' => __('Failed to update notification', 'fluent-cart')
        ]);

    }

    public function getShortCodes(Request $request): \WP_REST_Response
    {
        return $this->sendSuccess([
            'data' => [
                'email_templates' => $this->getTemplateFiles(),
                'shortcodes'      => EditorShortCodeHelper::getEmailNotificationShortcodes(),
                'buttons'         => EditorShortCodeHelper::getButtons()
            ],
        ]);
    }

    public function getTemplate(Request $request)
    {
        $template = $request->get('template');
        $view = TemplateService::getTemplateByPathName($template);
        return $this->sendSuccess([
            'data' => [
                'content' => $view
            ],
        ]);

    }

    public function getTemplateFiles()
    {
        $defaultFilePath = FLUENTCART_PLUGIN_PATH . '/app/Views/emails';
        $filesArray = [];
        $files = scandir($defaultFilePath);
        foreach ($files as $file) {
            $filePath = $defaultFilePath . '/' . $file;
            if (is_file($filePath)) {
                $file = Str::of($file)->replace('.php', '');
                $filesArray[] = [
                    'path'  => $file,
                    'label' => Str::of($file)->replace('fluent_cart', '')->headline()
                ];
            }
        }
        return $filesArray;
    }

    public function getSettings(): \WP_REST_Response
    {
        return $this->sendSuccess([
            'data'       => EmailNotifications::getSettings(),
            'shortcodes' => EditorShortCodeHelper::getEmailSettingsShortcodes()
        ]);
    }

    public function saveSettings(EmailSettingsRequest $request): \WP_REST_Response
    {
        $data = $request->getSafe($request->sanitize());

        if (!App::isProActive()) {
            $data['show_email_footer'] = 'yes';
        }

        $updated = EmailNotifications::updateSettings($data);

        if ($updated) {
            return $this->sendSuccess([
                'message' => __('Email settings saved successfully', 'fluent-cart')
            ]);
        } else {
            return $this->sendError([
                'message' => __('Failed to save email settings', 'fluent-cart')
            ]);
        }
    }

}
