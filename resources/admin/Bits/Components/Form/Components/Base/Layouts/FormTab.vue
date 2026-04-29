<script setup>
import {nextTick, onMounted, onUnmounted, ref} from 'vue';
import FormRenderer from "@/Bits/Components/Form/Renderer/FormRenderer.vue";
import {useRouter} from "vue-router";

const props = defineProps({
  field: {
    type: Object
  },
  value: {
    type: Object
  },
  statePath: {
    type: String
  },
  form: {
    type: Object,
    required: true
  },
})

const activeName = ref('');
const tabs = ref([]);
const router = useRouter();

const handleTabClick = (tab) => {
  const tabName = tab.props.name;
  setTabHash(tabName);
}
const setTabHash = (tabName) => {
  const newUrl = new URL(window.location.href);
  newUrl.hash = `#/settings/${tabName}`;
  window.history.replaceState(null, '', newUrl.toString());
}

// Function to initialize the selected tab based on the current URL hash
const initializeTabSelection = () => {
  // Get the hash portion of the URL
  const hash = window.location.hash;
  // Extract the last segment of the hash
  const extractedValue = hash.split('/').pop();


  // Storing keys of props.field.schema into tabs
  tabs.value = Object.keys(props.field.schema);

  // Match the extractedValue with tabs elements
  const matchingTab = tabs.value.find(tab => tab === extractedValue);

  // If a matching tab is found, set it as the active tab
  if (matchingTab) {
    activeName.value = matchingTab;
  }
  // Otherwise, set the default tab from props
  else {
    activeName.value = props.field.default_tab;
  }
};

let routeSubscriber;
onMounted(() => {
  routeSubscriber = router.beforeEach((to, from, next) => {
    next();
    setTimeout(() => {
      initializeTabSelection();
    }, 200)
  });
  initializeTabSelection();
})

onUnmounted(() => {
  if (routeSubscriber) {
    routeSubscriber();
  }
})

</script>

<template>
  <el-tabs v-model="activeName" class="fct-tabs-button" :class="{ 'hidden-switch': field.hide_tab_switch }"
           @tab-click="handleTabClick" type="card">
    <FormRenderer :form="form" :schema="field.schema" :value="value" :state-path="statePath"/>
  </el-tabs>
</template>
