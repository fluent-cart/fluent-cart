<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\EditorShortCodeHelper;
use FluentCart\App\Http\Requests\EmailNotificationRequest;
use FluentCart\App\Http\Requests\EmailSettingsRequest;
use FluentCart\App\Http\Requests\SchedulingSettingsRequest;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\PDF\ReceiptPdfTemplateService;
use FluentCart\App\Services\Reminders\ReminderService;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\App\Services\Email\EmailPreviewService;
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
            $hasFluentPdf = defined('FLUENT_PDF');
            $fontsReady = false;
            $setupUrl = '';

            if ($hasFluentPdf && class_exists('FluentPdf\Classes\Controller\FontDownloader')) {
                $fontDownloader = new \FluentPdf\Classes\Controller\FontDownloader();
                $fontsReady = empty($fontDownloader->getDownloadableFonts());
                $setupUrl = admin_url('admin.php?page=fluent_pdf.php');
            }

            $pdfTemplates = $hasFluentPdf ? (new ReceiptPdfTemplateService())->getTemplateList() : [];

            return $this->sendSuccess([
                'data'           => $notification,
                'shortcodes'     => EditorShortCodeHelper::getEmailNotificationShortcodes(),
                'has_fluent_pdf' => $hasFluentPdf,
                'fonts_ready'    => $fontsReady,
                'setup_url'      => $setupUrl,
                'pdf_templates'  => $pdfTemplates,
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

        // Strip custom template data in free; pro filter restores it
        $settingsWithoutTemplate = $settings;
        unset($settingsWithoutTemplate['email_body']);
        $settingsWithoutTemplate['is_default_body'] = 'yes';

        $settings = apply_filters('fluent_cart/prepare_email_template_data', $settingsWithoutTemplate, $settings);

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

    public function previewDefaultTemplate(Request $request)
    {
        $template = sanitize_text_field($request->get('template'));
        $previewService = new EmailPreviewService();
        $data = $previewService->getPreviewData($template);

        $body = TemplateService::getTemplateByPathName($template, $data);

        // Wrap in the same outer email template used by actual emails
        $header = (string) App::make('view')->make('emails.parts.order_header', $data);
        $mailer = new EmailNotificationMailer();
        $view = (string) App::make('view')->make('emails.general_template', [
            'emailBody'   => $body,
            'preheader'   => '',
            'header'      => $header,
            'emailFooter' => $mailer->getEmailFooter(),
        ]);

        // Resolve shortcodes (e.g., {{ settings.store_brand }}, {{order.created_at}})
        $view = ShortcodeTemplateBuilder::make($view, $data);

        // Disable all links/buttons in the preview so they are not clickable
        $view .= '<style>a, button, [role="button"] { pointer-events: none !important; cursor: default !important; }</style>';

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
            'shortcodes' => EditorShortCodeHelper::getEmailSettingsShortcodes(),
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

    public function getSchedulingSettings(StoreSettings $storeSettings): array
    {
        $tab = 'reminders';
        $currentTabFields = Arr::get($storeSettings->fields(), 'setting_tabs.schema.' . $tab);

        return [
            'settings' => $storeSettings->get(),
            'fields'   => [
                $tab => $currentTabFields
            ]
        ];
    }

    public function saveSchedulingSettings(SchedulingSettingsRequest $request, StoreSettings $storeSettings): \WP_REST_Response
    {
        try {
            $sanitizedData = $request->getSafe($request->sanitize());

            $data = array_merge(
                $storeSettings->get(),
                $sanitizedData
            );

            $updated = $storeSettings->save($data);

            return $this->sendSuccess([
                'data'    => $updated,
                'message' => __('Scheduling settings saved successfully', 'fluent-cart')
            ]);
        } catch (\Throwable $e) {
            return $this->sendError([
                'message' => __('Failed to save scheduling settings', 'fluent-cart')
            ], 423);
        }
    }

    public function sendManualReminder(Request $request): \WP_REST_Response
    {
        $event = sanitize_text_field(Arr::get($request->all(), 'event', ''));
        $entityId = absint(Arr::get($request->all(), 'entity_id', 0));

        if (empty($event) || empty($entityId)) {
            return $this->sendError([
                'message' => __('Event type and entity ID are required', 'fluent-cart')
            ]);
        }

        $result = (new ReminderService())->sendManualReminder($event, $entityId);

        if ($result['success']) {
            return $this->sendSuccess([
                'message' => $result['message']
            ]);
        }

        return $this->sendError([
            'message' => $result['message']
        ]);
    }
}
