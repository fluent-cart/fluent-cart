<script setup>
import translate from "@/utils/translator/Translator";
</script>

<template>
  <fct-skeleton animated>
      <fct-table :data="[1,2,3,4,5]" class="w-full compact-table">

        <fct-table-column :width="40"/>

        <fct-table-column :min-width="150" :label="translate('Title')">
          <div class="table-cell pl-5">
            <fct-skeleton variant="p" class="w-4/5"/>
          </div>
        </fct-table-column>

        <fct-table-column :min-width="100" :label="translate('Type')">
          <div class="table-cell">
            <fct-skeleton variant="p" class="w-3/5"/>
          </div>
        </fct-table-column>

        <fct-table-column :min-width="100" :label="translate('Styling')">
          <div class="table-cell">
            <fct-skeleton variant="p" class="w-3/5"/>
          </div>
        </fct-table-column>

        <fct-table-column :min-width="80" :label="translate('Terms')">
          <div class="table-cell">
            <fct-skeleton variant="p" class="w-1/2"/>
          </div>
        </fct-table-column>

        <fct-table-column :min-width="120" :label="translate('Date')">
          <div class="table-cell">
            <fct-skeleton variant="p" class="w-4/5"/>
          </div>
        </fct-table-column>

        <fct-table-column :width="80" align="right" :label="translate('Actions')">
          <div class="table-cell justify-end">
            <fct-skeleton variant="p" class="w-6"/>
          </div>
        </fct-table-column>

        <template #empty/>
      </fct-table>
  </fct-skeleton>
</template>
