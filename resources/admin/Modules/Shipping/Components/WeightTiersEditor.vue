<script setup>
import {ref, watch, onMounted, computed} from 'vue';
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => []
  },
  weightUnit: {
    type: String,
    default: 'kg'
  }
});

const emit = defineEmits(['update:modelValue']);

const tiers = ref(props.modelValue.length > 0 ? [...props.modelValue] : [
  {min: 0, max: 0, cost: 0}
]);

// Block adding tiers after an unlimited (max=0) tier
const hasUnlimitedTier = computed(() => {
  const last = tiers.value[tiers.value.length - 1];
  return last && last.max <= 0;
});

// Check if a tier has max <= min (non-last with max > 0)
const tierMaxError = (index) => {
  const tier = tiers.value[index];
  if (index === tiers.value.length - 1 && tier.max <= 0) {
    return ''; // Last tier with max=0 (unlimited) is valid
  }
  if (tier.max > 0 && tier.max <= tier.min) {
    return translate('Max must be greater than %s', tier.min);
  }
  return '';
};

// Check if a tier overlaps with the previous tier
const tierOverlapError = (index) => {
  if (index === 0) return '';
  const prev = tiers.value[index - 1];
  const curr = tiers.value[index];
  if (prev.max <= 0) return ''; // Previous is unlimited
  if (curr.min < prev.max) {
    return translate('Overlaps with previous tier (max %s)', prev.max);
  }
  return '';
};

// Combined error for a tier
const getTierError = (index) => {
  return tierOverlapError(index) || tierMaxError(index);
};

// Check if any tier has validation error
const hasInvalidTier = computed(() => {
  return tiers.value.some((_, index) => getTierError(index));
});

// Disable Add Tier when unlimited tier exists OR any tier is invalid
const isAddTierDisabled = computed(() => {
  return hasUnlimitedTier.value || hasInvalidTier.value;
});

watch(() => props.modelValue, (newVal) => {
  if (newVal && newVal.length > 0) {
    tiers.value = [...newVal];
  } else {
    tiers.value = [{min: 0, max: 0, cost: 0}];
  }
}, {deep: true});

const addTier = () => {
  if (isAddTierDisabled.value) return;
  const lastTier = tiers.value[tiers.value.length - 1];
  const newMin = lastTier && lastTier.max > 0 ? parseFloat((lastTier.max + 0.01).toFixed(2)) : 0;
  tiers.value.push({min: newMin, max: 0, cost: 0});
  emitChange();
};

const removeTier = (index) => {
  tiers.value.splice(index, 1);
  emitChange();
};

const normalizeTiers = () => {
  // Sort by min weight ascending
  tiers.value.sort((a, b) => (a.min || 0) - (b.min || 0));

  // No forced min=0 — merchants can set any starting weight

  // Fix non-last unlimited (max<=0)
  for (let i = 0; i < tiers.value.length - 1; i++) {
    if (tiers.value[i].max <= 0) {
      tiers.value[i].max = parseFloat((tiers.value[i].min + 1).toFixed(2));
    }
  }
};

const emitChange = () => {
  normalizeTiers();
  emit('update:modelValue', [...tiers.value]);
};

const onTierChange = () => {
  emitChange();
};

onMounted(() => {
  if (!props.modelValue.length) {
    emitChange();
  }
});
</script>

<template>
  <div class="fct-weight-tiers-editor">
    <div class="mb-2 text-sm text-gray-500">
      {{ translate('Define weight ranges and their shipping costs. Set max to 0 for unlimited.') }}
    </div>
    <el-table :data="tiers" size="small" class="w-full">
      <el-table-column :label="translate('Min Weight (%s)', weightUnit)" min-width="120">
        <template #default="scope">
          <el-input-number
              v-model="scope.row.min"
              :min="0"
              :precision="2"
              :step="0.1"
              size="small"
              controls-position="right"
              @change="onTierChange"
              class="w-full"
              :class="{ 'is-error': tierOverlapError(scope.$index) }"
          />
          <p v-if="tierOverlapError(scope.$index)" style="color: var(--el-color-danger); font-size: 12px; line-height: 1.2; margin: 4px 0 0;">
            {{ tierOverlapError(scope.$index) }}
          </p>
        </template>
      </el-table-column>
      <el-table-column :label="translate('Max Weight (%s)', weightUnit)" min-width="120">
        <template #default="scope">
          <el-input-number
              v-model="scope.row.max"
              :min="0"
              :precision="2"
              :step="0.1"
              size="small"
              controls-position="right"
              @change="onTierChange"
              class="w-full"
              :class="{ 'is-error': tierMaxError(scope.$index) }"
          />
          <p v-if="tierMaxError(scope.$index)" style="color: var(--el-color-danger); font-size: 12px; line-height: 1.2; margin: 4px 0 0;">
            {{ tierMaxError(scope.$index) }}
          </p>
        </template>
      </el-table-column>
      <el-table-column :label="translate('Cost')" min-width="120">
        <template #default="scope">
          <el-input-number
              v-model="scope.row.cost"
              :min="0"
              :precision="2"
              :step="0.5"
              size="small"
              controls-position="right"
              @change="onTierChange"
              class="w-full"
          />
        </template>
      </el-table-column>
      <el-table-column :label="translate('Actions')" width="60" align="center">
        <template #default="scope">
          <el-button
              v-if="tiers.length > 1"
              type="danger"
              text
              size="small"
              :aria-label="translate('Remove tier')"
              @click="removeTier(scope.$index)"
          >
            <DynamicIcon name="Delete" class="w-3 h-3"/>
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <div class="mt-2 flex items-center gap-2">
      <el-button size="small" @click="addTier" :disabled="isAddTierDisabled">
        <DynamicIcon name="Plus" class="w-3 h-3 mr-1"/>
        {{ translate('Add Tier') }}
      </el-button>
      <span v-if="hasUnlimitedTier" class="text-xs text-gray-400">
        {{ translate('Last tier has unlimited range (max = 0). Set a max value to add more tiers.') }}
      </span>
    </div>
  </div>
</template>
