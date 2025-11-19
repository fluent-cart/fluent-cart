<script setup>
import OperatorBuilder from "@/Bits/Components/Table/BaseComponents/Query/OperatorBuilder.vue";
import ConditionBuilder from "@/Bits/Components/Table/BaseComponents/Query/ConditionBuilder.vue";

const props = defineProps({
  relation: {
    type: Object,
    required: true
  },
  queryState: {
    type: Object,
    required: true
  },

})
</script>

<template>
  <div>
    <div class="relation-builder-title-wrap">
      <span>{{ $t('Relation:') }} {{ relation.title }}</span>
    </div>

    <ConditionBuilder
        :item="relation.conditions[0]"
        :columns="relation.columns"
        :queryState="queryState"
        :put="[
            'operators',
            'condition',
            'relations'+relation.name
        ]"
    />
  </div>
</template>

<style scoped>

</style>