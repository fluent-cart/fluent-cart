<script setup>
import {computed, ref, watch, nextTick, inject, onMounted} from "vue";
import {useRoute} from "vue-router";
import Alert from "@/Bits/Components/Alert.vue";
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";

const props = defineProps({
  placement: {
    type: String,
    default: 'top'
  }
})

const appVars = inject('appVars');
const route = useRoute();

const adminNoticeClass = computed(() => {
  let classes = 'notices fct-notices-wrap fct-layout-width ';

  if (route.fullPath.includes('settings')) {
    classes += 'settings';
  } else if (route.fullPath.includes('reports')) {
    classes += 'reports';
  }

  return classes;
});

const hasNotices = computed(() => {
  return appVars.admin_notices && appVars.admin_notices.length > 0;
});

const showNotice = ref(false);
onMounted(() => {
  nextTick(() => {
    showNotice.value = true;
  });
});
</script>

<template>
  <div :class="adminNoticeClass" v-if="hasNotices">
      <template v-for="notice in appVars.admin_notices">
        <Alert type="warning" icon="WarningFill">
          <div v-html="notice"/>
        </Alert>
      </template>
  </div>
</template>
