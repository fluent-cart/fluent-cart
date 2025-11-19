<script setup>
import translate from "@/utils/translator/Translator";
import OrderBumpTable from "@/utils/table-new/OrderBumpTable";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import OrderBumpsTable from "@/Modules/OrderBump/OrderBumpsTable.vue";
import CreateNewOrderBump from "@/Modules/OrderBump/CreateNewOrderBump.vue";
import OrderBumpsTableLoader from "@/Modules/OrderBump/OrderBumpsTableLoader.vue";

const orderBumpTable = OrderBumpTable();

</script>

<template>
    <div class="fct-order-bumps-page fct-layout-width">
        <PageHeading :title="translate('Order Bumps')">
            <template #action>
                <CreateNewOrderBump/>
            </template>
        </PageHeading>

        <div class="fct-order-bumps-wrap">
            <TableWrapper :table="orderBumpTable" :has-mobile-slot="true">
                <order-bumps-table-loader v-if="orderBumpTable.isLoading()"/>
                <div v-else>
                    <OrderBumpsTable :orderBumpTable="orderBumpTable"/>
                </div>
                <template #mobile>
                    <OrderBumpsTable :orderBumpTable="orderBumpTable"/>
                </template>
            </TableWrapper>
        </div>
    </div>
</template>
