<?php

namespace FluentCart\App\Modules\Reviews;

use FluentCart\App\Hooks\Handlers\ReviewCommentHandler;

class ReviewModule
{
    public function register()
    {
        // Isolate fluentcart reviews from WP native comment queries/counts
        (new ReviewCommentHandler())->register();

        // Register reviews as a valid module key (settings managed via dedicated Product Reviews page)
        add_filter('fluent_cart/module_setting/fields', function ($fields) {
            $fields['reviews'] = [
                'title'  => __('Product Reviews', 'fluent-cart'),
                'hidden' => true,
            ];
            return $fields;
        });

        add_filter('fluent_cart/module_setting/default_values', function ($values) {
            if (empty($values['reviews']['active'])) {
                $values['reviews']['active'] = 'yes';
            }
            if (empty($values['reviews']['review_permission_mode'])) {
                $values['reviews']['review_permission_mode'] = 'verified_buyers';
            }
            if (empty($values['reviews']['auto_approve_reviews'])) {
                $values['reviews']['auto_approve_reviews'] = 'no';
            }
            if (empty($values['reviews']['show_verified_badge'])) {
                $values['reviews']['show_verified_badge'] = 'yes';
            }
            if (empty($values['reviews']['enable_star_rating'])) {
                $values['reviews']['enable_star_rating'] = 'yes';
            }
            if (empty($values['reviews']['star_rating_required'])) {
                $values['reviews']['star_rating_required'] = 'yes';
            }
            if (empty($values['reviews']['reviews_per_page'])) {
                $values['reviews']['reviews_per_page'] = 10;
            }
            return $values;
        });
    }
}
