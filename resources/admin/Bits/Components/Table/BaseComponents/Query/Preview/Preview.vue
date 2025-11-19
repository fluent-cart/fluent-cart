<script setup>
import {VueDd} from "vue-dd";
import Condition from "@/Bits/Components/Table/BaseComponents/Query/Preview/Condition.vue";
import Operator from "@/Bits/Components/Table/BaseComponents/Query/Preview/Operator.vue";
import Relation from "@/Bits/Components/Table/BaseComponents/Query/Preview/Relation.vue";

const props = defineProps({
  query: {
    type: Object,
    required: true
  },
  columns: {
    type: Object,
    required: true
  },
  isFirst: {
    type: Boolean,
    default: false,
  },
  queryState: {
    type: Object,
    required: true
  },
})

const model = props.query;
</script>

<template>


  <template v-if="Array.isArray(query.conditions) && query.conditions.length > 0">
    <div class="flex preview-box gap-1">
      <template v-for="(condition , index) of query.conditions">
        <span v-if="index>0 && query.conditions.length > 1" class="text-orange-500 uppercase">
          {{ query.type }}
        </span>

        <Condition v-if="condition.isCondition === true" :condition="condition" :columns="columns"
                   :queryState="queryState"/>

        <Relation v-if="condition.isRelation === true" :relation="condition" :columns="columns"
                  :queryState="queryState"/>
        
        <Operator v-if="condition.isOperator === true" :operator="condition" :columns="columns"
                  :queryState="queryState"/>

      </template>
    </div>
  </template>
</template>

<style scoped>

</style>