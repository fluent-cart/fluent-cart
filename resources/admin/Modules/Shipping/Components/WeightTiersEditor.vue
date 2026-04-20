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

watch(() => props.modelValue, (newVal) => {
  if (newVal && newVal.length > 0) {
    tiers.value = [...newVal];
  } else {
    tiers.value = [{min: 0, max: 0, cost: 0}];
  }
}, {deep: true});

const addTier = () => {
  if (hasUnlimitedTier.value) return;
  const lastTier = tiers.value[tiers.value.length - 1];
  const newMin = lastTier ? lastTier.max : 0;
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

  // First tier must start at 0
  if (tiers.value.length > 0) {
    tiers.value[0].min = 0;
  }

  // Fix non-last unlimited (max<=0) BEFORE chaining mins
  for (let i = 0; i < tiers.value.length - 1; i++) {
    if (tiers.value[i].max <= 0) {
      tiers.value[i].max = tiers.value[i].min + 1;
    }
  }

  // Chain: each tier's min = previous tier's max (contiguous, no gaps)
  for (let i = 1; i < tiers.value.length; i++) {
    tiers.value[i].min = tiers.value[i - 1].max;
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
          />
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
          />
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
      <el-button size="small" @click="addTier" :disabled="hasUnlimitedTier">
        <DynamicIcon name="Plus" class="w-3 h-3 mr-1"/>
        {{ translate('Add Tier') }}
      </el-button>
      <span v-if="hasUnlimitedTier" class="text-xs text-gray-400">
        {{ translate('Last tier has unlimited range (max = 0). Set a max value to add more tiers.') }}
      </span>
    </div>
  </div>
</template>
