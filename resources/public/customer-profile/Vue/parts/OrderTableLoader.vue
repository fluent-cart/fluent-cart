<template>
    <div class="fct-customer-dashboard-table">
        <fct-table :data="rowsRange" :show-header="false">
            <fct-table-column label="#" :width="150">
                <fct-skeleton animated class="py-3">
                      <fct-skeleton variant="p" class="w-3/5"/>
                </fct-skeleton>
            </fct-table-column>
            <fct-table-column :width="300" :label="$t('Items')">
                <fct-skeleton animated>
                      <fct-skeleton variant="p" class="w-3/5"/>
                </fct-skeleton>
            </fct-table-column>
            <fct-table-column :label="$t('Status')" width="120">
                <fct-skeleton animated>
                      <fct-skeleton variant="p" class="w-3/5"/>
                </fct-skeleton>
            </fct-table-column>
            <fct-table-column :label="$t('Amount')" width="90">
                <fct-skeleton animated>
                      <fct-skeleton variant="p" class="w-3/5"/>
                </fct-skeleton>
            </fct-table-column>
        </fct-table>
    </div>
</template>
<script type="text/babel">
export default {
    name: 'OrderTableLoader',
    props: {
        rowsRange: {
            type: Array,
            default: [1, 2]
        }
    }
}
</script>
