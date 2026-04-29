<template>
    <div class="fct-inventory-stats-wrap">
        <CardContainer>
            <CardHeader
                :title="translate('Inventory Stats Overview')"
                border_bottom
                title_size="small"
            />

            <CardBody>
                <SummaryLoader v-if="isLoading" />

                <div v-else class="fct-inventory-stats-row">
                    <div class="fct-inventory-stats-card total-variants-stats">
                        <div class="fct-inventory-stats-head">
                            <div class="fct-inventory-stats-icon">
                                <DynamicIcon name="ShoppingBag" />
                            </div>
                            <div class="fct-inventory-stats-content">
                                <div class="title">{{ translate('Total Variants') }}</div>
                                <div class="value">{{ stats.totalVariants }}</div>
                            </div>
                        </div>
                        <div class="text">{{ translate('All products') }}</div>
                    </div>

                    <div class="fct-inventory-stats-card in-stock-stats">
                        <div class="fct-inventory-stats-head">
                            <div class="fct-inventory-stats-icon">
                                <DynamicIcon name="CheckCircle" />
                            </div>
                            <div class="fct-inventory-stats-content">
                                <div class="title">{{ translate('In Stock') }}</div>
                                <div class="value">{{ stats.inStock }}</div>
                            </div>
                        </div>
                        <div class="text">{{ calculatePercentage(stats.inStock) }}% {{ translate('of variants') }}</div>
                    </div>

                    <div class="fct-inventory-stats-card low-stock-stats">
                        <div class="fct-inventory-stats-head">
                            <div class="fct-inventory-stats-icon">
                                <DynamicIcon name="Warning" />
                            </div>
                            <div class="fct-inventory-stats-content">
                                <div class="title">{{ translate('Low Stock') }}</div>
                                <div class="value">{{ stats.lowStock }}</div>
                            </div>
                        </div>
                        <div class="text">{{ calculatePercentage(stats.lowStock) }}% {{ translate('of variants') }}</div>
                    </div>

                    <div class="fct-inventory-stats-card out-of-stock-stats">
                        <div class="fct-inventory-stats-head">
                            <div class="fct-inventory-stats-icon">
                                <DynamicIcon name="CircleClose" />
                            </div>
                            <div class="fct-inventory-stats-content">
                                <div class="title">{{ translate('Out of Stock') }}</div>
                                <div class="value">{{ stats.outOfStock }}</div>
                            </div>
                        </div>
                        <div class="text">{{ calculatePercentage(stats.outOfStock) }}% {{ translate('of variants') }}</div>
                    </div>
                </div>
            </CardBody>
        </CardContainer>
    </div>
</template>

<script setup>
import { defineOptions, onMounted, ref, computed } from 'vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SummaryLoader from './SummaryLoader.vue';

defineOptions({
    name: 'InventorySummaryCards'
});

const stats = ref({
    totalVariants: 0,
    inStock: 0,
    lowStock: 0,
    outOfStock: 0
});

const isLoading = ref(true);

const calculatePercentage = computed(() => {
    return (value) => {
        if (stats.value.totalVariants === 0) return 0;
        return Math.round((value / stats.value.totalVariants) * 100);
    };
});

const fetchStats = () => {
    isLoading.value = true;

    Rest.get('inventory/stats')
        .then((response) => {
            if (response) {
                stats.value = {
                    totalVariants: response.totalVariants || 0,
                    inStock: response.inStock || 0,
                    lowStock: response.lowStock || 0,
                    outOfStock: response.outOfStock || 0
                };
            }
        })
        .catch((error) => {
            console.error('Failed to fetch inventory stats:', error);
        })
        .finally(() => {
            isLoading.value = false;
        });
};

onMounted(() => {
    fetchStats();
});

defineExpose({
    fetchStats
});
</script>