<?php
/**
 * Exact-ID fixtures for the smoke routes that need a record some stores lack:
 * a product download, a shipment, a renewal order, a cart and an approved review.
 *
 * Orders, product and cart come from FcFixture, FcDomainFixture and
 * FcAutomationFixture. The download, shipment and review rows are created here
 * through their models and deleted by captured primary ID after a marker check.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\Shipment;

class FcSmokeFixture
{
    /** @var array<string,string> Manifest "needs" values this class builds. */
    const NEEDS = ['product_download', 'shipment', 'renewal', 'cart', 'review'];

    /** @var array<string,array<int,string>> Model class => [id => marker]. */
    private static $created = [
        ProductReview::class    => [],
        Shipment::class         => [],
        ProductDownload::class  => [],
    ];

    /** @var array<string,array<string,mixed>> */
    private static $tokens = [];

    /**
     * Placeholder values for one manifest need, or null when this class does
     * not build it.
     *
     * @param string $need
     * @return array<string,mixed>|null
     */
    public static function tokens($need)
    {
        return isset(self::$tokens[$need]) ? self::$tokens[$need] : null;
    }

    /**
     * Create the records the given needs call for.
     *
     * @param array<int,string> $needs
     * @return void
     */
    public static function create(array $needs)
    {
        $needs = array_intersect(self::NEEDS, $needs);
        if (!$needs) {
            return;
        }

        if (array_intersect(['product_download', 'review'], $needs)) {
            $product = FcDomainFixture::product();
            $postId = (int) $product['post']->ID;
            $variationId = (int) $product['variation']->id;
        }
        if (array_intersect(['shipment', 'renewal'], $needs)) {
            FcFixture::customer();
        }

        if (in_array('renewal', $needs, true)) {
            self::$tokens['renewal'] = ['id' => (int) FcFixture::order(['type' => 'renewal'])->id];
        }

        if (in_array('shipment', $needs, true)) {
            $orderId = (int) FcFixture::order()->id;
            $marker = FcDomainFixture::marker('smoke-shipment');
            $shipment = new Shipment();
            $shipment->fill(['tracking_number' => $marker]);
            $shipment->order_id = $orderId;
            $shipment->status = Shipment::STATUS_DISPATCHED;
            $shipment->save();
            self::capture(Shipment::class, $shipment, $marker);
            self::$tokens['shipment'] = ['order_id' => $orderId, 'shipment_id' => (int) $shipment->id];
        }

        if (in_array('product_download', $needs, true)) {
            $marker = FcDomainFixture::marker('smoke-download');
            $download = ProductDownload::query()->create([
                'post_id'              => $postId,
                'product_variation_id' => [$variationId],
                'download_identifier'  => $marker,
                'title'                => $marker,
                'type'                 => 'file',
                'driver'               => 'local',
                'file_name'            => 'smoke.txt',
                'file_path'            => 'smoke.txt',
                'file_url'             => 'smoke.txt',
                'file_size'            => '0',
                'serial'               => 1,
            ]);
            self::capture(ProductDownload::class, $download, $marker);
            self::$tokens['product_download'] = ['downloadableId' => (int) $download->id, 'product_id' => $postId];
        }

        if (in_array('cart', $needs, true)) {
            $cart = FcAutomationFixture::cart('smoke', gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));
            self::$tokens['cart'] = ['fct_cart_hash' => (string) $cart->cart_hash];
        }

        if (in_array('review', $needs, true)) {
            $marker = FcDomainFixture::marker('smoke-review') . '@example.invalid';
            $review = new ProductReview();
            $review->fill([
                'post_id'        => $postId,
                'reviewer_name'  => 'Smoke Fixture',
                'reviewer_email' => $marker,
                'title'          => 'Smoke fixture review',
                'review'         => 'Smoke fixture review',
                'rating'         => 5,
            ]);
            $review->status = Status::REVIEW_APPROVED;
            $review->save();
            self::capture(ProductReview::class, $review, $marker);
            self::$tokens['review'] = [
                'id'       => (int) $review->id,
                'reviewId' => (int) $review->id,
                'postId'   => $postId,
                'post_id'  => $postId,
            ];
        }
    }

    /**
     * Delete every owned row by exact ID, children before parents, and fail
     * when any captured ID is still present.
     *
     * @return void
     */
    public static function cleanupAll()
    {
        $markerColumns = [
            ProductReview::class   => 'reviewer_email',
            Shipment::class        => 'tracking_number',
            ProductDownload::class => 'download_identifier',
        ];
        foreach (self::$created as $class => $rows) {
            foreach (array_reverse($rows, true) as $id => $marker) {
                $row = $class::query()->find($id);
                if (!$row) {
                    continue;
                }
                if ((string) $row->{$markerColumns[$class]} !== $marker) {
                    throw new LogicException('Refusing to delete smoke fixture row: ownership changed.');
                }
                if ($row->delete() !== true) {
                    throw new RuntimeException('Model did not confirm delete of smoke fixture ' . $class . ' ' . $id);
                }
            }
        }

        FcAutomationFixture::cleanupAll();
        FcDomainFixture::cleanupAll();

        foreach (self::$created as $class => $rows) {
            if ($rows && $class::query()->whereIn('id', array_keys($rows))->count() !== 0) {
                throw new RuntimeException('Smoke fixture residue remains in ' . $class);
            }
        }
    }

    /**
     * @param string $class
     * @param object $row
     * @param string $marker
     * @return void
     */
    private static function capture($class, $row, $marker)
    {
        if ((int) $row->id <= 0) {
            throw new RuntimeException('Smoke fixture create returned no positive ID for ' . $class);
        }
        self::$created[$class][(int) $row->id] = $marker;
    }
}
