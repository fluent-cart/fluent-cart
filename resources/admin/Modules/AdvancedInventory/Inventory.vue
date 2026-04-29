<template>
    <div class="fct-all-inventory-page fct-layout-width">
        <PageHeading :title="translate('Inventory')">
            <template #action>
                <UserCan permission="products/view">
                    <!-- <ExportModal
                        v-if="shouldShowInventory"
                        :selected-items-count="selectedItemsCount"
                        @export="handleExport"
                    /> -->

                    <!-- <el-button @click="handleImport">
                        {{ translate('Import') }}
                    </el-button> -->
                </UserCan>
            </template>
        </PageHeading>

        <!-- Inventory content - Only load if Pro is active -->
        <UserCan v-if="shouldShowInventory" permission="products/view">
            <div class="fct-inventory-wrap">
                <TableWrapper
                    :table="inventoryTable"
                    :classicTabStyle="true"
                    :has-mobile-slot="true"
                >
                    <InventoryLoader
                        v-if="inventoryTable.isLoading()"
                        :next-page-count="inventoryTable.nextPageCount"
                        :inventory-table="inventoryTable"
                    />
                    <InventoryDataTable
                    v-else
                    :data="inventoryTable.getTableData()"
                    :inventory-table="inventoryTable"
                    @stock-save="handleStockSave"
                    @bulk-update-success="handleBulkUpdateSuccess"
                    @selection-changed="handleSelectionChanged" />

                    <template #mobile>
                        <InventoryDataTable
                        :data="inventoryTable.getTableData()"
                        :inventory-table="inventoryTable"
                        @stock-save="handleStockSave"
                        @bulk-update-success="handleBulkUpdateSuccess"
                        @selection-changed="handleSelectionChanged" />
                    </template>
                </TableWrapper>
            </div>
        </UserCan>

        <!-- Message if Stock Management is disabled -->
        <ModuleDisabledNotice v-else-if="!isStockManagementEnabled" />

        <!-- Pro Feature Notice -->
        <div v-else class="py-10">
            <ProFeatureNotice
                :title="translate('Advanced Inventory')"
            >
                <p class="fct-pro-feature-text">
                    {{ translate('This feature is only available in FluentCart Pro with ') }}
                    <router-link to="/settings/addons" target="_blank" rel="noopener noreferrer">
                        {{ translate('Advanced Inventory enabled') }}
                    </router-link>
                </p>
            </ProFeatureNotice>
        </div>
    </div>
</template>

<script setup>
import { defineOptions, getCurrentInstance, ref, computed, onMounted } from 'vue';
import translate from '@/utils/translator/Translator';
import PageHeading from '@/Bits/Components/Layout/PageHeading.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import ProFeatureNotice from '@/Bits/Components/ProFeatureNotice.vue';
import TableWrapper from '@/Bits/Components/TableNew/TableWrapper.vue';
import InventoryDataTable from './InventoryTable.vue';
import InventoryLoader from './InventoryLoader.vue';
import ExportModal from './ExportModal.vue';
import ModuleDisabledNotice from './ModuleDisabledNotice.vue';
import useInventoryTable from '@/utils/table-new/InventoryTable';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import AppConfig from '@/utils/Config/AppConfig';

defineOptions({
    name: 'Inventory'
});

// Get Pro status from app config
const appConfig = AppConfig.get('app_config');
const isProActive = computed(() => appConfig?.isProActive);

// Get module settings
const modulesSettings = AppConfig.get('modules_settings');
const isStockManagementEnabled = computed(() => {
    return modulesSettings?.stock_management?.active === 'yes';
});

const isAdvancedInventoryEnabled = computed(() => {
    return modulesSettings?.stock_management?.enable_advanced_inventory === 'yes';
});

// Only show inventory if Pro is active, Stock Management is enabled, and Advanced Inventory is enabled
const shouldShowInventory = computed(() => {
    return isProActive.value && isStockManagementEnabled.value && isAdvancedInventoryEnabled.value;
});


// Initialize table without fetching initially
const inventoryTable = useInventoryTable({
    instance: getCurrentInstance(),
    fetch: false
});

const selectedItemsCount = ref(0);
const selectedItems = ref([]);

const handleSelectionChanged = (data) => {
    selectedItemsCount.value = data.count;
    selectedItems.value = data.selectedItems || [];
};

const handleBulkUpdateSuccess = () => {
    inventoryTable.fetch();
};

const handleStockSave = (event) => {
    
    const { variant, newStock, reason, customReason } = event;

    let adjustedStock = parseInt(newStock);
    variant.total_stock = (adjustedStock < 0) ? 0 : adjustedStock;

    let available = parseInt(variant.total_stock) - parseInt(variant.committed) - parseInt(variant.on_hold);
    variant.available = available < 0 ? 0 : available;

    Rest.post('inventory/update-stock', {
        variant_id: variant.id,
        post_id: variant.post_id,
        new_stock: variant.total_stock,
        reason: reason,
        customReason: customReason || ''
    })
        .then(response => {
            Notify.success(translate(response.message));
        })
        .catch((errors) => {
            if (errors.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors.data?.message);
            }
        });
};

const handleImport = () => {
    // TODO: Implement import functionality
    console.log('Import inventory data');
};

const handleExport = (data) => {
    const payload = {
        scope: data.scope,
        inventoryState: data.inventoryState,
        format: data.format,
        items: selectedItems.value.map(item => ({
            id: item.id,
            post_id: item.post_id
        }))
    };

    Rest.post('inventory/export', payload)
        .then(response => {
            downloadFile(response.csvData, response.filename);
            Notify.success(translate('Export downloaded successfully'));
        })
        .catch(error => {
            Notify.error(error.data?.message || translate('Export failed'));
        });
};

const downloadFile = (csvData, filename) => {
    const blob = new Blob([csvData], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
};


// Fetch inventory data only if Pro is active
onMounted(() => {
    if (shouldShowInventory.value) {
        inventoryTable.fetch();
    }
});
</script>

