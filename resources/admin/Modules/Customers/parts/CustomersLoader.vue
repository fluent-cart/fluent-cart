<template>
    <fct-skeleton animated>
        <fct-table :data="nextPageCount" class="w-full compact-table responsive">

          <fct-table-column :label="$t('Customer')" width="340">
            <div class="table-cell">
              <div class="fct-product-info-card w-full">
                <div class="fct-product-info-media">
                  <fct-skeleton variant="image" class="w-10 h-10.5 rounded-full flex-none" />
                </div>
                <div class="fct-product-info-details grow-0 shrink-0 basis-[calc(100%-52px)]" style="overflow: hidden;">
                  <div class="fct-product-info-name flex flex-col items-start gap-3">
                    <fct-skeleton variant="p" class="max-w-[190px]" />
                    <fct-skeleton variant="p" class="max-w-[100px]" />
                  </div>
                </div>
              </div>
            </div>
          </fct-table-column>

          <fct-table-column :label="$t('Address')" width="340">
            <div class="table-cell">
              <fct-skeleton variant="p" class="w-4/5" />
            </div>
          </fct-table-column>

          <fct-table-column :label="$t('Purchases')" width="102">
            <div class="table-cell">
              <fct-skeleton variant="p" class="w-3/5" />
            </div>
          </fct-table-column>
          
          <fct-table-column v-if="customerTable.isColumnVisible('ltv')" :label="$t('LTV')" width="113">
            <div class="table-cell">
              <fct-skeleton variant="p" class="w-3/5" />
            </div>
          </fct-table-column>

          <fct-table-column
              v-if="customerTable.isColumnVisible('last_purchase_date')"
              :label="$t('Last Purchase Date')"
              width="226"
          >
            <div class="table-cell">
              <fct-skeleton variant="p" class="w-4/5" />
            </div>
          </fct-table-column>

          <fct-table-column
              v-if="customerTable.isColumnVisible('customer_since')"
              :label="$t('Customer Since')"
              width="170"
          >
            <div class="table-cell">
              <fct-skeleton variant="p" class="w-4/5" />
            </div>
          </fct-table-column>
        </fct-table>
    </fct-skeleton>
</template>

<script setup>
const props = defineProps({
    nextPageCount: {
        type: Number,
        default: 0
    },
    customerTable: {
        type: Object,
        default: () => ({})
    }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>
