<?php

namespace FluentCart\App\Services;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Services\Permission\PermissionManager;

class TermProductsService
{
    public static function sync(int $termId, string $taxonomy, array $addIds, array $removeIds): void
    {
        if (!$termId || !PermissionManager::hasPermission(['products/edit'])) {
            return;
        }

        foreach (self::productIds($addIds) as $productId) {
            wp_set_object_terms($productId, [$termId], $taxonomy, true);
        }

        foreach (self::productIds($removeIds) as $productId) {
            wp_remove_object_terms($productId, [$termId], $taxonomy);
        }
    }

    public static function getProducts(int $termId, string $taxonomy, int $limit): array
    {
        $query = new \WP_Query([
            'post_type'      => FluentProducts::CPT_NAME,
            'post_status'    => self::statuses(),
            'posts_per_page' => $limit,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => false,
            'tax_query'      => [[
                'taxonomy'         => $taxonomy,
                'terms'            => [$termId],
                'include_children' => false,
            ]],
        ]);

        $products = array_map(function ($post) {
            return ['id' => $post->ID, 'title' => $post->post_title];
        }, $query->posts);

        return ['products' => $products, 'total' => (int) $query->found_posts];
    }

    // Keeps only real, non-trashed products so a forged ID can't tag other posts.
    private static function productIds(array $ids): array
    {
        $ids = wp_parse_id_list($ids);
        if (!$ids) {
            return [];
        }

        return get_posts([
            'post_type'      => FluentProducts::CPT_NAME,
            'post_status'    => self::statuses(),
            'post__in'       => $ids,
            'posts_per_page' => count($ids),
            'fields'         => 'ids',
        ]);
    }

    private static function statuses(): array
    {
        return array_values(array_diff(get_post_stati(['show_in_admin_all_list' => true]), ['trash']));
    }
}
