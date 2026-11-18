<script setup>

import translate from "@/utils/translator/Translator";
import Empty from "@/Bits/Components/Table/Empty.vue";
</script>

<template>
  <fct-table :data="[1,2,3,4,5]" class="w-full compact-table">
    <fct-table-column :width="100" :label="translate('ID')">
      <template #default>
        <div class="table-cell">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="w-4/5"/>
          </fct-skeleton>
        </div>
      </template>
    </fct-table-column>
    <fct-table-column :label="translate('Bump Title')">
      <template #default>
        <div class="table-cell">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="w-4/5"/>
          </fct-skeleton>
        </div>
      </template>
    </fct-table-column>

    <fct-table-column :min-width="320" :label="translate('Offer')">
      <template #default>
        <div class="table-cell">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="w-4/5"/>
          </fct-skeleton>
        </div>
      </template>
    </fct-table-column>

    <fct-table-column :width="200" :label="translate('Discount')">
      <template #default>
        <div class="table-cell">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="w-4/5"/>
          </fct-skeleton>
        </div>
      </template>
    </fct-table-column>

    <fct-table-column :width="110" align="center" :label="translate('Status')">
      <template #default>
        <div class="table-cell">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="w-4/5"/>
          </fct-skeleton>
        </div>
      </template>
    </fct-table-column>

    <template #empty>
      <Empty icon="Empty/ListView" :has-dark="true"
             :text="translate('No order bumps found!')"/>
    </template>
  </fct-table>
</template>
