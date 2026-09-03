<script setup>
import {computed} from "vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";

const props = defineProps({
  selectedCount: {
    type: Number,
    default: 0,
  },
  bulkAction: {
    type: String,
    default: '',
  },
  bulkValue: {
    type: [String, Number],
    default: '',
  },
  saving: {
    type: Boolean,
    default: false,
  }
})

const emit = defineEmits(['update:bulkAction', 'update:bulkValue', 'apply', 'clear'])

const action = computed({
  get() {
    return props.bulkAction;
  },
  set(val) {
    emit('update:bulkAction', val);
  }
})

const value = computed({
  get() {
    return props.bulkValue;
  },
  set(val) {
    emit('update:bulkValue', val);
  }
})

const needsBulkValue = computed(() => {
  return props.bulkAction === 'set_price';
})
</script>

<template>
  <div v-if="selectedCount > 0" class="fct-adv-bulk-bar">
    <span class="fct-adv-bulk-count">
        {{
          /* translators: %1$s: number of selected variants */
          $t('%1$s selected', selectedCount)
        }}
    </span>

    <button
        type="button"
        class="fct-adv-bulk-clear"
        @click="$emit('clear')"
    >
      {{ $t('Clear') }}
    </button>

    <el-select
        v-model="action"
        :placeholder="$t('Bulk Actions')"
        size="small"
    >
      <el-option :label="$t('Set Price')" value="set_price"/>
      <el-option :label="$t('Set Active')" value="set_status_active"/>
      <el-option :label="$t('Set Inactive')" value="set_status_inactive"/>
    </el-select>

    <!-- Set Price feeds products/variants/bulk-update, which reads CENTS.
         PriceInput shows the merchant dollars and emits cents, so bulkValue
         is cents like every other price on this screen. -->
    <PriceInput
        v-if="needsBulkValue"
        v-model="value"
        :placeholder="$t('Value')"
        size="small"
    />
    <el-button
        type="primary"
        size="small"
        :loading="saving"
        :disabled="!bulkAction || selectedCount === 0"
        @click="$emit('apply')"
    >
      {{ $t('Apply') }}
    </el-button>
  </div>
</template>
