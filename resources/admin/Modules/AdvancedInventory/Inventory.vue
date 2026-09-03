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
                placement="feature_lock_advanced_inventory"
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
import { defineOptions, getCurrentInstance, provide, reactive, ref, computed, onMounted } from 'vue';
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
    name: 'AdvancedInventory'
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

// One in-flight save per PRODUCT, with per-variant latest-wins queueing (the
// codebase's pendingSave pattern, widened one level). Product-level
// serialization matters for the aggregates: every update-stock response
// carries the product's stock_totals computed after ITS commit — two
// parallel saves on different variants of one product could deliver an
// older aggregate after a newer one and quietly roll the parent row back.
// With one request in flight per product, the last response to arrive is
// always the last commit. A drop-stale token guard is not equivalent: it
// could discard a SUCCESSFUL older response when the newest request failed,
// leaving committed server state hidden.
const stockSaveQueues = new Map(); // post_id -> { inFlight, pending: Map<variantId, save> }

// Id-keyed pending set the adjuster popovers inject: unlike a flag written
// onto a variant object, ids survive a table refetch replacing the objects,
// so Apply stays locked for a variant whose save is still in flight even on
// a freshly fetched row.
const pendingStockSaves = reactive(new Set());
provide('fctPendingStockSaves', pendingStockSaves);

const handleStockSave = (event) => {
    const { variant, newStock, reason, customReason } = event;

    // An empty/invalid quantity must never coerce to 0 — that would silently
    // zero out inventory. Reject it the way the server would.
    const requestedStock = parseInt(newStock);
    if (isNaN(requestedStock) || requestedStock < 0) {
        Notify.error(translate('Please enter a valid stock quantity'));
        return;
    }

    // Ids only — a table refetch replaces the objects mid-flight, so live
    // objects are resolved by id at write-back time, never captured.
    const save = {
        variantId: variant.id,
        postId: variant.post_id,
        newStock: requestedStock,
        reason: reason,
        customReason: customReason || ''
    };

    const state = stockSaveQueues.get(save.postId) || { inFlight: false, pending: new Map() };
    stockSaveQueues.set(save.postId, state);

    // While a save is in flight or queued, that variant's adjuster inputs
    // would seed from unconfirmed stock — a second relative adjustment
    // computed from a stale base silently drops the pending one — so its
    // popover disables Apply until confirmation lands.
    pendingStockSaves.add(save.variantId);

    if (state.inFlight) {
        state.pending.set(save.variantId, save); // latest wins per variant
        return;
    }

    state.inFlight = true;
    dispatchStockSave(save, state);
};

// A refetch (e.g. the bulk-update flow) replaces row and variant objects
// while a save can still be in flight — writing confirmed values onto the
// object captured at dispatch time would update a detached copy the table
// no longer renders. Resolve the CURRENT objects by id at write-back time.
const resolveLiveRow = (postId) => {
    return inventoryTable.getTableData().find((candidate) => candidate.ID === postId);
};

const resolveLiveVariant = (postId, variantId, fallback) => {
    const row = resolveLiveRow(postId);
    return row?.variants?.find((candidate) => candidate.id === variantId) || fallback;
};

const dispatchStockSave = (save, state) => {
    Rest.post('inventory/update-stock', {
        variant_id: save.variantId,
        post_id: save.postId,
        new_stock: save.newStock,
        reason: save.reason,
        customReason: save.customReason
    })
        .then(response => {
            // Saves are serialized per product, so this response is the
            // authoritative latest. Write back the CANONICAL values the
            // server returned; an older pro backend that doesn't return
            // `available` gets the pre-existing client-side recompute so the
            // Available cell never goes stale against it.
            const liveVariant = resolveLiveVariant(save.postId, save.variantId, null);
            if (liveVariant) {
                const confirmedStock = parseInt(response.new_stock);
                liveVariant.total_stock = isNaN(confirmedStock) ? save.newStock : confirmedStock;
                if (response.available !== undefined) {
                    liveVariant.available = parseInt(response.available) || 0;
                } else {
                    const derived = liveVariant.total_stock
                        - (parseInt(liveVariant.committed) || 0)
                        - (parseInt(liveVariant.on_hold) || 0);
                    liveVariant.available = derived < 0 ? 0 : derived;
                }
            }

            // Fresh product aggregates from the same response keep the
            // collapsed parent row honest without a refetch — including
            // concurrent edits by other admins. Absent on an older pro
            // backend, where the parent sums loaded variants reactively.
            if (response.stock_totals) {
                const row = resolveLiveRow(save.postId);
                if (row && row.stock_totals) {
                    row.stock_totals = response.stock_totals;
                }
            }

            // An older backend may omit `message` — never let the toast throw
            // inside .then, where it would be misreported as a request failure.
            Notify.success(response.message ? translate(response.message) : translate('Stock updated successfully'));
        })
        .catch((errors) => {
            // A queued successor for this variant supersedes this request —
            // its own outcome will be reported; a toast here would mislead.
            if (state.pending.has(save.variantId)) {
                return;
            }
            if (errors?.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors?.data?.message || translate('Failed to update stock'));
            }
        })
        .finally(() => {
            // This variant's save chain is done unless a newer one is queued.
            if (!state.pending.has(save.variantId)) {
                pendingStockSaves.delete(save.variantId);
                const liveVariant = resolveLiveVariant(save.postId, save.variantId, null);
                if (liveVariant) {
                    liveVariant.stock_save_pending = false;
                }
            }

            const nextEntry = state.pending.entries().next().value;
            if (nextEntry) {
                state.pending.delete(nextEntry[0]);
                dispatchStockSave(nextEntry[1], state); // stays in flight
            } else {
                state.inFlight = false;
                // Self-cleaning: an idle entry carries no state worth keeping,
                // and the map would otherwise grow per product ever edited.
                stockSaveQueues.delete(save.postId);
            }
        });
};

const handleImport = () => {
    // TODO: Implement import functionality
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

