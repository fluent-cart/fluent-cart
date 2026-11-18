<template>
  <fct-dialog
    drawer
    v-model="drawerVisible"
    :title="isEdit ? translate('Edit Shipping Class') : translate('Add Shipping Class')"
    append-to-body
    :close-on-click-modal="true"
    class="fct-shipping-class-drawer"
    @closed="onDrawerClosed"
  >
    <el-form :model="form" :rules="rules" ref="formRef" label-position="top" require-asterisk-position="right">
      <el-form-item prop="name">
        <template #label>
          <LabelHint :title="translate('Class Name')" :content="translate('Ship_Give_Shipping')" placement="bottom"/>
        </template>
        <fct-input v-model="form.name" :placeholder="translate('Enter class name')"/>
      </el-form-item>

      <el-form-item prop="description">
        <template #label>
          <LabelHint :title="translate('Description')" :content="translate('Optional description of this shipping class for internal reference.')" placement="bottom"/>
        </template>
        <fct-input v-model="form.description" type="textarea" :rows="2" :placeholder="translate('Enter description (optional)')"/>
      </el-form-item>

      <el-form-item prop="cost">
        <template #label>
          <LabelHint :title="translate('Cost')" :content="translate('Ship_Enter_Shipping')"/>
        </template>
        <fct-input type="number" v-model="form.cost" :precision="2" :min="0" :step="1"/>
      </el-form-item>

      <el-form-item prop="type">
        <template #label>
          <LabelHint :title="translate('Cost Type')" :content="translate('How this cost should be applied to the shipping rate')"/>
        </template>

        <fct-select
            v-model="form.type"
            style="width: 100%"
            :options="[
              {label: translate('Fixed Amount'), value: 'fixed'},
              {label: translate('Percentage'), value: 'percentage'},
            ]"
        />
      </el-form-item>

      <el-form-item prop="per_item">
        <fct-choice v-model="form.per_item" true-value="1" false-value="0">
          <LabelHint :title="translate('Apply to quantity')" :content="translate('Ship_Check_Box')"/>
        </fct-choice>
      </el-form-item>
    </el-form>

    <template #footer>
      <span class="dialog-footer">
        <fct-button @click="closeDrawer">{{ translate('Cancel') }}</fct-button>
        <fct-button type="primary" @click="saveClass" :loading="saving">
          {{ isEdit ? translate('Update') : translate('Save') }}
        </fct-button>
      </span>
    </template>
  </fct-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import translate from "@/utils/translator/Translator";
import Rest from '@/utils/http/Rest';
import Notify from "@/utils/Notify";
import LabelHint from "@/Bits/Components/LabelHint.vue";

const props = defineProps({
  modelValue: {
    type: Boolean,
    required: true
  },
  classData: {
    type: Object,
    default: null
  },
  initialData: {
    type: Object,
    default: () => ({
      name: '',
      description: '',
      cost: 0,
      type: 'fixed',
      per_item: 0
    })
  }
});

const emit = defineEmits(['update:modelValue', 'saved', 'closed']);

const formRef = ref(null);
const saving = ref(false);

const form = ref({...props.initialData});

const rules = {
  name: [
    {required: true, message: translate('Please enter a class name'), trigger: 'blur'}
  ],
  cost: [
    {required: true, message: translate('Please enter a cost'), trigger: 'blur'}
  ],
  type: [
    {required: true, message: translate('Please select a cost type'), trigger: 'blur'}
  ]
};

const isEdit = computed(() => {
  return !!props.classData;
});

const drawerVisible = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
});


// Initialize form with classData when it changes
watch(() => props.classData, (newVal) => {
  if (newVal) {
    form.value = {
      name: newVal.name,
      description: newVal.description || '',
      cost: newVal.cost,
      type: newVal.type,
      per_item: newVal.per_item || 0
    };
  } else {
    // Reset form when classData is null (for adding new class)
    form.value = {...props.initialData};
  }
}, { immediate: true, deep: true });

const saveClass = () => {
  formRef.value.validate(valid => {
    if (!valid) return;

    saving.value = true;
    const method = isEdit.value ? 'put' : 'post';
    const url = isEdit.value ? `/shipping/classes/${props.classData.id}` : '/shipping/classes';

    Rest[method](url, form.value)
      .then(response => {
        Notify.success(response.message);
        emit('saved', response.shipping_class);
        closeDrawer();
      })
      .catch(error => {
        // Error shown via Notify
        Notify.error(translate('Failed to save shipping class'));
      })
      .finally(() => {
        saving.value = false;
      });
  });
};

const closeDrawer = () => {
  emit('update:modelValue', false);
};

const onDrawerClosed = () => {
  if (!isEdit.value) {
    form.value = {
      name: '',
      description: '',
      cost: 0,
      type: 'fixed',
      per_item: 0
    };
  }
  emit('closed');
};
</script>
