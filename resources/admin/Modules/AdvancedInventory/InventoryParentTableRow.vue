<template>
    <!-- Parent Row -->
    <tr class="fct-inventory-row fct-inventory-parent-row">
        <td width="50">
            <div class="fct-inventory-row-selection">
                <el-checkbox
                    :model-value="isAllChildrenSelected()"
                    :indeterminate="isSomeChildrenSelected()"
                    @change="toggleAllChildren"
                    @click.stop
                />

                <div @click="toggleRow()" class="fct-inventory-row-toggle">
                    <DynamicIcon
                        :name="isExpanded() ? 'ChevronDown' : 'ChevronRight'"
                    />
                </div>
                
            </div>
        </td>

        <td>
            <div
                class="fct-inventory-product-info cursor-pointer"
                @click="navigateToProduct(row.ID)"
            >
                <img 
                    v-if="row.thumbnail !== null"
                    :src="row.thumbnail"
                    :alt="row.post_title"
                    class="inventory-thumbnail"
                />

                <img 
                    v-else 
                    class="inventory-thumbnail"
                    :src="getPlaceholderImage()" 
                    :alt="row.post_title" 
                />

                <div class="fct-inventory-product-content">
                    <span class="fct-inventory-product-title">
                        {{ row.post_title }}
                    </span>
                </div>
            </div>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('sku')">
            <span v-show="!isExpanded()">--</span>
        </td>

        <td class="!pl-5">
            <span v-show="!isExpanded()">{{ sumTotalStock }}</span>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('available')">
            <span v-show="!isExpanded()">{{ sumAvailable }}</span>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('on_hand')">
            <span v-show="!isExpanded()">{{ sumOnHold }}</span>
        </td>

        <td v-if="inventoryTable?.isColumnVisible('committed')">
            <span v-show="!isExpanded()">{{ sumCommitted }}</span>
        </td>

        <td></td>
    </tr>

    <!-- Child Rows (Only when expanded) -->
    <tr
        v-for="child in row.variants"
        :key="`child-${child.id}`"
        v-show="row.variants?.length > 0 && isExpanded()"
        class="fct-inventory-child-row"
    >
        <td></td>
        <td>
            <div class="fct-inventory-variant-item">
                <el-checkbox
                    :model-value="isRowSelected(child)"
                    @change="toggleRowSelection(child)"
                />

                <div class="fct-inventory-product-info">
                    <img
                        v-if="child.thumbnail !== null"
                        :src="child.thumbnail"
                        :alt="child.variation_title"
                        class="inventory-thumbnail"
                    />

                    <img
                        v-else 
                        class="inventory-thumbnail"
                        :src="getPlaceholderImage()" 
                        :alt="child.variation_title" 
                    />

                    <div class="fct-inventory-product-content">
                        <router-link :to="`/products/${child.post_id}`" class="fct-inventory-product-title">
                            {{ child.variation_title }}
                        </router-link>
                    </div>
                </div>
            </div>
        </td>
        <td v-if="inventoryTable?.isColumnVisible('sku')">{{ child.sku }}</td>
        <td>
            <el-input
                v-model="child.total_stock"
                class="el-input--x-small input-with-total-stock fct-input-group"
                readonly
                size="small"
            >
                <template #append>
                    <InventoryStockAdjuster
                        :variant="child"
                        @save="$emit('stock-save', $event)"
                    />
                </template>
            </el-input>
        </td>
        <td v-if="inventoryTable?.isColumnVisible('available')">
            {{ child.available }}
        </td>
        <td v-if="inventoryTable?.isColumnVisible('on_hand')">
            {{ child.on_hold }}
        </td>
        <td v-if="inventoryTable?.isColumnVisible('committed')">
            {{ child.committed }}
        </td>
        <td>
            <IconButton
                tag="button"
                size="small"
                @click="$emit('open-history', child)"
            >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10 2.5C14.1422 2.5 17.5 5.85775 17.5 10C17.5 14.1422 14.1422 17.5 10 17.5C5.85775 17.5 2.5 14.1422 2.5 10H4C4 13.3135 6.6865 16 10 16C13.3135 16 16 13.3135 16 10C16 6.6865 13.3135 4 10 4C7.9375 4 6.118 5.04025 5.03875 6.625H7V8.125H2.5V3.625H4V5.5C5.368 3.6775 7.54675 2.5 10 2.5ZM10.75 6.25V9.68875L13.1823 12.121L12.121 13.1823L9.25 10.3098V6.25H10.75Z" fill="currentColor"/>
                </svg>
            </IconButton>
        </td>
    </tr>

    <!-- Load more variants (only when the capped list has more on the server) -->
    <tr v-if="hasMoreVariants" v-show="isRowExpanded" class="fct-inventory-child-row fct-inventory-load-more-row">
        <td></td>
        <td :colspan="loadMoreColspan">
            <button
                class="fct-inventory-load-more-btn"
                :disabled="loadingMore"
                @click="loadMoreVariants"
            >
                <DynamicIcon name="Reload" :class="{ 'fct-spin': loadingMore }"/>
                <template v-if="loadingMore">{{ translate('Loading...') }}</template>
                <template v-else>
                    {{ translate('Load More') }}
                    <span class="fct-inventory-load-more-count">({{ loadedVariantsCount }}/{{ totalVariantsCount }})</span>
                </template>
            </button>
        </td>
    </tr>
</template>

<script setup>
import { defineOptions, ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import InventoryStockAdjuster from './InventoryStockAdjuster.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';

useRouter();

defineOptions({
    name: 'InventoryParentTableRow'
});

const props = defineProps({
    row: {
        type: Object,
        required: true
    },
    inventoryTable: Object,
    navigateToProduct: {
        type: Function,
        required: true
    },
    getPlaceholderImage: {
        type: Function,
        required: true
    },
    isRowSelected: {
        type: Function,
        required: true
    },
    toggleRowSelection: {
        type: Function,
        required: true
    },
    expanded: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['stock-save', 'open-history', 'variants-loaded']);

const localExpanded = ref(true);

const isRowExpanded = computed(() => {
    // If user manually toggled individual row, use local state
    // Otherwise sync with parent's global state
    return localExpanded.value;
});

watch(() => props.expanded, (newVal) => {
    localExpanded.value = newVal;
});

const toggleRow = () => {
    localExpanded.value = !localExpanded.value;
};

const isExpanded = () => {
    return isRowExpanded.value;
};

// Parent-row stock totals. The variant list is capped server-side, so the
// SQL aggregates in row.stock_totals are the truth; summing loaded rows
// remains only as a fallback for a stale pro backend that doesn't send them.
// Staleness after an inline adjustment is handled at the source: the
// update-stock endpoint returns fresh product aggregates and Inventory.vue
// writes them onto row.stock_totals, which also picks up concurrent edits
// by other admins — no client-side drift accounting.
const stockTotal = (key) => {
    const aggregate = props.row.stock_totals?.[key];
    if (aggregate !== null && aggregate !== undefined) {
        return aggregate;
    }
    return props.row.variants?.reduce((sum, child) => sum + (child[key] || 0), 0) || 0;
};

const sumTotalStock = computed(() => stockTotal('total_stock'));
const sumAvailable = computed(() => stockTotal('available'));
const sumOnHold = computed(() => stockTotal('on_hold'));
const sumCommitted = computed(() => stockTotal('committed'));

// =============================
// LOAD MORE VARIANTS
// =============================
const VARIANTS_PER_PAGE = 10;
const loadingMore = ref(false);

const loadedVariantsCount = computed(() => props.row.variants?.length || 0);

const totalVariantsCount = computed(() => {
    return props.row.variants_total ?? loadedVariantsCount.value;
});

const hasMoreVariants = computed(() => loadedVariantsCount.value < totalVariantsCount.value);

// Product cell + Total Stock cell + actions cell are fixed (the selection
// cell is the row's own first <td>); the toggleable count is derived from
// the table's OWN column definition filtered by visibility — data.columns
// alone is hydrated from localStorage and can carry stale names that no
// longer render a cell, which would overshoot the span.
const loadMoreColspan = computed(() => {
    const toggleable = props.inventoryTable?.getToggleableColumns?.() || [];
    const visible = toggleable.filter((column) => props.inventoryTable?.isColumnVisible(column.value)).length;
    return 3 + visible;
});

const loadMoreVariants = () => {
    if (loadingMore.value) {
        return;
    }
    loadingMore.value = true;

    // Keyset continuation: ask for rows strictly AFTER the last one we hold
    // (both sides order by id ASC). Offset pages shift when a variant is
    // deleted concurrently — rows can fall between pages and a count-derived
    // page number can refetch the same page forever; after_id can do neither.
    // `page` rides along as the documented offset fallback: a backend with
    // keyset support prefers after_id and ignores page; one without it pages
    // by offset and the id-dedupe below absorbs the overlap — either contract
    // generation makes forward progress.
    const loaded = props.row.variants || [];
    const lastLoadedId = loaded.length ? loaded[loaded.length - 1].id : 0;

    Rest.get('inventory/variants', {
        post_id: props.row.ID,
        after_id: lastLoadedId,
        page: Math.floor(loaded.length / VARIANTS_PER_PAGE) + 1,
        per_page: VARIANTS_PER_PAGE
    })
        .then((response) => {
            const fetched = response?.variants?.data || [];
            const loadedIds = new Set((props.row.variants || []).map((variant) => variant.id));

            // `row` is shared reactive table state owned by the inventory
            // table store; appending in place AFTER server confirmation is the
            // established pattern (see ProductStatus.vue, SingleFileForm.vue).
            fetched.forEach((variant) => {
                if (!loadedIds.has(variant.id)) {
                    // eslint-disable-next-line vue/no-mutating-props
                    props.row.variants.push(variant);
                }
            });

            // The response total is the server's truth either way: it retires
            // the button when the list is genuinely complete (a stale count
            // after concurrent deletions) and — unlike snapping the count to
            // what we loaded — it can never hide variants that still exist.
            if (response?.variants?.total !== undefined) {
                // eslint-disable-next-line vue/no-mutating-props
                props.row.variants_total = response.variants.total;
            }

            // The header select-all state is computed over loaded items —
            // let the table reconcile it against the appended rows.
            emit('variants-loaded');
        })
        .catch((errors) => {
            if (errors.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors.data?.message || translate('Failed to load more variants'));
            }
        })
        .finally(() => {
            loadingMore.value = false;
        });
};

const isAllChildrenSelected = () => {
    const variants = props.row.variants || [];
    return variants.length > 0 && variants.every(child => props.isRowSelected(child));
};

const isSomeChildrenSelected = () => {
    const variants = props.row.variants || [];
    const selectedCount = variants.filter(child => props.isRowSelected(child)).length;
    return selectedCount > 0 && selectedCount < variants.length;
};

const toggleAllChildren = (value) => {
    props.row.variants?.forEach(child => {
        if (value !== props.isRowSelected(child)) {
            props.toggleRowSelection(child);
        }
    });
};
</script>
