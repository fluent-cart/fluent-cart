<?php
/**
 * Download link lifetimes (FluentCart board 23939).
 *
 * Purchase emails say "This download link is valid for 7 days", but the links
 * expired after 60 minutes, so a buyer who opened the email later got the
 * file-not-found page. Separately, the S3 driver ran the
 * fluent_cart/download_expiration_minutes filter and threw the result away.
 */

use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Services\FileSystem\Drivers\S3\S3Driver;
use FluentCart\Framework\Http\URL;

if (!class_exists('FcDownloadValidityS3Driver')) {
    // Records the lifetime the driver asks for; signing would look up the bucket region over HTTP
    class FcDownloadValidityS3Driver extends S3Driver
    {
        public $requestedMinutes = null;

        protected function generatePresignedDownloadUrl(string $filePath, $expirationMinutes = 15, $bucket = null, $fileName = null): string
        {
            $this->requestedMinutes = $expirationMinutes;
            return '';
        }
    }
}

$minutesLeft = static function ($signedUrl) {
    $validated = (new URL())->validate($signedUrl);
    FcTest::assert(is_array($validated) && !empty($validated['valid_till']), 'the download link carries a valid_till');

    return (int) round(((int) $validated['valid_till'] - time()) / 60);
};

return [
    [
        'id'            => 'download-link-validity-email-scope',
        'name'          => 'Purchase email download links last the 7 days the email promises; account links keep 60 minutes',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($minutesLeft) {
            $downloadId = 0;
            try {
                $product = FcDomainFixture::product(['payment_type' => 'onetime']);
                $productId = (int) $product['post']->ID;
                $variationId = (int) $product['variation']->id;
                $fixtureOrder = FcDomainFixture::orderWithItem($productId, $variationId, 1);

                $marker = FcDomainFixture::marker('download-validity');
                $download = ProductDownload::query()->create([
                    'post_id'              => $productId,
                    'product_variation_id' => [$variationId],
                    'download_identifier'  => $marker,
                    'title'                => $marker,
                    'type'                 => 'file',
                    'driver'               => 'local',
                    'file_name'            => 'validity.txt',
                    'file_path'            => 'validity.txt',
                    'file_url'             => 'validity.txt',
                    'file_size'            => '0',
                    'serial'               => 1,
                ]);
                $downloadId = (int) $download->id;

                $order = Order::query()->find((int) $fixtureOrder->id);
                $linkFor = function ($scope) use ($order) {
                    $groups = $order->getDownloads($scope);
                    FcTest::assert(!empty($groups[0]['downloads'][0]['download_url']), 'the paid order lists its download for ' . $scope);

                    return $groups[0]['downloads'][0]['download_url'];
                };

                $emailMinutes = $minutesLeft($linkFor('email'));
                FcTest::assert(
                    $emailMinutes >= 7 * 24 * 60 - 1,
                    'an email download link lasts 7 days, got ' . $emailMinutes . ' minutes'
                );

                $accountMinutes = $minutesLeft($linkFor('customer-profile'));
                FcTest::assert(
                    $accountMinutes >= 59 && $accountMinutes <= 60,
                    'an account page download link keeps 60 minutes, got ' . $accountMinutes . ' minutes'
                );
            } finally {
                if ($downloadId) {
                    ProductDownload::query()->where('id', $downloadId)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'download-link-validity-s3-filter',
        'name'          => 'The S3 signed download URL follows fluent_cart/download_expiration_minutes, capped at the 7 days S3 allows',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $requestedMinutes = function ($minutes) {
                $filter = function () use ($minutes) {
                    return $minutes;
                };
                add_filter('fluent_cart/download_expiration_minutes', $filter);
                try {
                    $driver = new FcDownloadValidityS3Driver();
                    $driver->getSignedDownloadUrl('validity/file.pdf', 'fct-validity-bucket');
                } finally {
                    remove_filter('fluent_cart/download_expiration_minutes', $filter);
                }

                return $driver->requestedMinutes;
            };

            FcTest::assertSame(30, $requestedMinutes(30), 'a store can shorten the S3 link to 30 minutes');
            FcTest::assertSame(7 * 24 * 60, $requestedMinutes(30 * 24 * 60), 'a longer value is capped at the 7 days S3 accepts');
        },
    ],
];
