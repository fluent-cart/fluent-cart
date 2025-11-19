<?php

namespace FluentCart\Database\Seeder;

use FluentCart\App\Models\DynamicModel;
use FluentCart\Faker\Factory;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class ProductSeeder
{
    public static function seed($count, $assoc_args = [])
    {
        $productTypes = Arr::get($assoc_args, 'product_types', []);
        $faker = Factory::create();
        $faker->addProvider(new \FluentCart\Database\Seeder\ProductNameProvide($faker));

        if (defined('WP_CLI') && WP_CLI) {
            $progress = \WP_CLI\Utils\make_progress_bar('%CSeeding Products', $count);
        }
        $variationTypes = [];

        $lastId = (new DynamicModel([], 'posts'))->newQuery()->latest('ID')->value('ID');

        for ($i = 0; $i <= $count - 1; $i++) {
            $fakeProduct = $faker->getProduct($productTypes);
            $variationTypes[$i] = $fakeProduct['variationType'];


            $productTitle = $fakeProduct['name'];
            $productName = Str::slug($productTitle, '-', null);
            $createdDate = $faker->dateTimeBetween('-700 days', 'now');
            $productNameSuffix = $faker->dateTime()->format('d-m-Y-H-i-s');
            $data = [
                'post_author' => get_current_user_id(),
                'post_date' => $createdDate,
                'post_date_gmt' => $createdDate,
                'post_content' => '',
                'post_content_filtered' => '',
                'post_title' => $productTitle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'post_type' => 'fluent-products',
                'comment_status' => 'open',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => $productName . '-' . $productNameSuffix,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $createdDate,
                'post_modified_gmt' => $createdDate,
                'post_parent' => 0,
                'menu_order' => 0,
                'post_mime_type' => '',
                'guid' => get_site_url() . '/?items=' . $productName . '-' . $productNameSuffix
            ];


            Product::query()->insert($data);

            if (defined('WP_CLI') && WP_CLI) {
                if ($i !== $count - 1) {
                    $progress->tick();
                }
            } else {
                echo wp_kses_post( sprintf(
                    /* translators: %d: product ID */
                    __('Inserting Product %1$s<br>', 'fluent-cart'),
                    esc_html($i + 1)
                ) );
            }
        }

        //$lastProductsIds = Product::select('ID')->orderBy('ID', 'desc')->limit($count)->get()->pluck('ID');
        $createdProducts = Product::query()->select(['ID', 'post_title'])
            ->where('ID', '>', $lastId)
            ->orderBy('ID', 'asc')->limit($count)->get();


        $productDetails = [];


        $variationLoop = -1;
        foreach ($createdProducts as $createdProduct) {
            $variationLoop = $variationLoop + 1;


            $createdPostId = $createdProduct->ID;

            $productPrices = [];
            $productComparePrices = [];

            if (!empty($createdPostId)) {
                $fulfilmentType = 'physical';
                $stockStatus = $faker->randomElement(['in-stock', 'out-of-stock']);
                $isSubscribable = $variationTypes[$variationLoop] === 'subscribable';
                $basePrice = $faker->numberBetween(180, 520);


                if ($isSubscribable) {
                    //ensure if a product type is subscribable, fulfilmentType type should be digital,
                    //and its variation type should be simple_variations
                    $fulfilmentType = 'digital';
                    $variationType = Helper::PRODUCT_TYPE_SIMPLE_VARIATION;
                    $variationCount = 4;
                } else {
                    $variationType = $faker->randomElement([Helper::PRODUCT_TYPE_SIMPLE, Helper::PRODUCT_TYPE_SIMPLE_VARIATION]);
                    $variationCount = $faker->numberBetween(1, 2);
                }
                $createdDate = $faker->dateTimeBetween('-700 days', 'now');
                $defaultVariationId = [];


                for ($j = 0; $j <= $variationCount - 1; $j++) {

                    if ($isSubscribable) {
                        if ($j === 0) {
                            $price = $basePrice;
                        } else if ($j === 1) {
                            $price = ((int) (($basePrice - $basePrice * (.04)) / 52)) + 2;
                        } else if ($j === 2) {
                            $price = ((int) (($basePrice - $basePrice * (.04)) / 12)) + 1;
                        } else {
                            $price = (int) (($basePrice - $basePrice * (.04)));
                        }
                    } else {
                        $price = $faker->numberBetween(10, 100);
                    }

                    $productPrices[] = $price;

                    $comparePrice = $faker->numberBetween($price - 1, $price + wp_rand(10, 100));

                    if ($isSubscribable && $comparePrice > $basePrice) {
                        $comparePrice = (int) (($basePrice - $basePrice * (.02))) - 1;
                    }

                    $productComparePrices[] = $comparePrice;

                    $otherInfo = [];


                    //Check if the product supports subscription
                    if ($variationTypes[$variationLoop] === 'subscribable' && $j === 0) {
                        $paymentType = 'onetime';
                    } else if ($variationTypes[$variationLoop] === 'subscribable' && $j > 0) {
                        $paymentType = 'subscription';
                    } else {
                        $paymentType = 'onetime';
                    }

                    if ($paymentType == 'onetime') {
                        $otherInfo['payment_type'] = $paymentType;
                    } else {

                        $manageSetupFee = $faker->randomElement(['yes', 'no']);
                        $times = $faker->numberBetween(1, 10);


                        // $repeatIUnit = $faker->randomElement(['daily', 'weekly', 'monthly', 'yearly']);

                        $repeatIUnits = ['lifetime', 'weekly', "monthly", "yearly"];
                        $repeatIUnit = Arr::get($repeatIUnits, $j, 'yearly');
                        // $chargeFee = number_format(($price / $times), 2);
                        $billingSummary = "{$price} {$repeatIUnit} for {$times} Times";
                        $otherInfo = [
                            'payment_type' => $paymentType,
                            'times' => $times,
                            'repeat_interval' => $repeatIUnit,
                            'billing_summary' => $billingSummary,
                            'manage_setup_fee' => $manageSetupFee,
                        ];
                        if ($manageSetupFee == 'yes') {
                            $setupFeePerItem = $faker->randomElement(['yes', 'no']);
                            $otherInfo['signup_fee'] = Helper::toCent($faker->numberBetween(10, 1000));
                            $otherInfo['signup_fee_name'] = $faker->productSignUpFeeName();
                            $otherInfo['setup_fee_per_item'] = $setupFeePerItem;
                        }
                    }

                    $stock = ($stockStatus === Helper::IN_STOCK) ? 500 : 0;

                    $productVariationDetails = [
                        'post_id' => $createdPostId,
                        'serial_index' => $j + 1,
                        'variation_title' => ($j === 0 && $variationCount === 1) ?
                            $createdProduct['post_title'] :
                            $faker->productVariation(
                                $variationTypes[$variationLoop],
                                $createdProduct['post_title'],
                                $paymentType,
                                $j
                            ),
                        'payment_type' => $paymentType,
                        'stock_status' => $stockStatus,
                        'total_stock' => $stock,
                        'available' => $stock,
                        'fulfillment_type' => $fulfilmentType,
                        'item_status' => 'active',
                        'item_price' => Helper::toCent($price),
                        'compare_price' => Helper::toCent($comparePrice),
                        'other_info' => $otherInfo,
                        'created_at' => $createdDate
                    ];
                    $defaultVariationId[] = ProductVariation::query()->create($productVariationDetails);
                }

                //$getProductVariation = ProductVariation::query()->where('post_id', $createdPostId);
                //$price = $getProductVariation->min('item_price');
                //$comparePrice = $getProductVariation->max('item_price');

                $productDetails[] = [
                    'post_id' => $createdPostId,
                    'fulfillment_type' => $fulfilmentType,
                    'min_price' => min($productPrices) * 100,
                    'max_price' => max($productComparePrices) * 100,
                    'default_variation_id' => $defaultVariationId[0]['id'],
                    'variation_type' => $variationType,
                    'stock_availability' => $stockStatus,
                    'created_at' => $createdDate
                ];
            }
        }

        ProductDetail::query()->insert($productDetails);
        //ProductVariation::insert($productVariationDetails);

        if (defined('WP_CLI') && WP_CLI) {
            $progress->tick();
            $progress->finish();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%0%n');
        }

    }

    public static function seedProductWithoutDetails($count)
    {
        $faker = Factory::create();
        $faker->addProvider(new \FluentCart\Database\Seeder\ProductNameProvide($faker));

        $productsArray = [];
        for ($i = 0; $i <= $count; $i++) {
            $name = $faker->productName();
            $productsArray[] = [
                'post_title' => $name,
                'post_status' => 'publish',
                'post_type' => 'fluent-products',
            ];
        }

        return Product::query()->insert($productsArray);
    }
}

//DELETE FROM wp_posts WHERE post_type = 'fluent-products';
//DELETE FROM wp_fct_product_details WHERE product_id > 0;

