<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Services\Filter\AttrGroupFilter;
use FluentCart\App\Services\Filter\AttrTermFilter;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Vite;
use FluentCart\Database\Seeder\AttributeSeeder;
use FluentCart\Framework\Support\Arr;

class AttributesHandler
{
    public function register()
    {
        // Seed the system-template attribute groups (Color, Size, …) once the
        // attribute tables are migrated. Fires from DBMigrator::migrate() on
        // activation/migrate; the seeder is idempotent (early-returns when any
        // group already exists) so re-runs never duplicate merchant data.
        add_action('fluent_cart/after_migrate', [AttributeSeeder::class, 'seed']);

        add_action('fluent_cart/loading_app', function () {
            Vite::enqueueScript('fluent_cart_attributes', 'attributes/attributes.js');
        });

        // Hide the Attributes submenu item by default on FluentCart admin
        // pages. useNavigationMenuUpdateService toggles it back on whenever the
        // active route's active_menu is 'products', matching the behavior of
        // the existing "↳ Inventory" child.
        add_action('admin_enqueue_scripts', function () {
            wp_register_style('fluent-cart-admin', false);
            wp_enqueue_style('fluent-cart-admin');
            wp_add_inline_style('fluent-cart-admin', '
                .toplevel_page_fluent-cart li.fluent_cart_attributes {
                    display: none;
                }
            ');
        });

        // Show Attributes under Products in the WP admin left sidebar.
        add_action('fluent_cart/admin_submenu_added', function () {
            global $submenu;
            if (!isset($submenu['fluent-cart'])) {
                return;
            }

            $capability = 'manage_options';
            if (!current_user_can('manage_options')) {
                $capability = PermissionManager::ADMIN_CAP;
            }

            $entry = [
                __('↳ Attributes', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/attributes',
                '',
                'fluent_cart_attributes',
            ];

            $newSubmenu = [];
            foreach ($submenu['fluent-cart'] as $key => $item) {
                $newSubmenu[$key] = $item;
                if ($key === 'products' && !isset($newSubmenu['attributes'])) {
                    $newSubmenu['attributes'] = $entry;
                }
            }
            if (!isset($newSubmenu['attributes'])) {
                $newSubmenu['attributes'] = $entry;
            }
            $submenu['fluent-cart'] = $newSubmenu;
        }, 999); // late priority: insert Attributes right after Products AFTER the
                 // Inventory child is placed, so Attributes sits above Inventory.

        add_filter('fluent_cart/admin_filter_options', function ($filterOptions, $args) {
            $filterOptions['attr_groups_filter_options'] = AttrGroupFilter::getTableFilterOptions();
            $filterOptions['attr_terms_filter_options'] = AttrTermFilter::getTableFilterOptions();
            return $filterOptions;
        }, 10, 2);

        add_filter('fluent_cart/admin_table_saved_views', function ($tableConfig, $args) {
            $filterOptions = Arr::get($args, 'filterOptions', []);
            $tableConfig['attr_groups_table'] = ['filters' => Arr::get($filterOptions, 'attr_groups_filter_options', [])];
            $tableConfig['attr_terms_table']  = ['filters' => Arr::get($filterOptions, 'attr_terms_filter_options', [])];
            return $tableConfig;
        }, 10, 2);

        add_filter('fluent_cart/products_filter_options', function ($options) {
            $options['attributes'] = [
                'label'    => __('Attributes', 'fluent-cart'),
                'value'    => 'attributes',
                'children' => [
                    [
                        'label'       => __('Attribute Term', 'fluent-cart'),
                        'value'       => 'attribute_term',
                        'filter_type' => 'custom',
                        'type'        => 'cascading_select',
                        'callback'    => static function ($query, $item) {
                            $groupId = intval(Arr::get($item, 'value.group_id', 0));
                            $termIds = array_filter(array_map('intval', (array) Arr::get($item, 'value.term_ids', [])));
                            if (!$groupId) {
                                return;
                            }
                            $operator = Arr::get($item, 'operator', 'in');
                            $match    = Arr::get($item, 'value.match', 'any');

                            if (empty($termIds)) {
                                // Group selected, no terms: match products that have this attribute at all
                                if ($operator === 'not_in') {
                                    $query->whereDoesntHave('variants', function ($variantQuery) use ($groupId) {
                                        $variantQuery->whereHas('attrRelations', function ($relQuery) use ($groupId) {
                                            $relQuery->where('group_id', $groupId);
                                        });
                                    });
                                } else {
                                    $query->whereHas('variants', function ($variantQuery) use ($groupId) {
                                        $variantQuery->whereHas('attrRelations', function ($relQuery) use ($groupId) {
                                            $relQuery->where('group_id', $groupId);
                                        });
                                    });
                                }
                                return;
                            }

                            if ($operator === 'not_in') {
                                $query->whereDoesntHave('variants', function ($variantQuery) use ($termIds, $groupId) {
                                    $variantQuery->whereHas('attrRelations', function ($relQuery) use ($termIds, $groupId) {
                                        $relQuery->where('group_id', $groupId)->whereIn('term_id', $termIds);
                                    });
                                });
                            } elseif ($match === 'all') {
                                // AND: product must have a variant for each individual term
                                foreach ($termIds as $termId) {
                                    $query->whereHas('variants', function ($variantQuery) use ($termId, $groupId) {
                                        $variantQuery->whereHas('attrRelations', function ($relQuery) use ($termId, $groupId) {
                                            $relQuery->where('group_id', $groupId)->where('term_id', $termId);
                                        });
                                    });
                                }
                            } else {
                                // OR: product must have a variant carrying any of the terms
                                $query->whereHas('variants', function ($variantQuery) use ($termIds, $groupId) {
                                    $variantQuery->whereHas('attrRelations', function ($relQuery) use ($termIds, $groupId) {
                                        $relQuery->where('group_id', $groupId)->whereIn('term_id', $termIds);
                                    });
                                });
                            }
                        },
                    ],
                ],
            ];
            return $options;
        });

        // Attribute groups for the cascading picker (left panel)
        add_filter('fluent_cart/advanced_filter_options_attr_groups', function ($options, $args) {
            $search     = Arr::get($args, 'search', '');
            $includeIds = array_filter(array_map('intval', (array) Arr::get($args, 'include_ids', [])));

            $query = AttributeGroup::query()->orderBy('title', 'asc');

            if ($search) {
                $query->where('title', 'like', '%' . $search . '%');
            }

            if (!empty($includeIds)) {
                $query->orWhereIn('id', $includeIds);
            }

            return $query->limit(200)->get()->map(function ($group) {
                return ['id' => $group->id, 'title' => $group->title];
            })->values()->toArray();
        }, 10, 2);

        // Terms for the selected group (right panel)
        add_filter('fluent_cart/advanced_filter_options_attr_terms', function ($options, $args) {
            $groupId    = intval(Arr::get($args, 'parent_id', 0));
            $search     = sanitize_text_field(Arr::get($args, 'search', ''));
            $includeIds = array_filter(array_map('intval', (array) Arr::get($args, 'include_ids', [])));

            if (!$groupId) {
                return [];
            }

            /** @var AttributeGroup $group */
            $group = AttributeGroup::query()->find($groupId);
            if (!$group) {
                return [];
            }

            $query = $group->terms()->orderBy('serial', 'asc');

            if ($search) {
                $query->where('title', 'like', '%' . $search . '%');
            }

            if (!empty($includeIds)) {
                $query->orWhereIn('id', $includeIds);
            }

            return $query->limit(50)->get()->map(function ($term) {
                return ['id' => $term->id, 'title' => $term->title];
            })->values()->toArray();
        }, 10, 2);
    }
}
