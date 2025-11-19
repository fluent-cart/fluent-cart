<script setup>

const props = defineProps({
  operator: {
    type: Object,
    required: true
  },
  columns: {
    type: Object,
    required: true
  },
  queryState: {
    type: Object,
    required: true
  },
});

const getOperatorScheme = () => {
  if (!props.operator.operator in props.queryState.operatorList) {
    return null;
  }

  return props.queryState.operatorList[props.operator.operator];
}

const getColumnTitle = () => {
  if (!props.operator.column) {
    return null;
  }
  const foundItem = props.columns.find(item => item.name === props.operator.column);
  return foundItem.title;
}

const isOperatorNull = () => {
  return getOperatorScheme().isNull === true;
}

const getOperatorTitle = () => {
  const scheme = getOperatorScheme();
  return scheme === null ? null : scheme.title;
}
</script>

<template>
  <div class="flex gap-1">
    <span class="fct-query-operator" :class="{
      'is-empty': getOperatorTitle() === null,
      'order-3' : isOperatorNull()
    }">


      <span v-if="getOperatorTitle()">{{ getOperatorTitle() }}</span>
      <span v-else>{{ $t('Select') }}</span>

      <el-select popper-class="operator-popover" v-model="operator.operator" filterable>
        <el-option
            v-for="(operator, index) in Object.values(queryState.operatorList)"
            :key="operator.operator"
            :label="operator.title"
            :value="operator.operator"
        />
      </el-select>
    </span>

    <span v-if="isOperatorNull()">
        {{ $t('Where') }}
      </span>

    <div class="fct-query-column" :class="getColumnTitle() === null ? 'is-empty' : ''">


      <span v-if="getColumnTitle()">{{ getColumnTitle() }}</span>
      <span v-else>{{ $t('Select') }}</span>
      <el-select
          popper-class="operator-popover"
          v-model="operator.column"
      >
        <el-option
            v-for="column in columns"
            :key="column.name"
            :label="column.title"
            :value="column.name"
        />
      </el-select>
    </div>

    <span class="text-system-dark dark:text-gray-50" v-if="isOperatorNull()">
      is
    </span>

    <span class="text-system-dark dark:text-gray-50" v-if="!isOperatorNull()">
      ="{{ operator.value }}"
    </span>
  </div>
</template>

<style scoped>

</style>
