<template>
  <div class="fct-query-builder">
    <div class="fct-query-builder-sidebar">
      <h4 class="fct-query-builder-sidebar-heading">{{ $t('Conditions') }}</h4>
      <div>
        <VueDraggableNext
            class="fct-query-builder-draggable-list"
            tag="ul"
            :list="queryState.conditionTypes"
            :sort="false"
            :group="{ name: 'condition', pull: 'clone', put: false }"
            :clone="(item)=>{
              return cloneItem(item,'isCondition')
            }"
            item-key="type"
        >
          <li v-for="(type, index) in queryState.conditionTypes" :key="type.title" class="item">
            {{ type.title }}
          </li>
        </VueDraggableNext>
      </div>

      <h4 class="fct-query-builder-sidebar-heading">{{ $t('Operators') }}</h4>
      <div>
        <VueDraggableNext
            class="fct-query-builder-draggable-list"
            tag="ul"
            :list="Object.values(queryState.operatorList)"
            :sort="false"
            :group="{ name: 'operators', pull: 'clone', put: false }"
            :clone="(item)=>{
              return cloneItem(item,'isOperator')
            }"
            item-key="operator"
        >
          <li v-for="(operator, index) in Object.values(queryState.operatorList)" :key="operator.title" class="item">
            {{ operator.title }}
          </li>
        </VueDraggableNext>
      </div>


      <h4 class="fct-query-builder-sidebar-heading">{{ $t('Relations') }}</h4>
      <RelationList :clone-item="cloneItem" :list="queryState.relations" parent="relations"/>
    </div>

    <div class="fct-query-builder-main">
      <!-- This section accepts conditionType -->
      <div>
        <ConditionBuilder
            :is-first="true"
            hide-title
            class="min-h-full"
            :item="data.query[0]"
            :columns="queryState.data.columns"
            :queryState="queryState"
        />
      </div>

      <div class="fct-query-preview-title">
        <span>{{ $t('Preview') }}</span>
      </div>

      <Preview :query="data.query[0]" :columns="queryState.data.columns" :queryState="queryState" is-first/>

      <div class="fct-apply-filter-footer" v-if="typeof onApply === 'function'">
        <el-button type="primary" @click="evt => {

            onApply();
          }">
          {{ $t('Apply') }}
        </el-button>
      </div>
    </div>
  </div>

</template>

<script setup>
import {VueDraggableNext} from 'vue-draggable-next';
import ConditionBuilder from "@/Bits/Components/Table/BaseComponents/Query/ConditionBuilder.vue";

import {VueDd} from 'vue-dd';
import RelationList from "@/Bits/Components/Table/BaseComponents/Query/RelationList.vue";
import Preview from "@/Bits/Components/Table/BaseComponents/Query/Preview/Preview.vue";

const props = defineProps({
  table: {
    type: Object,
    required: true
  },
  onApply: {
    type: Function
  }
});


// Initialize query state using external setup
const queryState = props.table.dynamicQuery;
const data = queryState.data;


// Clone function for drag-and-drop items
const cloneItem = (item, identifier) => {
  return JSON.parse(
      JSON.stringify({
        ...item,
        ...(identifier === 'isCondition' && {conditions: item.conditions ? [...item.conditions] : []}),
        [identifier]: true
      })
  );
};

</script>


