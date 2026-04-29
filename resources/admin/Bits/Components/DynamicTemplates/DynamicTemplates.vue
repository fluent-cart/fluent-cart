<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import {defineComponent, getCurrentInstance, onBeforeMount, onMounted, ref} from "vue";
import * as VueInstance from 'vue';

import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import DynamicFormLoader from "@/Bits/Components/DynamicTemplates/DynamicFormLoader.vue";
import EChart from "@/Bits/Components/DynamicTemplates/EChart.vue";
import VueTemplateLoaderLoader from '@/Bits/Components/DynamicTemplates/VueTemplateLoader.vue';

// Registering all components you may need globally

const selfRef = getCurrentInstance().ctx;
const emit = defineEmits(['change']);
const props = defineProps({
  widgetsQuery: {
    type: Object,
    default: {},
    required: false,
  },

  filter: {
    type: String,
    default: '',
    required: false,
  },
  data: {
    required: false
  },
});
const widgets = ref([]);
const readonlyVueInstance = VueInstance.readonly(VueInstance);

const componentsMap = {};
const resolveComponents = (appComponents) => {
  window.fluent_cart_admin.app = {
    vue: readonlyVueInstance,
    components: appComponents,
    xhr: Rest,
    notify: Notify,
  };
  let components = window.fluent_cart_admin.hooks.applyFilters(props.filter + '_components', [], readonlyVueInstance, appComponents);
  components.forEach(({name, component}) => {
    componentsMap[name] = defineComponent(component);
  });
}

onBeforeMount(() => {
  const instance = getCurrentInstance();
  let appComponents = instance.appContext.components;

  resolveComponents(appComponents);

  let dynamicTemplates = window.fluent_cart_admin.hooks.applyFilters(props.filter, [], readonlyVueInstance, appComponents);

  if (Array.isArray(dynamicTemplates)) {
    widgets.value = widgets.value.concat(dynamicTemplates);
  }

  const query = {
    filter: props.filter,
    data: props.widgetsQuery
  }
  Rest.get('widgets', query).then((data) => {
    if (Array.isArray(data.widgets)) {
      widgets.value = widgets.value.concat(data.widgets);
    }
  });

  if (typeof props.widgetsQuery === 'object') {

  }
});

const getDynamicComponent = (component) => {
  return defineComponent(component);
};

const getDynamicComponentFromMap = (component) => {
  return componentsMap[component] || null;
};

const componentRefs = ref([]);

const callComponentCallbacks = async (callbackName, data) => {
  const promises = componentRefs.value.map((component) => {
    if (typeof component[callbackName] === 'function') {
      // Call the function and ensure it returns a Promise
      return component[callbackName](data);
    }
    return Promise.resolve(); // For components that do not have onUpdatingData
  });
  await Promise.all(promises);
  return true;
}

const beforeUpdatingData = async (data) => {
  return await callComponentCallbacks('beforeUpdatingData', data)
};
const afterUpdatingData = async (data) => {
  return await callComponentCallbacks('afterUpdatingData', data)
};

const getFormStates = () => {
  let data = [];

  if (Array.isArray(formRefs.value)) {
    for (let form of formRefs.value) {
      data = {
        ...data,
        ...form.getState()
      };
    }
  }

  return data;
}
const resetForms = () => {
  if (Array.isArray(formRefs.value)) {
    for (let form of formRefs.value) {
      if (form.resetOnSave()) {
        form.resetForm();
      }

    }
  }
}


defineExpose({beforeUpdatingData, afterUpdatingData, getFormStates, resetForms})

const formRefs = ref();


</script>

<template>
  <template v-for="widget in widgets">
    <template v-if="(widget.type !== 'vue-component' && widget.type !== 'vue-template') || widget.use_card == true">
      <Card.Container>
        <Card.Header :title="widget.title" :subtitle="widget.subtitle" border_bottom/>
        <Card.Body>
          <template v-if="widget.type === 'html'">
            <div v-html="widget.content"></div>
          </template>
          <template v-else-if="widget.type === 'chart'">
            <EChart :option="widget.data" :height="`${widget.height||400}`" :width="`${widget.width||'100%'}`"/>
          </template>

          <template v-else-if="widget.type === 'form'">

            <DynamicFormLoader :schema="widget.schema" :values="widget.values"
                               :submit_button_text="widget.submit_button_text" :form_name="widget.form_name"
                               ref="formRefs"
                               :widget="widget"
                               @change="() => emit('change')"
            />
          </template>

          <template v-else-if="typeof widget.component === 'object'">
            <component ref="componentRefs" :is="getDynamicComponent(widget.component)" v-bind="{
            ...widget,
            data:data
          }"/>
          </template>
          <template v-else-if="getDynamicComponentFromMap(widget.component)">
            <component ref="componentRefs" :is="getDynamicComponentFromMap(widget.component)" v-bind="{
            ...widget,
            data:data
          }"/>
          </template>

          <template v-else>
            {{ widget.content }}
          </template>

        </Card.Body>
      </Card.Container>
    </template>

    <template v-else-if="widget.type === 'vue-component'">
      <template v-if="typeof widget.component === 'object'">
        <component ref="componentRefs" :is="getDynamicComponent(widget.component)" v-bind="{
            ...widget,
            data:data
          }"/>
      </template>
      <template v-else-if="getDynamicComponentFromMap(widget.component)">
        <component ref="componentRefs" :is="getDynamicComponentFromMap(widget.component)" v-bind="{
            ...widget,
            data:data
          }"/>
      </template>

    </template>
    <template v-else-if="widget.type === 'vue-template'">
      <VueTemplateLoaderLoader :widget="widget" :data="data"/>
    </template>

  </template>

</template>
