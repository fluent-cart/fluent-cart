<template>
  <div class="shipping-classes-loader">

    <div class="shipping-classes-table">
      <fct-table
          :data="[]"
          class="w-full compact-table responsive"
      >
        <fct-table-column prop="name" :label="translate('Class Name')">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[120px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>

        <fct-table-column :label="translate('Cost')">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[80px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>

        <fct-table-column :label="translate('Type')">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[100px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>

        <fct-table-column :label="translate('Actions')" width="200" align="right">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[80px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>
      </fct-table>
    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";

defineProps({
  shippingClassTable: {
    type: Object,
    required: true
  },
  nextPageCount: {
    type: Number,
    default: 0
  }
});
</script>