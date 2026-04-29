<template>
  <el-drawer
    v-model="drawerVisible"
    :title="isEdit ? translate('Edit Tax Class') : translate('Add Tax Class')"
    width="500px"
    append-to-body
    :close-on-click-modal="true"
    class="fct-tax-class-drawer"
    @closed="onDrawerClosed"
  >
    <el-form :model="form" :rules="rules" ref="formRef" label-position="top" require-asterisk-position="right">
      <el-form-item prop="title">
        <template #label>
          <LabelHint :title="translate('Tax Class Name')" :content="translate('Give the tax class a friendly name to help identify it when setting up tax rates for your products. For instance: Standard Rate, Reduced Rate, Zero Rate, and so on.')" placement="bottom"/>
        </template>
        <el-input v-model="form.title" :placeholder="translate('Enter tax class name')"></el-input>
      </el-form-item>

      <el-form-item prop="description">
        <template #label>
          <LabelHint :title="translate('Description')" :content="translate('Enter a description for this tax class to help you and your team understand when to use it.')"/>
        </template>
        <el-input 
          v-model="form.description" 
          type="textarea" 
          :rows="3"
          :placeholder="translate('Enter tax class description')"
        ></el-input>
      </el-form-item>

      <el-form-item prop="priority">
        <template #label>
          <LabelHint :title="translate('Priority')" :content="translate('Higher numbers have higher priority (0-10). Tax classes with higher priority will be applied first when multiple classes could apply to a product.')"/>
        </template>
        <el-input-number 
          v-model="form.priority" 
          :min="0" 
          :max="10" 
          :step="1"
          controls-position="right"
          class="w-full"
        ></el-input-number>
      </el-form-item>

      <el-form-item prop="categories">
        <template #label>
          <LabelHint :title="translate('Product Categories')" :content="translate('Select the product categories that this tax class will be applied to. Leave empty to apply to all categories.')"/>
        </template>
        <el-select
          v-model="form.categories"
          v-loading="fetchingCategories"
          filterable
          multiple
          :placeholder="translate('Select Product Categories')"
          class="w-full"
        >
          <el-option
            v-for="category in categories"
            :key="category.value"
            :label="category.label"
            :value="category.value"
          />
        </el-select>
      </el-form-item>
    </el-form>

    <template #footer>
      <span class="dialog-footer">
        <el-button @click="closeDrawer">{{ translate('Cancel') }}</el-button>
        <el-button type="primary" @click="saveClass" :loading="saving">
          {{ isEdit ? translate('Update') : translate('Save') }}
        </el-button>
      </span>
    </template>
  </el-drawer>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
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
      title: '',
      description: '',
      priority: 10,
      categories: []
    })
  }
});

const emit = defineEmits(['update:modelValue', 'saved', 'closed']);

const formRef = ref(null);
const saving = ref(false);
const fetchingCategories = ref(false);
const categories = ref([]);

const form = ref({...props.initialData});

const rules = {
  title: [
    {required: true, message: translate('Please enter a tax class name'), trigger: 'blur'}
  ],
  priority: [
    {required: true, message: translate('Please enter a priority'), trigger: 'blur'}
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
      title: newVal.title || '',
      description: newVal.description || '',
      priority: newVal.meta?.priority || 10,
      categories: newVal.meta?.categories || []
    };
  } else {
    // Reset form when classData is null (for adding new class)
    form.value = {...props.initialData};
  }
}, { immediate: true, deep: true });

const fetchCategories = () => {
  if (categories.value.length > 0) {
    return;
  }
  
  fetchingCategories.value = true;
  Rest.get('products/fetch-term')
    .then(response => {
      categories.value = response.taxonomies["product-categories"].terms;
    })
    .catch((error) => {
      console.error('Error fetching product categories:', error);
      Notify.error(translate('Failed to fetch product categories'));
    })
    .finally(() => {
      fetchingCategories.value = false;
    });
};

const saveClass = () => {
  formRef.value.validate(valid => {
    if (!valid) return;

    saving.value = true;
    const method = isEdit.value ? 'put' : 'post';
    const url = isEdit.value ? `/tax/classes/${props.classData.id}` : '/tax/classes';

    const payload = {
      title: form.value.title,
      description: form.value.description,
      priority: form.value.priority,
      categories: form.value.categories
    };

    Rest[method](url, payload)
      .then(response => {
        Notify.success(response.message);
        emit('saved', response.tax_class || response);
        closeDrawer();
      })
      .catch(error => {
        console.error('Error saving tax class:', error);
        Notify.error(translate('Failed to save tax class'));
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
      title: '',
      description: '',
      priority: 10,
      categories: []
    };
  }
  emit('closed');
};

onMounted(() => {
  fetchCategories();
});
</script>
