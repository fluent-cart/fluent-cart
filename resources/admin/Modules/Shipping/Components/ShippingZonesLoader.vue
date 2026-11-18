<template>
  <div class="shipping-zones-loader">
    <div class="shipping-zones-table">
      <fct-table
          :data="nextPageCount"
          class="w-full compact-table responsive"
      >
        <fct-table-column prop="name" :label="translate('Zone Name')">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[120px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>

        <fct-table-column :label="translate('Regions')">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[150px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>

        <fct-table-column :label="translate('Shipping Methods')" width="180">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[200px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>
        <fct-table-column :label="translate('Actions')" width="200" align="right">
          <div class="table-cell">
            <fct-skeleton animated>
              <fct-skeleton variant="p" class="w-[120px]" />
            </fct-skeleton>
          </div>
        </fct-table-column>
      </fct-table>
    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  shippingZoneTable: {
    type: Object,
    required: true
  },
  nextPageCount: {
    type: Number,
    default: 0
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);
</script>
