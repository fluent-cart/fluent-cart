<script setup>

import translate from "@/utils/translator/Translator";
</script>

<template>
  <fct-table :data="[1,2,3,4,5]">
    <fct-table-column :label="translate('From Plan')" prop="object_id" :width="300">
      <fct-skeleton animated class="flex items-center min-h-[28px]">
        <fct-skeleton variant="p" class="w-4/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column :label="translate('To Plan')" :width="200">
      <fct-skeleton animated class="flex items-center min-h-[28px]">
        <fct-skeleton variant="p" class="w-4/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column :label="translate('Discount Amount')" :width="110">
      <fct-skeleton animated class="flex items-center min-h-[28px]">
        <fct-skeleton variant="p" class="w-4/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column :label="translate('Is Prorate')" :width="100">
      <fct-skeleton animated class="flex items-center min-h-[28px]">
        <fct-skeleton variant="p" class="w-4/5" />
      </fct-skeleton>
    </fct-table-column>
    <fct-table-column :width="100">
      <fct-skeleton animated class="flex items-center min-h-[28px]">
        <fct-skeleton variant="p" class="w-3/5" />
      </fct-skeleton>
    </fct-table-column>
  </fct-table>
</template>

<style scoped>

</style>
