<script setup>
import {computed} from "vue";

const props = defineProps({
  product: Object,
  attributeGroups: {
    type: Array,
    default: () => [],
  },
  modelValue: {
    type: [Number, null],
    default: null,
  },
})

const emit = defineEmits(['update:modelValue'])

const groupByGroupId = computed({
  get() {
    return props.modelValue;
  },
  set(val) {
    emit('update:modelValue', val);
  }
})

const configuredGroups = computed(() => {
  const config = props.product?.detail?.other_info?.attribute_config;
  if (!config || !Array.isArray(config)) return [];
  return config.map(c => {
    // String() both sides — a BIGINT group id can serialize as a string in the
    // saved config but as a number in the library list (or vice versa); a
    // strict === miss would empty configuredGroups and hide the selector.
    const group = props.attributeGroups.find(g => String(g.id) === String(c.group_id));
    return group ? { id: group.id, title: group.title } : null;
  }).filter(Boolean);
});

defineExpose({ configuredGroups });
</script>

<template>
  <div 
    v-if="configuredGroups.length >= 2" 
    class="fct-adv-groupby-bar"
>
    <span class="fct-adv-groupby-label">{{ $t('Group by') }}</span>
    <el-select
        v-model="groupByGroupId"
        :placeholder="$t('Select')"
    >
       <el-option
            v-for="g in configuredGroups"
            :key="g.id"
            :label="g.title"
            :value="g.id"
        />
    </el-select>
  </div>
</template>
