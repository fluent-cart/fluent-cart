
<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  couponTable: {
    type: Object,
    default: () => ({})
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>

<template>
  <fct-table
      :data="nextPageCount"
      class="w-full compact-table responsive full-compact"
  >
    <fct-table-column :width="103" :label="translate('ID')">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[70px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column
        v-if="couponTable.isColumnVisible('title')"
        :label="translate('Title')"
        :width="227"
    >
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[180px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column :label="translate('Code')" :width="206">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[130px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column :label="translate('Amount')" :width="124">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[100px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column :label="translate('Usage')" :width="155">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[100px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column
        v-if="couponTable.isColumnVisible('stackable')"
        :label="translate('Stackable')"
        :width="124"
    >
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[80px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column :label="translate('Status')" :width="124">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[90px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column :label="translate('Expiry Date')" :width="124">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[90px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>
    <fct-table-column
        v-if="couponTable.isColumnVisible('actions')"
        :label="translate('Actions')"
        :width="103"
        align="right"
    >
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p" class="w-[70px]" />
        </fct-skeleton>
      </div>
    </fct-table-column>

  </fct-table>
</template>

