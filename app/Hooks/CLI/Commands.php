<?php

namespace FluentCart\App\Hooks\CLI;

use FluentCart\App\App;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\Database\DBMigrator;
use FluentCart\Database\DBSeeder;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;

class Commands
{
    public function migrate_wc_products($args, $assoc_args)
    {
        if (!class_exists('WooCommerce')) {
            \WP_CLI::error('WooCommerce is not installed or activated.');
            return;
        }

        $tableCheck = \FluentCart\App\Modules\WooCommerceMigrator\WooCommerceMigratorHelper::checkRequiredTables();
        if (is_wp_error($tableCheck)) {
            \WP_CLI::error($tableCheck->get_error_message());
            return;
        }

        $wcMigrator = new \FluentCart\App\Modules\WooCommerceMigrator\WooCommerceMigratorCli();

        try {
            // Count total products for progress bar
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $totalProducts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");

            if (!$totalProducts) {
                \WP_CLI::error('No WooCommerce products found to migrate.');
                return;
            }

            \WP_CLI::line('Starting attachment migration...');
            $attachmentResult = $wcMigrator->migrateAttachments();
            if (is_wp_error($attachmentResult)) {
                \WP_CLI::error('Attachment migration failed: ' . $attachmentResult->get_error_message());
                return;
            }
            \WP_CLI::success('Attachments migrated successfully.');

            \WP_CLI::line('Starting product migration...');
            $progress = \WP_CLI\Utils\make_progress_bar('Migrating products', $totalProducts);

            $result = $wcMigrator->migrate_products(Arr::get($assoc_args, 'update', false));

            if (is_wp_error($result)) {
                $progress->finish();
                \WP_CLI::error($result->get_error_message());
                return;
            }

            $progress->finish();

            \WP_CLI::success(sprintf(
                'Migration completed. Successfully migrated %d products. Failed: %d products.',
                $result['success'],
                $result['failed']
            ));

            if ($result['failed'] > 0) {
                \WP_CLI::warning('Failed products:');
                foreach ($result['failed_ids'] as $productId => $error) {
                    \WP_CLI::line(sprintf('Product ID %d: %s', $productId, $error));
                }
                \WP_CLI::warning('Check the migration logs for more details (_fluent_wc_failed_migration_logs option).');
            }

            // Verify migration
            $verificationErrors = $this->verifyMigration($result);
            if (!empty($verificationErrors)) {
                \WP_CLI::warning('Migration verification found issues:');
                foreach ($verificationErrors as $error) {
                    \WP_CLI::line('- ' . $error);
                }
            } else {
                \WP_CLI::success('Migration verification passed successfully.');
            }

        } catch (\Exception $e) {
            \WP_CLI::error('Migration failed: ' . $e->getMessage());
        }
    }

    private function verifyMigration($result)
    {
        $errors = [];
        global $wpdb;

        // Check if all products have details
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $productsWithoutDetails = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->prefix}fct_product_details pd ON p.ID = pd.post_id 
            WHERE p.post_type = 'fluent-products' AND pd.id IS NULL"
        );

        if ($productsWithoutDetails > 0) {
            $errors[] = sprintf('%d products are missing product details.', $productsWithoutDetails);
        }

        // Check if all variable products have variations
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $variableProductsWithoutVariations = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_details pd 
            LEFT JOIN {$wpdb->prefix}fct_product_variations pv ON pd.post_id = pv.post_id 
            WHERE pd.variation_type = 'advance_variation' AND pv.id IS NULL"
        );

        if ($variableProductsWithoutVariations > 0) {
            $errors[] = sprintf('%d variable products are missing variations.', $variableProductsWithoutVariations);
        }

        // Check if all downloadable products have download records
        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $downloadableProductsWithoutFiles = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_details pd 
            LEFT JOIN {$wpdb->prefix}fct_product_downloads dl ON pd.post_id = dl.post_id 
            WHERE pd.fulfillment_type = 'digital' AND dl.id IS NULL"
        );

        if ($downloadableProductsWithoutFiles > 0) {
            $errors[] = sprintf('%d downloadable products are missing download files.', $downloadableProductsWithoutFiles);
        }

        return $errors;
    }

    public function anonymize_customers()
    {
        $total = Customer::count();
        $progress = \WP_CLI\Utils\make_progress_bar('Anonymize Customers: (' . number_format($total) . ')', $total);
        $page = 1;
        $completed = false;
        while (!$completed) {
            $customers = Customer::orderBy('id', 'ASC')
                ->limit(500)
                ->offset(($page - 1) * 500)
                ->get();
            if ($customers->isEmpty()) {
                $completed = true;
                break;
            }

            foreach ($customers as $customer) {
                $progress->tick();
                if (!$customer->orders || $customer->orders->isEmpty()) {
                    $customer->delete();
                    continue;
                }
                $customer->email = 'customer_' . $customer->id . '@example.com';
                $customer->user_id = NULL;
                $customer->save();
            }
            $page++;
        }
        $progress->finish();

        \WP_CLI::line('Anonymized ' . $total . ' Customers');

        // let's annonymize the license keys as well
        $total = fluentCart('db')->table('fct_licenses')->count();
        $progress = \WP_CLI\Utils\make_progress_bar('Anonymize License keys: (' . number_format($total) . ')', $total);

        $page = 1;
        $completed = false;
        while (!$completed) {
            $licesnses = License::orderBy('id', 'ASC')
                ->limit(500)
                ->offset(($page - 1) * 500)
                ->get();

            if ($licesnses->isEmpty()) {
                $completed = true;
                break;
            }
            foreach ($licesnses as $license) {
                $progress->tick();
                $license->license_key = md5($license->license_key . time());
                $license->save();
            }
            $page++;
        }

        $progress->finish();
        \WP_CLI::line('Anonymized ' . $total . ' License Keys');

        $progress->finish();
        \WP_CLI::line('Anonymized ' . $total . ' Sites');

    }

    public function sync_product_names()
    {

        $productTitles = fluentCart('db')->table('posts')
            ->where('post_type', 'fluent-products')
            ->get()
            ->keyBy('ID');

        foreach ($productTitles as $productId => $product) {
            continue;
            fluentCart('db')->table('fct_order_items')
                ->where('post_id', $productId)
                ->update([
                    'post_title' => $product->post_title
                ]);
        }

        $subscriptions = fluentCart('db')->table('fct_subscriptions')
            ->get();

        foreach ($subscriptions as $subscription) {
            $title = $productTitles[$subscription->product_id] ? $productTitles[$subscription->product_id]->post_title : '';
            if (!$title) {
                continue;
            }

            $variation = fluentCart('db')->table('fct_product_variations')
                ->where('id', $subscription->variation_id)
                ->first();

            if (!$variation) {
                continue;
            }

            $fullTitle = $title . ' - ' . $variation->variation_title;

            fluentCart('db')->table('fct_subscriptions')
                ->where('id', $subscription->id)
                ->update([
                    'item_name' => $fullTitle
                ]);
        }


        dd($productTitles);


    }

    public function recount_stats($args, $assoc_args)
    {
        $type = Arr::get($assoc_args, 'type');
        $types = ['customers', 'subscriptions', 'coupons'];

        if (!in_array($type, $types)) {
            \WP_CLI::line('Invalid Type. Please provide any of the following types:');
            foreach ($types as $type) {
                \WP_CLI::line($type);
            }
            return;
        }

        if ($type == 'customers') {
            $this->recountCustomersStat();
        } else if ($type == 'subscriptions') {
            $this->recountSubscriptions();
        } else if ($type == 'coupons') {
            $this->recountCoupons();
        }
    }

    public function recountCustomersStat()
    {
        $completed = false;
        $page = 1;
        $perPage = 100;
        $totalCustomers = Customer::count();

        $progress = \WP_CLI\Utils\make_progress_bar('Recounting Customer stats: (' . number_format($totalCustomers) . ')', $totalCustomers);
        while (!$completed) {
            $customers = Customer::orderBy('id', 'ASC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            if ($customers->isEmpty()) {
                $completed = true;
                break;
            }

            foreach ($customers as $customer) {
                $orders = \FluentCart\App\Models\Order::query()->where('customer_id', $customer->id)
                    ->with('transactions')
                    ->get();

                $totalPayments = [];
                $ltv = 0;

                foreach ($orders as $order) {
                    $netPaid = $order->total_paid - $order->total_refund;
                    if ($netPaid <= 0) {
                        continue;
                    }

                    $ltv += $netPaid;


                    foreach ($order->transactions as $transaction) {
                        if ($transaction->status == 'paid') {
                            if (empty($totalPayments[$order['currency']])) {
                                $totalPayments[$order['currency']] = $transaction['total'];
                            } else {
                                $totalPayments[$order['currency']] += $transaction['total'];
                            }
                        }
                    }

                    $totalPayments = array_map(function ($value) {
                        return (int)$value;
                    }, $totalPayments);
                }

                $updateData = [
                    'user_id'             => $customer->getWpUserId(true),
                    'purchase_value'      => $totalPayments,
                    'ltv'                 => $ltv,
                    'purchase_count'      => $orders->count(),
                    'first_purchase_date' => $orders->min('created_at') . '',
                    'last_purchase_date'  => $orders->max('created_at') . '',
                ];

                App::db()->table('fct_customers')->where('id', $customer->id)->update($updateData);
                $progress->tick();
            }

            $page++;
        }

        $progress->finish();
    }

    public function recountSubscriptions()
    {
        $completed = false;
        $page = 1;
        $perPage = 100;
        $total = Subscription::count();

        $progress = \WP_CLI\Utils\make_progress_bar('Recounting Subscriptions Bills count: (' . number_format($total) . ')', $total);
        while (!$completed) {
            $subscriptions = Subscription::orderBy('id', 'ASC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            if ($subscriptions->isEmpty()) {
                $completed = true;
                break;
            }

            $keyedSubscriptions = [];
            $parentOrderIds = [];

            foreach ($subscriptions as $subscription) {
                $progress->tick();
                if (isset($keyedSubscriptions[$subscription->parent_order_id])) {
                    // dd('Invalid Subscription Parent ID: '. $subscription->parent_order_id);
                }

                $keyedSubscriptions[$subscription->parent_order_id] = $subscription;
                $parentOrderIds[] = $subscription->parent_order_id;
            }

            $renewals = \FluentCart\App\Models\Order::query()
                ->where(function ($query) use ($parentOrderIds) {
                    $query->whereIn('id', $parentOrderIds)
                        ->orWhereIn('parent_id', $parentOrderIds);
                })
                ->whereIn('payment_status', ['paid', 'partially_refunded'])
                ->get();

            $counts = [];

            foreach ($renewals as $renewal) {
                if ($renewal->parent_id) {
                    if (!isset($counts[$renewal->parent_id])) {
                        $counts[$renewal->parent_id] = 0;
                    }
                    $counts[$renewal->parent_id]++;
                } else {
                    if (!isset($counts[$renewal->id])) {
                        $counts[$renewal->id] = 0;
                    }
                    $counts[$renewal->id]++;
                }
            }

            foreach ($counts as $orderId => $count) {
                if (!isset($keyedSubscriptions[$orderId])) {
                    \WP_CLI::line('Invalid Subscription. orderID: ' . $orderId);
                    continue;
                }

                $subscription = $keyedSubscriptions[$orderId];
                if ($subscription->bill_count != $count) {
                    unset($subscription->preventsLazyLoading);
                    $subscription->bill_count = $count;
                    $subscription->save();
                }
            }

            $page++;
        }

        $progress->finish();
    }

    private function recountCoupons()
    {
        $appliedCoupons = AppliedCoupon::whereHas('order', function ($query) {
            $query->whereIn('payment_status', ['paid', 'partially_refunded', 'require_capture']);
        })
            ->selectRaw('coupon_id, code, COUNT(*) as count')
            ->groupBy('coupon_id')
            ->whereNotNull('coupon_id')
            ->get();

        foreach ($appliedCoupons as $appliedCoupon) {
            fluentCart('db')->table('fct_coupons')->where('id', $appliedCoupon->coupon_id)
                ->update(['use_count' => $appliedCoupon->count]);
        }

        \WP_CLI::line('Recounted ' . $appliedCoupons->count() . ' Coupons');
    }

    private function getFakeCustomer()
    {
        $faker = \Faker\Factory::create();

        $gender = $faker->randomElement(['male', 'female']);

        $firstName = $faker->firstName($gender);
        $lastName = $faker->firstName($gender);

        return [
            'email'              => $faker->email,
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'billing_first_name' => $firstName,
            'shipping_last_name' => $lastName,
            'billing_address_1'  => $faker->streetName(),
            'billing_address_2'  => '',
            'billing_city'       => $faker->city(),
            'billing_state'      => $faker->state,
            'billing_zip'        => $faker->postcode,
            'billing_country'    => $faker->countryCode(),
            'ip_address'         => $faker->ipv4,
            'phone'              => $faker->phoneNumber,
            'date_of_birth'      => $faker->date('Y-m-d', '-35 years')
        ];

    }

    private function getRandomCart($products, $maxAmount = 5)
    {
        $maxItem = random_int(1, $maxAmount);

        $cartItems = [];
        for ($itemCount = 1; $itemCount <= $maxItem; $itemCount++) {
            $randomProduct = $products[array_rand($products)];
            $quantity = random_int(1, 3);
            $itemPrice = $randomProduct['detail']['item_price'];
            $itemData = [
                'product_id'     => $randomProduct['ID'],
                'variation_id'   => $randomProduct['detail']['id'],
                'quantity'       => $quantity,
                'item_price'     => $itemPrice,
                'line_total'     => $quantity * $itemPrice,
                'fallback_title' => $randomProduct['post_title']
            ];

            if ($randomProduct['detail']['manage_cost'] == 'yes' && $randomProduct['detail']['item_cost']) {
                $profitPerItem = $itemPrice - $randomProduct['detail']['item_cost'];
                $itemData['net_profit'] = $profitPerItem * $quantity;
            }

            $cartItems[$randomProduct['ID']] = $itemData;
        }

        return $cartItems;
    }

    public function migrate(): void
    {
        DBMigrator::migrate();
    }

    public function migrate_fresh_2($args, $assoc_args)
    {
        delete_option('fluent_cart_plugin_once_activated');
    }

    public function migrate_fresh($args, $assoc_args, $checkDev = true)
    {

        if ($checkDev && App::config()->get('using_faker') === false) {
            if (class_exists('WP_CLI')) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo \WP_CLI::colorize('%yYou Are Not In Dev Mode');
            } else {
                echo esc_html__("You Are Not In Dev Mode", "fluent-cart");
            }
            return;
        }

        delete_option('fluent_cart_plugin_once_activated');
        delete_option('fluent_cart_store_settings');

        delete_option('__fluent_cart_edd2_migration_steps');
        delete_option('_fluent_edd_failed_payment_logs');

        global $wpdb;
        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("SET GLOBAL FOREIGN_KEY_CHECKS=0;");

        try {
            DBMigrator::refresh();
        } catch (\Exception $e) {

        }

        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("SET GLOBAL FOREIGN_KEY_CHECKS=1;");

        // Delete the post metas
        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE pm FROM {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID WHERE p.post_type = 'fluent-products'");
        // Delete the posts
        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_type = 'fluent-products'");

        // Delete the post metas
        $postmetas = ['_edd_migrated_from', '_fcart_migrated_id', '__edd_migrated_variation_maps'];
        foreach ($postmetas as $postMeta) {
            // delete from wp_postmeta table where meta_key = $postMeta
            //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key = %s", $postMeta));
        }

        if (isset($assoc_args['seed'])) {
            $this->seed_all($args, $assoc_args, 1000, false);
        }

        if (class_exists('WP_CLI')) {
            \WP_CLI::line('All Data has been reseted!');
        } else {
            echo "All Done!";
        }

    }

    public function seed($args, $assoc_args, $default = 1000, $checkDev = true)
    {
        if ($checkDev) {
            $this->authorize();
        }

        $entities = ['product', 'customer', 'order', 'coupon', 'tax'];
        $count = isset($assoc_args['count']) ? absint($assoc_args['count']) : $default;


        if (class_exists('WP_CLI')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%yInserting ' . esc_html($count) . ' records. Please wait...%n');
        }

        foreach ($entities as $entity) {
            if (isset($assoc_args[$entity])) {
                DBSeeder::run($count, $entity, true, $assoc_args);
            }
        }

        if (class_exists('WP_CLI')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize( '%GSuccess: ' . esc_html( $count ) . ' records inserted into the database.%n' );

        }
    }

    public function authorize($checkDev = true)
    {
        if ($checkDev && App::config()->get('using_faker') === false) {
            if (class_exists('WP_CLI')) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo \WP_CLI::colorize('%yYou Are Not In Dev Mode');
            } else {
                echo('You Are Not In Dev Mode');
            }

            die();
        }
    }

    public function seed_all($args, $assoc_args, $default = 1000, $checkDev = true)
    {

        if ($checkDev) {
            $this->authorize();
        }

        $count = isset($assoc_args['count']) ? absint($assoc_args['count']) : $default;
        if (class_exists('WP_CLI')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%yInserting ' . esc_html($count) . ' records. Please wait...%n');
        }
        DBSeeder::run($count);
        if (class_exists('WP_CLI')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo \WP_CLI::colorize('%GSuccess: ' . esc_html($count) . ' records inserted into the database.%n');
        }
    }


    public function generate_billing_address_from_ip()
    {
        if (!function_exists('fluent_geo_location')) {
            \WP_CLI::line('Skipping Billing Address Generation. fluent_geo_location function not found.');
            return;
        }

        $counts = Order::whereDoesntHave('billing_address')->count();
        if (!$counts) {
            \WP_CLI::line('All Done!');
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar('Generating Billing Address: (' . number_format($counts) . ')', $counts);
        $completed = false;
        $perPage = 100;
        $page = 1;

        while (!$completed) {
            $orders = Order::whereDoesntHave('billing_address')
                ->orderBy('id', 'ASC')
                ->limit($perPage)
                ->offset(($page - 1) * $perPage)
                ->get();

            if ($orders->isEmpty()) {
                $completed = true;
                break;
            }

            $allAddresses = [];
            foreach ($orders as $order) {
                $progress->tick();
                $ip = $order->ip_address;
                if (!$ip) {
                    continue;
                }

                $geoInfo = fluent_geo_location($ip);

                if (is_wp_error($geoInfo)) {
                    continue;
                }

                if (empty($geoInfo['country'])) {
                    continue;
                }

                $allAddresses[] = array_filter([
                    'type'       => 'billing',
                    'order_id'   => $order->id,
                    'city'       => $geoInfo['city'],
                    'state'      => $geoInfo['state'],
                    'country'    => $geoInfo['country'],
                    'postcode'   => $geoInfo['postal_code'],
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ]);

                $customer = $order->customer;
                if (!$customer->country && $geoInfo['country']) {
                    $customer->country = $geoInfo['country'] ?? '';
                    $customer->city = $geoInfo['city'] ?? '';
                    $customer->state = $geoInfo['state'] ?? '';
                    $customer->postcode = $geoInfo['postal_code'] ?? '';
                    $customer->save();
                }
            }

            if ($allAddresses) {
                foreach ($allAddresses as $address) {
                    fluentCart('db')->table('fct_order_addresses')->insert($address);
                }
            }

            $page++;
        }

        $progress->finish();
    }


    /**
     * Migrate WooCommerce customers to FluentCart
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force migration even if customers already exist
     *
     * [--debug]
     * : Show debug information about customers found
     *
     * ## EXAMPLES
     *
     *     wp fluent_cart migrate_customers
     *     wp fluent_cart migrate_customers --force
     *     wp fluent_cart migrate_customers --debug
     */
    public function migrate_customers($args, $assoc_args)
    {
        \WP_CLI::line('Starting WooCommerce to FluentCart customer migration...');

        // Debug mode to check what customers are found
        if (isset($assoc_args['debug'])) {
            global $wpdb;

            \WP_CLI::line('=== DEBUG MODE ===');

            // Check WooCommerce
            if (!class_exists('WooCommerce')) {
                \WP_CLI::error('WooCommerce is not active');
                return;
            }
            \WP_CLI::line('✓ WooCommerce is active');

            // Check customers query
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $customers = $wpdb->get_results("
                SELECT DISTINCT u.ID, u.user_email, u.user_registered
                FROM {$wpdb->users} u
                WHERE u.ID IN (
                    SELECT DISTINCT pm.meta_value
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order' 
                    AND pm.meta_key = '_customer_user'
                    AND pm.meta_value > 0
                )
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} um 
                    WHERE um.user_id = u.ID 
                    AND um.meta_key = 'paying_customer' 
                    AND um.meta_value = '1'
                )
                ORDER BY u.user_registered ASC
                LIMIT 10
            ");

            \WP_CLI::line(sprintf('Found %d customers:', count($customers)));
            foreach ($customers as $customer) {
                \WP_CLI::line(sprintf('- ID: %d, Email: %s, Registered: %s',
                    $customer->ID, $customer->user_email, $customer->user_registered));
            }

            // Check existing FluentCart customers
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $fluentCustomers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");
            \WP_CLI::line(sprintf('Existing FluentCart customers: %d', $fluentCustomers));

            return;
        }

        $service = new \FluentCart\App\Modules\WooCommerceMigrator\Services\CustomerMigrationService();

        if (!$service->checkDependencies()) {
            \WP_CLI::error('Migration dependencies not met. Check error logs for details.');
            return;
        }

        $options = [
            'force' => isset($assoc_args['force']) && $assoc_args['force']
        ];

        $stats = $service->migrate($options);

        $this->displayMigrationStats('Customers', $stats);
    }


    /**
     * Migrate WooCommerce orders to FluentCart
     *
     * ## OPTIONS
     *
     * [--batch-size=<size>]
     * : Number of orders to process at once
     * ---
     * default: 50
     * ---
     *
     * [--start-date=<date>]
     * : Start date for order migration (YYYY-MM-DD)
     *
     * [--end-date=<date>]
     * : End date for order migration (YYYY-MM-DD)
     *
     * [--debug]
     * : Show debug information about orders found
     *
     * ## EXAMPLES
     *
     *     wp fluent_cart migrate_orders
     *     wp fluent_cart migrate_orders --batch-size=25
     *     wp fluent_cart migrate_orders --start-date=2024-01-01 --end-date=2024-12-31
     *     wp fluent_cart migrate_orders --debug
     */
    public function migrate_orders($args, $assoc_args)
    {
        \WP_CLI::line('Starting WooCommerce to FluentCart order migration...');

        $service = new \FluentCart\App\Modules\WooCommerceMigrator\Services\OrderMigrationService();

        if (!$service->canMigrate()) {
            $errors = $service->getErrors();
            foreach ($errors as $error) {
                \WP_CLI::error($error);
            }
            return;
        }

        // Debug mode to check what orders are found
        if (isset($assoc_args['debug'])) {
            global $wpdb;

            \WP_CLI::line('=== DEBUG MODE ===');

            // Check HPOS
            $hposEnabled = get_option('woocommerce_custom_orders_table_enabled') === 'yes';
            \WP_CLI::line($hposEnabled ? '✓ HPOS is enabled' : '✗ HPOS is not enabled');

            // Check orders
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $orderCount = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'");
            \WP_CLI::line(sprintf('Total WooCommerce orders: %d', $orderCount));

            //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $migratedCount = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE invoice_no LIKE 'WC-%'");
            \WP_CLI::line(sprintf('Already migrated orders: %d', $migratedCount));

            // Sample orders
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $sampleOrders = $wpdb->get_results("
                SELECT id, status, currency, total_amount, customer_id, date_created_gmt 
                FROM {$wpdb->prefix}wc_orders 
                WHERE type = 'shop_order' 
                ORDER BY id DESC 
                LIMIT 5
            ");

            \WP_CLI::line('Sample orders:');
            foreach ($sampleOrders as $order) {
                \WP_CLI::line(sprintf('- ID: %d, Status: %s, Total: %s %s, Customer: %s, Date: %s',
                    $order->id, $order->status, $order->currency, $order->total_amount,
                    $order->customer_id ?: 'Guest', $order->date_created_gmt));
            }

            return;
        }

        $options = [];

        // Set batch size
        if (isset($assoc_args['batch-size'])) {
            $service->setBatchSize((int)$assoc_args['batch-size']);
        }

        // Set date filters (if needed in future)
        if (isset($assoc_args['start-date']) || isset($assoc_args['end-date'])) {
            \WP_CLI::warning('Date filtering not yet implemented. Processing all orders.');
        }

        $stats = $service->migrate($options);

        $this->displayMigrationStats('Orders', $stats);
    }

    /**
     * Update customer purchase statistics after order migration
     *
     * This command recalculates purchase counts, values, and dates for customers
     * who have migrated orders. Useful if orders were migrated but customer stats
     * weren't properly updated.
     *
     * ## EXAMPLES
     *
     *     wp fluent_cart update_customer_stats
     *
     * @when after_wp_load
     */
    public function update_customer_stats($args, $assoc_args)
    {
        \WP_CLI::line('Updating customer purchase statistics...');

        try {
            $orderService = new \FluentCart\App\Modules\WooCommerceMigrator\Services\OrderMigrationService();
            $result = $orderService->updateAllCustomerStats();

            \WP_CLI::success($result['message']);

            if (!empty($orderService->getErrors())) {
                \WP_CLI::warning('Some errors occurred:');
                foreach ($orderService->getErrors() as $error) {
                    \WP_CLI::line('- ' . $error);
                }
            }

        } catch (\Exception $e) {
            \WP_CLI::error('Failed to update customer statistics: ' . $e->getMessage());
        }
    }

    /**
     * Run complete migration (products, customers, orders)
     *
     * ## OPTIONS
     *
     * [--skip-products]
     * : Skip product migration
     *
     * [--skip-customers]
     * : Skip customer migration
     *
     * [--skip-orders]
     * : Skip order migration
     *
     * ## EXAMPLES
     *
     *     wp fluent_cart migrate_all
     *     wp fluent_cart migrate_all --skip-products
     *     wp fluent_cart migrate_all --skip-customers
     *     wp fluent_cart migrate_all --skip-orders
     */
    public function migrate_all($args, $assoc_args)
    {
        \WP_CLI::line('Starting complete WooCommerce to FluentCart migration...');
        \WP_CLI::line('Migration order: Products → Customers → Orders (dependencies respected)');

        $totalStats = [
            'products'  => null,
            'customers' => null,
            'orders'    => null
        ];

        // Migrate Products (foundation requirement)
        if (!isset($assoc_args['skip-products'])) {
            \WP_CLI::line('');
            \WP_CLI::line('=== MIGRATING PRODUCTS ===');
            \WP_CLI::line('Products must be migrated first (required for order items)');
            try {
                $this->migrate_wc_products($args, $assoc_args);
                \WP_CLI::success('Product migration completed');
            } catch (\Exception $e) {
                \WP_CLI::error('Product migration failed: ' . $e->getMessage());
                \WP_CLI::line('Cannot proceed with orders without products. Stopping migration.');
                return;
            }
        }

        // Migrate Customers (required for orders)
        if (!isset($assoc_args['skip-customers'])) {
            \WP_CLI::line('');
            \WP_CLI::line('=== MIGRATING CUSTOMERS ===');
            \WP_CLI::line('Customers must be migrated before orders (required for order ownership)');
            $service = new \FluentCart\App\Modules\WooCommerceMigrator\Services\CustomerMigrationService();
            $totalStats['customers'] = $service->migrate();
            $this->displayMigrationStats('Customers', $totalStats['customers']);

            if (($totalStats['customers']['success'] ?? 0) === 0) {
                \WP_CLI::warning('No customers were migrated. Orders migration may have limited functionality.');
            }
        }

        // Migrate Orders (depends on products and customers)
        if (!isset($assoc_args['skip-orders'])) {
            \WP_CLI::line('');
            \WP_CLI::line('=== MIGRATING ORDERS ===');
            \WP_CLI::line('Orders migration includes: line items, addresses, coupons, fees, and metadata');

            $orderService = new \FluentCart\App\Modules\WooCommerceMigrator\Services\OrderMigrationService();

            if (!$orderService->canMigrate()) {
                $errors = $orderService->getErrors();
                \WP_CLI::warning('Order migration cannot proceed:');
                foreach ($errors as $error) {
                    \WP_CLI::line('- ' . $error);
                }
                \WP_CLI::line('Skipping order migration. Run individual migrations first.');
            } else {
                $totalStats['orders'] = $orderService->migrate();
                $this->displayMigrationStats('Orders', $totalStats['orders']);
            }
        }

        // Summary
        \WP_CLI::line('');
        \WP_CLI::line('=== MIGRATION SUMMARY ===');
        $overallSuccess = true;

        foreach ($totalStats as $type => $stats) {
            if ($stats === null) {
                \WP_CLI::line(sprintf('%s: Skipped', ucfirst($type)));
            } else {
                $success = $stats['success'] ?? 0;
                $failed = $stats['failed'] ?? 0;
                $total = $success + $failed;
                \WP_CLI::line(sprintf('%s: %d/%d successful', ucfirst($type), $success, $total));
                if ($failed > 0) {
                    $overallSuccess = false;
                }
            }
        }

        if ($overallSuccess) {
            \WP_CLI::success('Migration completed successfully! All data has been migrated.');
        } else {
            \WP_CLI::warning('Migration completed with some failures. Check individual stats above for details.');
        }
    }


    private function displayMigrationStats($type, $stats)
    {
        if (!$stats) {
            \WP_CLI::line("No {$type} migration stats available.");
            return;
        }

        \WP_CLI::line('');
        \WP_CLI::line("=== {$type} Migration Stats ===");
        \WP_CLI::line(sprintf('Success: %d', $stats['success'] ?? 0));
        \WP_CLI::line(sprintf('Failed: %d', $stats['failed'] ?? 0));
        \WP_CLI::line(sprintf('Skipped: %d', $stats['skipped'] ?? 0));
        \WP_CLI::line(sprintf('Total processed: %d', ($stats['success'] ?? 0) + ($stats['failed'] ?? 0) + ($stats['skipped'] ?? 0)));

        if (!empty($stats['errors'])) {
            \WP_CLI::line('');
            \WP_CLI::line('Errors:');
            foreach ($stats['errors'] as $error) {
                \WP_CLI::line('- ' . $error);
            }
        }

        if (!empty($stats['warnings'])) {
            \WP_CLI::line('');
            \WP_CLI::line('Warnings:');
            foreach ($stats['warnings'] as $warning) {
                \WP_CLI::line('- ' . $warning);
            }
        }
    }

    /**
     * Clone existing orders with random dates
     *
     * ## OPTIONS
     *
     * [--count=<number>]
     * : Number of orders to clone
     * ---
     * default: 10
     * ---
     *
     * [--start-date=<date>]
     * : Start date for cloned orders (YYYY-MM-DD)
     * ---
     * default: 30 days ago
     * ---
     *
     * [--end-date=<date>]
     * : End date for cloned orders (YYYY-MM-DD)
     * ---
     * default: today
     * ---
     *
     * [--source-order-id=<id>]
     * : Specific order ID to clone
     *
     * ## EXAMPLES
     *
     *     wp fluent_cart clone_orders --count=50
     *     wp fluent_cart clone_orders --count=25 --start-date=2024-01-01 --end-date=2024-12-31
     *     wp fluent_cart clone_orders --source-order-id=123 --count=5
     */
    public function clone_orders($args, $assoc_args)
    {
        $cloner = new OrderCloneCommand();
        $cloner->clone_orders($args, $assoc_args);
    }
}
