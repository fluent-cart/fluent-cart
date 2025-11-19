<?php

namespace FluentCart\App\Http\Controllers\WebController;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\FrontendView;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Http\URL;
use FluentCart\Framework\Support\Arr;

class FileDownloader extends Controller
{
    /**
     * @param Request $request
     * @return void
     */
    public function index(Request $request)
    {
        $url = new URL();
        $validated = $url->validate($request->getFullUrl());
        $validTill = Arr::get($validated, 'valid_till');

        if (empty($validTill)) {
            $this->returnError();
        }

        try {
            $validTill = DateTime::anytimeToGmt($validTill);
        } catch (\Exception $e) {
            $this->returnError();
        }

        if ($validTill < DateTime::now()) {
            $this->returnError();
        }

        $downloadIdentifier = Arr::get($validated, 'download_identifier');
        $download = ProductDownload::query()->where('download_identifier', $downloadIdentifier)->first();

        if (empty($downloadIdentifier) || !$download) {
            $this->returnError();
        }

        $orderIds = Arr::get($validated, 'order_id', '');
        if ($orderIds) {
            $orderIds = json_decode($orderIds, true);
            if (!$orderIds) {
                $orderIds = [];
            }
        }

        if (!empty($orderIds) && is_array($orderIds)) {
            $orders = Order::query()
                ->where(function (Builder $query) {
                    $query->whereIn('payment_status', Status::getOrderPaymentSuccessStatuses());
                })
                ->whereHas('order_items', function ($query) use ($download) {
                    $query->where('post_id', $download->post_id);
                })
                ->with('subscriptions')
                ->whereIn('id', $orderIds)
                ->get();

            if ($orders->isEmpty()) {
                $this->returnError();
            }

            $canBeDownloaded = false;

            foreach ($orders as $order) {
                if ($canBeDownloaded) {
                    break;
                }

                if ($order->subscriptions && $order->subscriptions->isNotEmpty()) {
                    foreach ($order->subscriptions as $subscription) {
                        if ($subscription->hasAccessValidity()) {
                            $canBeDownloaded = true;
                            break;
                        }
                    }
                    continue;
                }

                $canBeDownloaded = true;
            }

            $canBeDownloaded = apply_filters('fluent_cart/product_download/can_be_downloaded', $canBeDownloaded, [
                'orders'   => $orders,
                'download' => $download
            ]);

            if (!$canBeDownloaded) {
                $this->returnError();
            }
        }


        if (is_array($validated) && !empty($validated['download_identifier'])) {
            $downloaded = \FluentCart\App\Services\FileSystem\DownloadService::downloadFileFromId($validated['download_identifier']);
            if(is_wp_error($downloaded)){

                if(is_user_logged_in() && current_user_can('manage_options')){

                    $settingsUrl = admin_url('admin.php?page=fluent-cart#/settings/storage/' . $download->driver);

                    FrontendView::renderNotFoundPage(
                        __('Driver Error', 'fluent-cart'),
                        __('Configuration Error', 'fluent-cart'),
                        sprintf(
                            /* translators: %s is the driver name */
                            __('The storage driver %s is not configured correctly.', 'fluent-cart'),
                            $download->driver
                        ),

                        __('Check Storage Settings', 'fluent-cart'),
                        '',
                        $settingsUrl
                    );
                }else{
                    FrontendView::renderFileNotFoundPage();
                }
            }
        } else {
            $this->returnError();
        }

        exit;
    }

    public function returnError()
    {
        if (App::request()->wantsJson()) {
            wp_send_json(['message' => __('Invalid download Url', 'fluent-cart')], 422);
        } else {
            FrontendView::renderFileNotFoundPage();
        }

        die();
    }

}
