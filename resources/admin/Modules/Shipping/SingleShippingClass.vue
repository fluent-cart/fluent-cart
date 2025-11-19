<template>
  <div class="fct-single-shipping-class-page">
    <div v-if="loading">
      <SingleShippingClassLoader/>
    </div>
    <NotFound v-else-if="notFound.show"
              :message="notFound.message"
              :button-text="notFound.buttonText"
              :route="notFound.route"
    />
    <div v-else class="fct-single-shipping-class">
      <div class="single-page-header flex items-center justify-between">
        <el-breadcrumb :separator-icon="ArrowRight">
          <el-breadcrumb-item :to="{ name: 'shipping_classes' }">
            {{ translate("Shipping Classes") }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            {{ isEdit ? translate('Edit Shipping Class') : translate('Add Shipping Class') }}
          </el-breadcrumb-item>
          <el-breadcrumb-item v-if="isEdit">
            {{ classForm.name }}
          </el-breadcrumb-item>
        </el-breadcrumb>

        <div class="fct-page-header__actions">
          <el-button type="primary" @click="saveClass" :loading="saving">
            {{ translate('Save Shipping Class') }}
          </el-button>
        </div>
      </div>

      <CardContainer class="mb-4">
        <CardHeader :title="translate('Class Details')" border_bottom title_size="small"/>
        <CardBody>
          <el-form :model="classForm" :rules="rules" ref="classFormRef" label-position="top">
            <el-form-item :label="translate('Class Name')" prop="name">
              <el-input v-model="classForm.name" :placeholder="translate('Enter class name')"></el-input>
            </el-form-item>

            <el-form-item :label="translate('Cost')" prop="cost">
              <el-input-number v-model="classForm.cost" :precision="2" :min="0" :step="1" class="w-full max-w-[200px]"></el-input-number>
              <div class="form-help-text w-full">
                {{ translate('Additional cost for products in this shipping class') }}
              </div>
            </el-form-item>

            <el-form-item :label="translate('Cost Type')" prop="type">
              <el-select v-model="classForm.type" style="width: 100%">
                <el-option :label="translate('Fixed Amount')" value="fixed"></el-option>
                <el-option :label="translate('Percentage')" value="percentage"></el-option>
              </el-select>
              <div class="form-help-text">
                {{ translate('How this cost should be applied to the shipping rate') }}
              </div>
            </el-form-item>
          </el-form>
        </CardBody>
      </CardContainer>
    </div>
  </div>
</template>

<script setup>
import {ref, computed, onMounted} from 'vue';
import {useRouter, useRoute} from 'vue-router';
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import SingleShippingClassLoader from '@/Modules/Shipping/Components/SingleShippingClassLoader.vue';
import NotFound from '@/Pages/NotFound.vue';
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import Notify from "@/utils/Notify";
import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";

const saveShortcut = useSaveShortcut();

// Props
const props = defineProps({
  class_id: {
    type: [String, Number],
    required: false
  }
});

// Router
const router = useRouter();

saveShortcut.onSave(() => {
  saveClass();
});

// Refs and State
const classFormRef = ref(null);
const loading = ref(false);
const saving = ref(false);

const classForm = ref({
  name: '',
  cost: 0,
  type: 'fixed'
});

const rules = ref({
  name: [
    {required: true, message: translate('Please enter a class name'), trigger: 'blur'}
  ],
  cost: [
    {required: true, message: translate('Please enter a cost'), trigger: 'blur'}
  ],
  type: [
    {required: true, message: translate('Please select a cost type'), trigger: 'blur'}
  ]
});

const notFound = ref({
  show: false,
  message: '',
  buttonText: '',
  route: ''
});

// Computed
const isEdit = computed(() => {
  return !!props.class_id;
});

// Methods
const fetchClassData = () => {
  if (!isEdit.value) {
    loading.value = false;
    return;
  }

  loading.value = true;
  Rest.get(`/shipping/classes/${props.class_id}`)
    .then(response => {
      const shippingClass = response.shipping_class;
      classForm.value = {
        name: shippingClass.name,
        cost: shippingClass.cost,
        type: shippingClass.type
      };
    })
    .catch(error => {
      console.error('Error fetching shipping class:', error);
      notFound.value = {
        show: true,
        message: translate('The shipping class you are looking for does not exist or has been deleted.'),
        buttonText: translate('Go to Shipping Classes'),
        route: { name: 'shipping_classes' }
      };
    })
    .finally(() => {
      loading.value = false;
    });
};

const saveClass = () => {
  classFormRef.value.validate(valid => {
    if (!valid) return;

    saving.value = true;
    const method = isEdit.value ? 'put' : 'post';
    const url = isEdit.value ? `/shipping/classes/${props.class_id}` : '/shipping/classes';

    Rest[method](url, classForm.value)
      .then(response => {
        Notify.success(response.message);
        if (!isEdit.value) {
          router.push({
            name: 'view_shipping_class',
            params: {class_id: response.shipping_class.id}
          });
        }
      })
      .catch(error => {
        console.error('Error saving shipping class:', error);
        Notify.error(translate('Failed to save shipping class'));
      })
      .finally(() => {
        saving.value = false;
      });
  });
};

// Lifecycle
onMounted(() => {
  fetchClassData();
});
</script>
