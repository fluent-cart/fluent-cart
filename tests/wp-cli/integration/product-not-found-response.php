<?php
/**
 * The block editor tells a deleted product from a failed load by the error that
 * GET /products/{product} returns (productLoadFailure in ProductPicker/productLoadFailure.js).
 * Route binding fails in the permission step, so a missing product is not a
 * 404; pin the body the editor reads.
 */

return [
    [
        'id'            => 'product-not-found-response',
        'name'          => 'missing product answers with the model-not-found error the block editor reads',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            global $wpdb;

            $missingId = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}") + 100000;

            $result = FcTest::rest('GET', '/products/' . $missingId);
            $data = is_array($result['data']) ? $result['data'] : [];

            FcTest::assert($result['status'] >= 400, 'missing product is an error, got status ' . $result['status']);
            FcTest::assert(
                isset($data['message']) && preg_match('/No query results for model/', (string) $data['message']) === 1,
                'error message carries the not-found text, got: ' . wp_json_encode($data)
            );
            FcTest::assert(
                ($data['code'] ?? '') !== 'rest_forbidden',
                'a missing product must not look like a permission denial'
            );
        },
    ],
];
