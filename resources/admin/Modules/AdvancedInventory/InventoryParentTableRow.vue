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
</template>

<script setup>
import { defineOptions, ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import InventoryStockAdjuster from './InventoryStockAdjuster.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';

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

defineEmits(['stock-save', 'open-history']);

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

// Calculate sum of all variants' stock values
const sumTotalStock = computed(() => {
    return props.row.variants?.reduce((sum, child) => sum + (child.total_stock || 0), 0) || 0;
});

const sumAvailable = computed(() => {
    return props.row.variants?.reduce((sum, child) => sum + (child.available || 0), 0) || 0;
});

const sumOnHold = computed(() => {
    return props.row.variants?.reduce((sum, child) => sum + (child.on_hold || 0), 0) || 0;
});

const sumCommitted = computed(() => {
    return props.row.variants?.reduce((sum, child) => sum + (child.committed || 0), 0) || 0;
});

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
