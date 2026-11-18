<script setup>
import translate from "@/utils/translator/Translator";
import OrderBumpTable from "@/utils/table-new/OrderBumpTable";
import Page from "@/Bits/Components/Layout/Page.vue";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import OrderBumpsTable from "@/Modules/OrderBump/OrderBumpsTable.vue";
import CreateNewOrderBump from "@/Modules/OrderBump/CreateNewOrderBump.vue";
import OrderBumpsTableLoader from "@/Modules/OrderBump/OrderBumpsTableLoader.vue";

const orderBumpTable = OrderBumpTable();

</script>

<template>
    <Page class="fct-order-bumps-page fct-layout-width" :title="translate('Order Bumps')">
        <template #actions>
            <fct-button :to="{ name: 'order_bump_design' }">
                {{ translate('Design') }}
            </fct-button>
            <CreateNewOrderBump/>
        </template>

        <div class="fct-order-bumps-wrap">
            <TableWrapper :table="orderBumpTable" :has-mobile-slot="true">
                <OrderBumpsTableLoader v-if="orderBumpTable.isLoading()"/>
                <div v-else>
                    <OrderBumpsTable :orderBumpTable="orderBumpTable"/>
                </div>
                <template #mobile>
                    <OrderBumpsTable :orderBumpTable="orderBumpTable"/>
                </template>
            </TableWrapper>
        </div>
    </Page>
</template>
