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
            <fct-skeleton animated>
                    <fct-skeleton variant="text" class="max-w-180"/>
            </fct-skeleton>
        </div>

        <fct-skeleton animated>
                <div class="fct-pricing-rule-summary">
                    <div class="flex items-center gap-2 flex-1">
                        <fct-skeleton variant="text" class="w-10"/>
                        <fct-skeleton variant="button" class="w-[96px] h-6 rounded-sm"/>
                        <fct-skeleton variant="text" class="w-4"/>
                        <fct-skeleton variant="button" class="w-[80px] h-6 rounded-sm"/>
                        <fct-skeleton variant="text" class="w-[160px]"/>
                    </div>

                    <div v-if="canManage" class="fct-pricing-rule-summary-status">
                        <fct-skeleton variant="button" class="w-10 h-5 rounded-full"/>
                        <fct-skeleton variant="text" class="w-12"/>
                    </div>
                </div>
        </fct-skeleton>

        <div class="single-page-body">
            <!-- Was el-row :gutter="30" with el-col :lg spans (Element Plus lg = 1200px). -->
            <div class="flex flex-wrap box-border -mx-[15px]">
                <div class="box-border px-[15px] w-full min-[1200px]:w-2/3">
                    <Card.Container class="overflow-hidden">
                        <Card.Header :title="translate('Details')"/>
                        <Card.Body>
                            <fct-skeleton animated>
                                    <div class="fct-pricing-rule-form">
                                        <div class="fct-form-group">
                                            <fct-skeleton variant="text" class="w-16 mb-2"/>
                                            <fct-skeleton variant="button" class="w-full h-8"/>
                                        </div>

                                        <div class="fct-form-group">
                                            <fct-skeleton variant="text" class="w-[96px] mb-2"/>
                                            <fct-skeleton variant="button" class="w-full h-8"/>
                                        </div>

                                        <div class="fct-form-group">
                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                <div v-for="column in 3" :key="column">
                                                    <fct-skeleton variant="text" class="w-[80px] mb-2"/>
                                                    <fct-skeleton variant="button" class="w-full h-8"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                            </fct-skeleton>
                        </Card.Body>
                    </Card.Container>

                    <Card.Container class="overflow-hidden">
                        <Card.Header :title="translate('Display Conditions')" border_bottom/>
                        <Card.Body>
                            <fct-skeleton animated :rows="2"/>
                        </Card.Body>
                    </Card.Container>
                </div>

                <div class="box-border px-[15px] w-full min-[1200px]:w-1/3">
                    <Card.Container>
                        <Card.Header :title="translate('Applies To')" border_bottom/>
                        <Card.Body>
                            <fct-skeleton animated>
                                    <div class="fct-pricing-rule-applies-to-options">
                                        <fct-skeleton
                                            v-for="tile in 3"
                                            :key="tile"
                                            variant="button"
                                            class="w-full h-[66px] rounded"
                                        />
                                    </div>
                                    <fct-skeleton variant="text" class="w-4/5 mt-3"/>
                            </fct-skeleton>
                        </Card.Body>
                    </Card.Container>

                    <Card.Container>
                        <Card.Header :title="translate('When to Apply')" border_bottom/>
                        <Card.Body>
                            <fct-skeleton animated>
                                    <div class="flex flex-col gap-3">
                                        <div
                                            v-for="row in whenToApplyRows"
                                            :key="row"
                                            class="flex items-center gap-2"
                                        >
                                            <fct-skeleton variant="circle" class="w-4 h-4 flex-none"/>
                                            <fct-skeleton variant="text" class="w-2/5"/>
                                        </div>
                                    </div>
                            </fct-skeleton>
                        </Card.Body>
                    </Card.Container>

                    <Card.Container>
                        <Card.Header :title="translate('Schedule')" border_bottom/>
                        <Card.Body>
                            <fct-skeleton animated :rows="2"/>
                        </Card.Body>
                    </Card.Container>
                </div>
            </div>
        </div>
    </div>
</template>
