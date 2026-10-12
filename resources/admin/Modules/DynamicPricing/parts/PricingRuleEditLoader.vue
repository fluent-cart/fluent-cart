<script setup>
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";
import Permission from "@/utils/permission/Permission";

const canManage = Permission.has('dynamic_pricing/manage');

// Placeholders for the When to Apply radio list, one per option.
const whenToApplyRows = [1, 2, 3, 4];
</script>

<template>
    <!-- Mirrors EditPricingRule: same cards, order and shapes. -->
    <div class="fct-pricing-rule-loader-wrap">
        <div class="single-page-header fct-page-loader-header">
            <el-skeleton animated>
                <template #template>
                    <el-skeleton-item class="max-w-180"/>
                </template>
            </el-skeleton>
        </div>

        <el-skeleton animated>
            <template #template>
                <div class="fct-pricing-rule-summary">
                    <div class="flex items-center gap-2 flex-1">
                        <el-skeleton-item variant="text" class="w-10"/>
                        <el-skeleton-item variant="button" class="w-[96px] h-6 rounded-sm"/>
                        <el-skeleton-item variant="text" class="w-4"/>
                        <el-skeleton-item variant="button" class="w-[80px] h-6 rounded-sm"/>
                        <el-skeleton-item variant="text" class="w-[160px]"/>
                    </div>

                    <div v-if="canManage" class="fct-pricing-rule-summary-status">
                        <el-skeleton-item variant="button" class="w-10 h-5 rounded-full"/>
                        <el-skeleton-item variant="text" class="w-12"/>
                    </div>
                </div>
            </template>
        </el-skeleton>

        <div class="single-page-body">
            <el-row :gutter="30">
                <el-col :lg="16">
                    <Card.Container class="overflow-hidden">
                        <Card.Header :title="translate('Details')"/>
                        <Card.Body>
                            <el-skeleton animated>
                                <template #template>
                                    <div class="fct-pricing-rule-form">
                                        <div class="fct-form-group">
                                            <el-skeleton-item variant="text" class="w-16 mb-2"/>
                                            <el-skeleton-item variant="button" class="w-full h-8"/>
                                        </div>

                                        <div class="fct-form-group">
                                            <el-skeleton-item variant="text" class="w-[96px] mb-2"/>
                                            <el-skeleton-item variant="button" class="w-full h-8"/>
                                        </div>

                                        <div class="fct-form-group">
                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                <div v-for="column in 3" :key="column">
                                                    <el-skeleton-item variant="text" class="w-[80px] mb-2"/>
                                                    <el-skeleton-item variant="button" class="w-full h-8"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </el-skeleton>
                        </Card.Body>
                    </Card.Container>

                    <Card.Container class="overflow-hidden">
                        <Card.Header :title="translate('Display Conditions')" border_bottom/>
                        <Card.Body>
                            <el-skeleton animated :rows="2"/>
                        </Card.Body>
                    </Card.Container>
                </el-col>

                <el-col :lg="8">
                    <Card.Container>
                        <Card.Header :title="translate('Applies To')" border_bottom/>
                        <Card.Body>
                            <el-skeleton animated>
                                <template #template>
                                    <div class="fct-pricing-rule-applies-to-options">
                                        <el-skeleton-item
                                            v-for="tile in 3"
                                            :key="tile"
                                            variant="button"
                                            class="w-full h-[66px] rounded"
                                        />
                                    </div>
                                    <el-skeleton-item variant="text" class="w-4/5 mt-3"/>
                                </template>
                            </el-skeleton>
                        </Card.Body>
                    </Card.Container>

                    <Card.Container>
                        <Card.Header :title="translate('When to Apply')" border_bottom/>
                        <Card.Body>
                            <el-skeleton animated>
                                <template #template>
                                    <div class="flex flex-col gap-3">
                                        <div
                                            v-for="row in whenToApplyRows"
                                            :key="row"
                                            class="flex items-center gap-2"
                                        >
                                            <el-skeleton-item variant="circle" class="w-4 h-4 flex-none"/>
                                            <el-skeleton-item variant="text" class="w-2/5"/>
                                        </div>
                                    </div>
                                </template>
                            </el-skeleton>
                        </Card.Body>
                    </Card.Container>

                    <Card.Container>
                        <Card.Header :title="translate('Schedule')" border_bottom/>
                        <Card.Body>
                            <el-skeleton animated :rows="2"/>
                        </Card.Body>
                    </Card.Container>
                </el-col>
            </el-row>
        </div>
    </div>
</template>
