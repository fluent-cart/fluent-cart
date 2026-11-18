<script setup>

const props = defineProps({
  condition: Object,
  columns: {
    type: Object,
    required: true
  },
  queryState: {
    type: Object,
    required: true
  },

});

const isDate = () => {
  const selectedColumn = props.condition.column;
  if (selectedColumn.length === 0) {
    return false;
  }

  const column = props.columns.find(item => item.name === selectedColumn);
  return column.isDate === true;
}
const getOperatorScheme = (operator) => {
  return props.queryState.getOperatorScheme(operator);
};
</script>

<template>
  <div class="grid grid-cols-3 w-full">
    <div>
      <fct-select
          popper-class="operator-popover"

          v-model="condition.column"
          :options="columns.map(column => ({label: column.title, value: column.name}))"
      />
    </div>
    <div>

      <fct-select popper-class="operator-popover" v-model="condition.operator" filterable
          :options="Object.values(queryState.operatorList).map(operator => ({label: operator.title, value: operator.operator}))"
      />
    </div>
    <div>


      <template v-if="getOperatorScheme(condition.operator).isNull !== true">

        <template v-if="!isDate()">
          <fct-input v-model="condition.value"/>
        </template>
        <template v-else>
          <fct-date-picker popper-class="operator-popover" v-model="condition.value"
                          :type="getOperatorScheme(condition.operator).multiple === true? 'daterange':'date'"/>
        </template>


      </template>

    </div>
  </div>
</template>

<style scoped>

</style>