import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { CategoriesList } from "@/BlockEditor/Icons";
import ProductCategoriesListInspectorSettings from "./Components/ProductCategoriesListInspectorSettings";

const { useBlockProps } = wp.blockEditor;
const { registerBlockType } = wp.blocks;
const { useMemo } = wp.element;

const blockEditorData = window.fluent_cart_product_categories_list_data;

/**
 * Converts a flat array of categories into a nested tree structure.
 *
 * Pass 1: Creates a lookup map keyed by term_id, each entry gets an empty children array.
 * Pass 2: Iterates again — if a category has a parent in the map, it's pushed
 *         into that parent's children array. Otherwise it's a root-level category.
 *
 * Flat input:  [{ term_id: 1, parent: 0 }, { term_id: 2, parent: 1 }]
 * Tree output: [{ term_id: 1, children: [{ term_id: 2, children: [] }] }]
 */
function buildTree(categories) {
    // Pass 1: Build a map of term_id -> category (with empty children array)
    const map = {};
    const roots = [];

    categories.forEach(cat => {
        map[cat.term_id] = { ...cat, children: [] };
    });

    // Pass 2: Assign each category to its parent's children, or to roots if top-level
    categories.forEach(cat => {
        if (cat.parent && map[cat.parent]) {
            map[cat.parent].children.push(map[cat.term_id]);
        } else {
            roots.push(map[cat.term_id]);
        }
    });

    return roots;
}

function CategoryItem({ category, showCount, showHierarchy, depth = 0 }) {
    return (
        <li className={`fct-category-item fct-category-item--depth-${depth}`}>
            <span className="fct-category-link-wrap">
                <a href="#" className="fct-category-link" onClick={(e) => e.preventDefault()}>
                    {category.name}
                </a>

                {showCount && (
                    <span className="fct-category-count">&#40;{category.count}&#41;</span>
                )}
            </span>
            {showHierarchy && category.children && category.children.length > 0 && (
                <ul className="fct-categories-children">
                    {category.children.map(child => (
                        <CategoryItem
                            key={child.term_id}
                            category={child}
                            showCount={showCount}
                            showHierarchy={showHierarchy}
                            depth={depth + 1}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: CategoriesList,
    },
    category: "fluent-cart",
    attributes: {
        show_product_count: {
            type: 'boolean',
            default: true
        },
        show_hierarchy: {
            type: 'boolean',
            default: true
        },
        show_empty: {
            type: 'boolean',
            default: false
        },
        display_style: {
            type: 'string',
            default: 'list' // list | dropdown
        }
    },
    supports: {
        html: false,
        alignWide: false,
        typography: {
            fontSize: true,
            lineHeight: true
        },
        color: {
            text: true,
            link: true,
            background: false,
        },
        shadow: false,
    },
    edit: ({ attributes, setAttributes, clientId }) => {
        const { show_product_count, show_hierarchy, show_empty, display_style } = attributes;
        const blockProps = useBlockProps();
        const allCategories = blockEditorData.categories || [];

        // Filtered and structured categories for rendering, recomputed when settings change
        const displayCategories = useMemo(() => {
            // Remove empty categories unless show_empty is enabled
            let filtered = allCategories;
            if (!show_empty) {
                filtered = filtered.filter(cat => cat.count > 0);
            }

            // Nest into parent-child tree or keep flat
            if (show_hierarchy) {
                return buildTree(filtered);
            }

            return filtered.map(cat => ({ ...cat, children: [] }));
        }, [allCategories, show_empty, show_hierarchy]);

        const isEmpty = displayCategories.length === 0;

        return (
            <div {...blockProps}>

                <ProductCategoriesListInspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    clientId={clientId}
                />

                <div className="fct-product-categories-list">
                    {isEmpty ? (
                        <p className="fct-product-categories-list--empty">
                            {blocktranslate('No categories found.', 'fluent-cart')}
                        </p>
                    ) : display_style === 'dropdown' ? (
                        <div className="fct-categories-dropdown-wrap">
                            <select className="fct-categories-dropdown" disabled>
                                <option value="">
                                    {blocktranslate('Select a category', 'fluent-cart')}
                                </option>
                                {displayCategories.map(cat => (
                                    <option key={cat.term_id} value={cat.slug}>
                                        {cat.name} {show_product_count ? `(${cat.count})` : ''}
                                    </option>
                                ))}
                            </select>
                            <button type="button" className="fct-categories-go-btn" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                            </button>
                        </div>
                    ) : (
                        <ul className="fct-categories-list">
                            {displayCategories.map(cat => (
                                <CategoryItem
                                    key={cat.term_id}
                                    category={cat}
                                    showCount={show_product_count}
                                    showHierarchy={show_hierarchy}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        );
    },

    save: function (props) {
        return null;
    },
});
