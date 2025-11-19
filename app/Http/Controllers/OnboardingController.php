<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Invokable\DummyProduct;
use FluentCart\Api\StoreSettings;
use FluentCart\App\CPT\Pages;
use FluentCart\App\Helpers\Helper as HelperService;
use FluentCart\App\Http\Requests\CreatePageRequest;
use FluentCart\Framework\Foundation\Async;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class OnboardingController extends Controller
{
    public function index(Request $request): \WP_REST_Response
    {
        $defaultSettings = (new StoreSettings())->toArray();

        $pages = new Pages();

        foreach ($pages->getGeneratablePage(true) as $pageName => $page) {

            if (empty(Arr::get($defaultSettings, "{$pageName}_page_id"))) {
                Arr::set(
                    $defaultSettings,
                    "{$pageName}_page_id",
                    Arr::get($page, 'page_id')
                );
            }

        }

        return $this->response->sendSuccess([
            'pages'            => Pages::getPages('', true),
            'currencies'       => CurrencySettings::getFormattedCurrencies(),
            'default_settings' => $defaultSettings
        ]);
    }

    public function createPages(Request $request): \WP_REST_Response
    {
        $excluded = [];
        $pages = new Pages();
        $storeSettings = new StoreSettings();

        foreach ($pages->getGeneratablePage() as $pageName => $page) {
            $pageId = $storeSettings->get("{$pageName}_page_id");
            if (!empty($pageId) && !Pages::isPage($pageId)) {
                $excluded[] = $pageName;
            }
        }

        $pages->createPages($excluded);

        return $this->index($request);
    }

    public function createPage(CreatePageRequest $request): \WP_REST_Response
    {
        $content = sanitize_text_field($request->get('content'));
        $pageKey = $content;
        $content = Str::of($content)->replaceFirst('_page_id', '')->toString();
        $saveSettings = filter_var($request->get('save_settings'), FILTER_VALIDATE_BOOLEAN);

        $generateablePages = (new Pages())->getGeneratablePage();
        $pageData = Arr::get($generateablePages, $content, null);

        if (!empty($pageData)) {
            $page = [
                'post_type'    => 'page',
                'post_title'   => sanitize_text_field($request->get('page_name')),
                'post_content' => $pageData['content'],
                'post_status'  => 'publish'
            ];

            $pageId = (string)wp_insert_post($page);

            if ($saveSettings) {
                (new StoreSettings())->save([
                    $pageKey => $pageId
                ]);

                flush_rewrite_rules(true);
                delete_option('rewrite_rules');
//                if ($content === 'customer_profile') {
//                    flush_rewrite_rules(true);
//                    delete_option('rewrite_rules');
//                }
            }

            return $this->response->sendSuccess([
                'page_id'   => $pageId,
                'page_name' => $pageData['title'],
                'link'      => get_page_link($pageId)
            ]);
        }

        return $this->response->sendError([
            'message' => __('Unable to create page', 'fluent-cart')
        ]);
    }

    public function saveSettings(Request $request)
    {
        $settings = array_merge((new StoreSettings())->toArray(), $request->all());

        $savedStoreSettings = (new StoreSettings())->save(
            Arr::except($settings, 'category')
        );

        if ($category = $request->get('category')) {
            Async::call(DummyProduct::class, ['category' => $category]);
        }

        if ($savedStoreSettings) {
            return $this->response->sendSuccess([
                'message' => __('Store has been updated successfully', 'fluent-cart')
            ]);
        }

        return $this->response->sendError([
            'errors' => __('Failed to update!', 'fluent-cart')
        ], 400);
    }
}
