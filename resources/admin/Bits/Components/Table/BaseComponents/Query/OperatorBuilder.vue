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
      <el-select
          popper-class="operator-popover"

          v-model="condition.column"
      >
        <el-option
            v-for="column in columns"
            :key="column.name"
            :label="column.title"
            :value="column.name"
        />
      </el-select>
    </div>
    <div>

      <el-select popper-class="operator-popover" v-model="condition.operator" filterable>
        <el-option
            v-for="(operator, index) in Object.values(queryState.operatorList)"
            :key="operator.operator"
            :label="operator.title"
            :value="operator.operator"
        />
      </el-select>
    </div>
    <div>


      <template v-if="getOperatorScheme(condition.operator).isNull !== true">

        <template v-if="!isDate()">
          <el-input v-model="condition.value"/>
        </template>
        <template v-else>
          <el-date-picker popper-class="operator-popover" v-model="condition.value"
                          :type="getOperatorScheme(condition.operator).multiple === true? 'daterange':'date'"/>
        </template>


      </template>

    </div>
  </div>
</template>

<style scoped>

</style>