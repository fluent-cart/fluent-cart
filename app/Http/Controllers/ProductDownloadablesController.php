<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductDownloadResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Requests\ProductDownloadable\ProductDownloadableBulkFileRequest;
use FluentCart\App\Http\Requests\ProductDownloadable\ProductDownloadableFileRequest;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Http\URL as BaseUrl;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Validator\Validator;

class ProductDownloadablesController extends Controller
{
    public function syncDownloadableFiles(Request $request): \WP_REST_Response
    {
        $productDownloadableBulkFileRequest = new ProductDownloadableBulkFileRequest();
        $validator = Validator::make($request->all(), $productDownloadableBulkFileRequest->rules());
        $validationErrors = [];


        if ($validator->fails()) {
            $validationErrors = $validator->errors();
        }


        if (count($validationErrors) !== 0) {
            return $this->sendError($validationErrors);
        }

        $data = $request->get('downloadable_files');
        $sanitizedData = [];

        foreach ($data as $downloadableFile) {

            $variationIds = Arr::get($downloadableFile, 'product_variation_id');
            if (is_array($variationIds)) {
                $variationIds = array_map('intval', $variationIds);
            } else {
                $variationIds = '';
            }

            $sanitizedData[] = [
                'id'                   => intval(Arr::get($downloadableFile, 'id')),
                'post_id'              => intval(Arr::get($downloadableFile, 'post_id')),
                'product_variation_id' => $variationIds,
                'title'                => sanitize_text_field(Arr::get($downloadableFile, 'title')),
                'type'                 => sanitize_text_field(Arr::get($downloadableFile, 'type')),
                'driver'               => sanitize_text_field(Arr::get($downloadableFile, 'driver')),
                'bucket'               => sanitize_text_field(Arr::get($downloadableFile, 'bucket')),
                'file_name'            => sanitize_text_field(Arr::get($downloadableFile, 'file_name')),
                'file_path'            => sanitize_text_field(Arr::get($downloadableFile, 'file_path')),
                'file_url'             => sanitize_text_field(Arr::get($downloadableFile, 'file_url')),
                'settings'             => Arr::get($downloadableFile, 'settings'),
                'serial'               => sanitize_text_field(Arr::get($downloadableFile, 'serial')),
                'file_size'            => sanitize_text_field(Arr::get($downloadableFile, 'file_size')),
            ];
        }


        $fileData['downloadable_files'] = $sanitizedData;


        unset($fileData['downloadable_files']['*']);

        $productId = $request->getSafe('postId', 'intval');

        foreach ($fileData['downloadable_files'] as &$file) {

            $file['download_identifier'] = Str::uuid();
            $file['post_id'] = $productId;
            $file['file_path'] = $file['file_name'];
            $file['file_url'] = $file['file_name'];
            $file['product_variation_id'] = json_encode(
                Arr::get($file, 'product_variation_id', [])
            );

            $fileName = $file['file_name'];
            $fileName = explode('_____fluent-cart_____', $fileName)[0];
            $fileName = explode('__fluent-cart__', $fileName)[0];
            $file['file_name'] = $fileName;

            $file['settings'] = json_encode(
                Arr::get($file, 'settings', [])
            );

            unset($file['id']);
            unset($file['bucket']);
        }


        ProductDetail::query()->where('post_id', $productId)->update([
            'manage_downloadable' => 1
        ]);


        $isCreated = ProductDownload::query()->insert($fileData['downloadable_files']);
        if ($isCreated) {
            return $this->sendSuccess([
                'downloadable_files' => ProductDownloadResource::search(['post_id' => $productId])
            ]);
        } else {
            return $this->sendError([
                'message' => __('Failed to attach downloadable files', 'fluent-cart')
            ]);
        }
    }

    public function getDownloadableUrl($downloadableId)
    {
        $productDownload = ProductDownload::query()->findOrFail($downloadableId);


        if (empty($productDownload)) {
            return $this->sendError([
                'message' => __('Failed to generate downloadable link', 'fluent-cart')
            ]);
        }


        return $this->sendSuccess([
            'url' => Helper::generateDownloadFileLink($productDownload, null, 60 * 60 * 24 * 7, true)
        ]);


    }

    public function update(ProductDownloadableFileRequest $request, $downloadId)
    {
        $productDownload = ProductDownload::query()->findOrFail($downloadId);
        $data = $request->getSafe($request->sanitize());

        $fileName = Arr::get($data, 'file_name');

        $filePath = $fileName;
        $fileUrl = $fileName;
        $productVariationId = Arr::get($data, 'product_variation_id', []);
        $fileName = explode('_____fluent-cart_____', $fileName)[0];
        $fileName = explode('__fluent-cart__', $fileName)[0];



        if (is_array($productVariationId)) {
            $productVariationId = array_map('intval', $productVariationId);
        } else {
            $productVariationId = '';
        }

        $productDownload->product_variation_id = $productVariationId;
        $productDownload->title = Arr::get($data, 'title');
        $productDownload->type = Arr::get($data, 'type');
        $productDownload->driver = Arr::get($data, 'driver');
        $productDownload->file_name = $fileName;
        $productDownload->file_path = $filePath;
        $productDownload->file_url = $fileUrl;
        $productDownload->settings = Arr::get($data, 'settings');
        $productDownload->serial = Arr::get($data, 'serial');

        if ($productDownload->save()) {
            return $this->sendSuccess([
                'message' => __('Product downloadable files updated successfully', 'fluent-cart')
            ]);
        } else {
            return $this->sendError([
                'message' => __('Failed to update product downloadable files', 'fluent-cart')
            ]);
        }

    }

    public function delete($downloadableFileId)
    {
        $response = ProductDownloadResource::delete($downloadableFileId);
        if (is_wp_error($response)) {
            return $this->sendError([
                'message' => $response->get_error_message()
            ]);
        }
        return $this->sendSuccess([
            'message' => $response['message']
        ]);
    }

    public function updateDownloadableFile(Request $request, Product $product, ProductDownload $productDownload)
    {

    }
}
