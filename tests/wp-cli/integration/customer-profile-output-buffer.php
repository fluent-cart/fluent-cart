<?php
/**
 * Customer profile shortcode/block output buffers (FluentCart board 23309).
 *
 * CustomerProfileHandler::renderShortcode() opened a buffer and returned
 * `$view ?? ob_get_clean()`. For a logged-out visitor render() returns the
 * login view as a string, so ob_get_clean() never ran and one buffer level
 * stayed open for the rest of the request. Anything that captures the render
 * in its own buffer (Elementor, REST consumers of the page) then got the
 * wrong output.
 */

return [
    [
        'id'            => 'customer-profile-output-buffer-logged-out',
        'name'          => 'Customer profile shortcode and block close their output buffer for logged-out visitors',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $originalUserId = get_current_user_id();
            wp_set_current_user(0);

            $renders = [
                'shortcode' => function () {
                    return do_shortcode('[fluent_cart_customer_profile]');
                },
                'block'     => function () {
                    return do_blocks('<!-- wp:fluent-cart/customer-profile /-->');
                },
            ];

            try {
                foreach ($renders as $name => $render) {
                    $level = ob_get_level();
                    $html = $render();
                    $leaked = ob_get_level() - $level;
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }

                    FcTest::assertSame(0, $leaked, 'the ' . $name . ' leaves the output buffer level unchanged');
                    FcTest::assert(
                        strpos((string) $html, 'fct_auth_wrap') !== false,
                        'the ' . $name . ' still returns the login view'
                    );
                }
            } finally {
                wp_set_current_user($originalUserId);
            }
        },
    ],
];
