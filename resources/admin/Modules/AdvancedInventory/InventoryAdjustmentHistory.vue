<template>
    <el-drawer
        v-model="drawerVisible"
        :title="translate('Stock Adjustment History')"
        @close="handleClose"
    >
        <!-- Selected Variant Info -->
        <div class="fct-adjustment-header">
            <h3 class="fct-adjustment-product-name">
                {{ selectedVariant.name }}
            </h3>
        </div>

        <!-- Adjustments Timeline -->
        <div v-if="!isLoading" class="fct-adjustment-timeline-wrap">
            <template v-if="adjustments.length > 0">
                <div class="fct-adjustment-timeline">
                    <div
                        v-for="(adjustment, index) in adjustments"
                        :key="index"
                        class="fct-timeline-item"
                    >
                        <!-- Timeline Node (Dot) -->
                        <div class="fct-timeline-node">
                            <span class="fct-node-dot" :class="`node-${adjustment.reason}`"></span>
                        </div>

                        <!-- Timeline Content -->
                        <div class="fct-timeline-content">
                            <!-- Header: Time + User -->
                            <div class="fct-timeline-header">
                                <span class="fct-timeline-time">
                                    <ConvertedTime :date-time="adjustment.created_at" /> (by {{ adjustment.user_name }})
                                </span>
                                <span class="fct-timeline-reason-badge" :class="`badge-${adjustment.reason}`">
                                    {{ adjustment.reason_label }}
                                </span>
                            </div>

                            <!-- Stock Flow Details -->
                            <div class="fct-stock-flow">
                                <div class="fct-stock-flow-item">
                                    <div class="fct-stock-entry">
                                        <span class="fct-stock-label">
                                            {{ translate('Previous Stock:') }}
                                        </span>
                                        <span class="fct-stock-value">
                                            {{ adjustment.old_stock }}
                                        </span>
                                    </div>

                                    <span class="fct-stock-arrow">→</span>

                                    <div class="fct-stock-entry new-stock-entry">
                                        <span class="fct-stock-label">
                                            {{ translate('New Stock:') }}
                                        </span>
                                        <span class="fct-stock-value">
                                            {{ adjustment.new_stock }}
                                        </span>
                                    </div>
                                </div>

                                <div class="fct-stock-flow-item">
                                    <span class="fct-stock-label">
                                        {{ translate('Change:') }}
                                    </span>
                                    <span
                                        class="fct-stock-value fct-stock-change"
                                        :class="getChangeClass(adjustment.change)"
                                    >
                                        {{ formatChange(adjustment.change) }} {{ translate('units') }}
                                    </span>
                                </div>

                                <!-- Custom Reason (if reason is "other") -->
                                <div 
                                    v-if="adjustment.reason === 'other' && adjustment.custom_reason"
                                    class="fct-custom-reason-section"
                                >
                                    <span class="fct-stock-label">
                                        {{ translate('Details:') }}
                                    </span>
                                    
                                    <p class="fct-custom-reason-text">
                                        {{ adjustment.custom_reason }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div v-else class="fct-timeline-empty">
                <Empty icon="Empty/ListView" :has-dark="true" :text="translate('No changes recorded')" />
            </div>
        </div>
        <div v-else class="fct-timeline-loading">
            <p>{{ translate('Loading adjustment history...') }}</p>
        </div>
    </el-drawer>
</template>

<script setup>
import { defineOptions, ref } from 'vue';
import translate from '@/utils/translator/Translator';
import ConvertedTime from '@/Bits/Components/ConvertedTime.vue';
import Empty from "@/Bits/Components/Table/Empty.vue";
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';

defineOptions({
    name: 'InventoryAdjustmentHistory'
});

const drawerVisible = ref(false);
const isLoading = ref(false);

const selectedVariant = ref({
    id: null,
    name: ''
});

const adjustments = ref([]);


const handleClose = () => {
    drawerVisible.value = false;
    adjustments.value = [];
};

// Expose method to open drawer and fetch data
const openHistory = (variant) => {
    selectedVariant.value = variant;
    drawerVisible.value = true;
    fetchAdjustmentHistory(variant.id);
};

const fetchAdjustmentHistory = (variantId) => {
    if (!variantId) return;

    isLoading.value = true;
    Rest.get('inventory/adjustment-history', {
        variant_id: variantId
    })
        .then(response => {
            adjustments.value = response?.adjustments || [];
        })
        .catch(() => {
            Notify.error(translate('Failed to load adjustment history'));
        })
        .finally(() => {
            isLoading.value = false;
        });
};

const getChangeClass = (change) => {
    if (change > 0) return 'positive';
    if (change < 0) return 'negative';
    return '';
};

const formatChange = (change) => {
    return change > 0 ? `+${change}` : `${change}`;
};

defineExpose({
    openHistory
});
</script>


