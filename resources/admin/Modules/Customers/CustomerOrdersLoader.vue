<script setup>

import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";

const props = defineProps({
  columns: {
    type: Array,
    default: () => []
  }
});

const isColumnVisible = (column) => {
  return props.columns.includes(column)
}
</script>

<template>
  <fct-table class="w-full compact-table responsive" :data="[1,2,3,4,5,6,7,8,9,10]">
    <fct-table-column v-if="isColumnVisible('selection')" type="selection" width="45"/>
    <fct-table-column :width="140" :label="translate('# Date')">
      <div class="min-h-[72px] px-2 ps-6 flex flex-col justify-center">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-3/5" />
        </fct-skeleton>
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-4/5" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column v-if="isColumnVisible('order_items')" :min-width="200" :label="translate('Items')">
      <fct-skeleton animated>
        <fct-skeleton variant="p" class="w-4/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column :width="150" :label="translate('Total')">
      <fct-skeleton animated>
        <fct-skeleton variant="p" class="w-3/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column :width="150" :label="translate('Payment Status')">
      <fct-skeleton animated>
        <fct-skeleton variant="p" class="w-4/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column v-if="isColumnVisible('status')" :width="120" :label="translate('Status')">
      <fct-skeleton animated>
        <fct-skeleton variant="p" class="w-3/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column v-if="isColumnVisible('type')" :width="140" :label="translate('Order Type')">
      <fct-skeleton animated>
        <fct-skeleton variant="p" class="w-3/5" />
      </fct-skeleton>
    </fct-table-column>

  </fct-table>
</template>

<style scoped>

</style>
