<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  logTable: {
    type: Object,
    default: () => ({})
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>

<template>
  <fct-table
      :data="nextPageCount"
      class="w-full compact-table full-compact"
  >

    <fct-table-column :width="143" :label="translate('ID')" >
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column v-if="logTable.isColumnVisible('date')" :label="translate('Date')" :width="172">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column v-if="logTable.isColumnVisible('title')" :label="translate('Title')" :width="287">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column v-if="logTable.isColumnVisible('content')" :label="translate('Content')" :width="287">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column :label="translate('Status')" :width="143">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column :label="translate('Module')" :width="143">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

    <fct-table-column v-if="logTable.isColumnVisible('actions')" :label="translate('Actions')" :width="114">
      <div class="table-cell">
        <fct-skeleton animated>
          <fct-skeleton variant="p"/>
        </fct-skeleton>
      </div>
    </fct-table-column>

  </fct-table>
</template>

