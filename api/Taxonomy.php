<?php

namespace FluentCart\Api;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\WpModels\Term;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\Str;

class Taxonomy
{
    public static function getTaxonomies($objectType = 'fluent-products', $publicOnly = true): array
    {
        $args = [
            'object_type' => [$objectType]
        ];

        if ($publicOnly) {
            $args['public'] = true;
        }

        return get_taxonomies($args);
    }

    public static function syncTaxonomyTermsToProduct(
        $productId, string $taxonomy, array $terms
    )
    {
        if (!is_taxonomy_hierarchical($taxonomy)) {
            $terms = Term::query()->find($terms)->pluck('name')->toArray();
        }

        return wp_set_post_terms($productId, $terms, $taxonomy);
    }

    public static function deleteTaxonomyTermFromProduct(
        $productId, string $taxonomy, int $term
    )
    {
        return wp_remove_object_terms($productId, $term, $taxonomy);
    }

    public static function deleteAllTermRelationshipsFromProduct($productId, string $taxonomy)
    {
        return wp_delete_object_term_relationships($productId, $taxonomy);
    }

    public static function getFormattedTerms(
        $taxonomy,
        $hideEmpty = false,
        $parents = null,
        $valueKey = 'value',
        $labelKey = 'label',
        $prefilled = null
    ): array
    {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => $hideEmpty,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }


        // Stores all terms with an empty children array.
        $termMap = [];

        foreach ($terms as $term) {

            $termMap[$term->term_id] = [
                $valueKey  => (string)$term->term_id,
                $labelKey  => $term->name,
                'parent'   => (string)$term->parent,
                'children' => []
            ];
        }

        // Organize terms into a nested parent-child structure
        // Iterates through $termMap and attaches each child term to its parent’s children array.
        $formattedTerms = [];

        foreach ($termMap as &$term) {
            // If the term has a parent and the parent exists in termMap, add it as a child
            if ($term['parent'] != "0" && isset($termMap[$term['parent']])) {
                $termMap[$term['parent']]['children'][] = &$term;
            } else {
                // Otherwise, add it to the top-level array (root categories)
                $formattedTerms[] = &$term;
            }
        }

        // Mark prefilled terms as selected
        // Adds a selected key if the term ID is found in $prefilled.
        if (!empty($prefilled)) {
            foreach ($formattedTerms as &$term) {
                // Check if the current term should be selected
                if (isset($term['value'])) {
                    $term['selected'] = (is_array($prefilled) && in_array($term['value'], $prefilled)) || $term['value'] == $prefilled;
                }

                // If the term has children, apply the same logic recursively
                if (!empty($term['children'])) {
                    foreach ($term['children'] as &$childTerm) {
                        // Check if the child term should be selected
                        if (isset($childTerm['value'])) {
                            $childTerm['selected'] = (is_array($prefilled) && in_array($childTerm['value'], $prefilled)) || $childTerm['value'] == $prefilled;
                        }
                    }
                }
            }

        }

        return $formattedTerms;
    }

    public static function getTermIdsFromTerms(array $terms): array
    {
        return Collection::make($terms)->pluck('term_id')->toArray();
    }

    public static function addTaxonomyTerms(string $taxonomy, array $terms, array $args = [])
    {
        $taxonomies = static::getTaxonomies();
        if (!Arr::has($taxonomies, $taxonomy)) {
            return false;
        }

        $termIds = [];

        foreach ($terms as $term) {
            if (term_exists($term, $taxonomy)) {
                $termIds[] = get_term_by('name', $term, $taxonomy)->term_id;
            } else if (is_array($newTerm = wp_insert_term($term, $taxonomy, $args))) {
                $termIds[] = $newTerm['term_id'];
            }
        }

        return $termIds;
    }

    public static function getTaxonomyLabels($taxonomy, $keys = [])
    {
        $taxonomy_object = get_taxonomy($taxonomy);

        if ($taxonomy_object) {
            // Get all labels as an array
            $all_labels = (array)get_taxonomy_labels($taxonomy_object);

            $desired_keys = [
                'all_items',
                'add_new_item',
                'edit_item',
                'name',
                'not_found',
                'parent_item',
                'search_items',
                'update_item',
                'view_item',
                'singular_name',
                'no_terms',
            ];

            $desired_keys = Arr::mergeMissingValues($desired_keys, $keys);

            $collectionInstance = new Collection($all_labels);
            $labels = $collectionInstance->only($desired_keys)->toArray();
        } else {
            $labels = $keys;
        }

        return $labels;
    }


    public static function fetchTaxonomies($data): ?array
    {
        $search = sanitize_text_field(Arr::get($data, 'search', ''));
        $page = (int)sanitize_text_field(Arr::get($data, 'page', 1));
        $per_page = (int)sanitize_text_field(Arr::get($data, 'per_page', 10));
        $sort_by = sanitize_text_field(Arr::get($data, 'sort_by', 'name'));
        $sort_type = sanitize_text_field(Arr::get($data, 'sort_type', 'DESC'));
        $taxonomy = sanitize_text_field(Arr::get($data, 'taxonomy', 'product-categories'));

        $offset = ($page - 1) * $per_page;

        $total = (int)wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        if (!empty($search)) {
            $search_results = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'search'     => $search,
                'fields'     => 'ids',
            ]);

            if (is_wp_error($search_results)) {
                wp_send_json([
                    'message' => __('Failed to fetch search results.', 'fluent-cart'),
                ], 500);
            }

            $total = count($search_results);
        }

        $labels = self::getTaxonomyLabels($taxonomy);

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => $sort_by,
            'order'      => $sort_type,
            'number'     => $per_page,
            'offset'     => $offset,
            'search'     => $search
        ]);

        if (is_wp_error($terms)) {
            return wp_send_json([
                /* translators: %s - taxonomy name */
                'message' => sprintf(__('Failed to fetch %s.', 'fluent-cart'), Str::lower($labels['name'])),
            ], 500);
        }

        // Initialize an array to hold the terms by their IDs
        $terms_by_id = [];
        foreach ($terms as $term) {
            $terms_by_id[$term->term_id] = [
                'term_id'     => $term->term_id,
                'name'        => $term->name,
                'description' => $term->description,
                'slug'        => $term->slug,
                'parent'      => $term->parent,
                'count'       => $term->count,
                'children'    => [],
                'url'         => get_term_link($term->term_id),
            ];
        }

        // Build the hierarchy
        $hierarchy = [];
        foreach ($terms_by_id as $term_id => &$term_data) {
            // If the term has a parent and the parent exists in the array, add it to the parent's children.
            if ($term_data['parent'] && isset($terms_by_id[$term_data['parent']])) {
                $terms_by_id[$term_data['parent']]['children'][] = &$term_data;
            } else {
                // If the term has no parent, it is added as a top-level category.
                $hierarchy[] = &$term_data;
            }
        }

        // Calculate total pages
        $last_page = ceil($total / $per_page);

        // Calculate "from" and "to"
        $from = ($total > 0) ? (($page - 1) * $per_page) + 1 : 0;
        $to = min($page * $per_page, $total);

        return [
            "taxonomies" => [
                "data"         => $hierarchy,
                "total"        => $total,
                "current_page" => $page,
                "last_page"    => $last_page,
                "per_page"     => $per_page,
                "from"         => $from,
                "to"           => $to,
                "labels"       => $labels,
            ],
        ];
    }


    public static function taxonomyWithTerms(): Collection
    {
        $taxonomies = Taxonomy::getTaxonomies();

        return Collection::make($taxonomies)
            ->map(function ($taxonomy) {
                return [
                    'name'   => $taxonomy,
                    'label'  => Str::headline($taxonomy),
                    'parent' => 0,
                    'terms'  => Taxonomy::getFormattedTerms($taxonomy, false, null, 'value', 'label'),
                ];
            });

    }

}
