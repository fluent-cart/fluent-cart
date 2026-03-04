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

// Local reactive notices
const notices = ref([]);

// Sync with appVars on mount
onMounted(() => {
  notices.value = appVars.admin_notices || [];
});

// Watch appVars for changes
watch(
  () => appVars.admin_notices,
  (newVal) => {
    notices.value = [...newVal];
  },
  { deep: true }
);

// Layout classes
const adminNoticeClass = computed(() => {
  let classes = 'notices fct-notices-wrap fct-layout-width ';

  if (route.fullPath.includes('settings')) {
    classes += 'settings';
  } else if (route.fullPath.includes('reports')) {
    classes += 'reports';
  }
  return classes.trim();
});

// Visibility
const hasNotices = computed(() => notices.value.length > 0);

// Notice configuration mapping
const NOTICE_CONFIG = {
  activate_license: { icon: 'WarningFill', type: 'warning' },
  deactivate_license: { icon: 'WarningFill', type: 'warning' },
  update_license: { icon: 'WarningFill', type: 'warning' },
  renew_license: { icon: 'WarningFill', type: 'warning' },
  fluent_cart_license_activated: { icon: 'CheckCircleFill', type: 'success' },
  fluent_cart_license_notice: { icon: 'WarningFill', type: 'warning' },
};

// Icon resolver
const noticeIcon = (id) => NOTICE_CONFIG[id]?.icon || 'InformationFill';

// Type resolver
const noticeType = (id) => NOTICE_CONFIG[id]?.type || 'warning';

const LICENSE_NOTICE_IDS = [
  'activate_license',
  'deactivate_license',
  'renew_license',
  'update_license',
  'fluent_cart_license_activated',
  'fluent_cart_license_notice'
];

const upsertNotice = (notice) => {
  if (!notice?.id) return;

  /**
   * License ACTIVATED (success)
   * Remove all license related notices
   */
  if (notice.id === 'fluent_cart_license_activated') {
    notices.value = notices.value.filter(
      n => !LICENSE_NOTICE_IDS.includes(n.id)
    );

    notices.value.push(notice);
    autoRemove(notice);

    appVars.admin_notices = appVars.admin_notices.filter(
      n => !LICENSE_NOTICE_IDS.includes(n.id)
    );
    return;
  }

  /**
   * License state changed (deactivate / renew / update)
   * Remove license activation success notice
   */
  if (['deactivate_license', 'renew_license', 'update_license', 'activate_license', 'fluent_cart_license_notice']
      .includes(notice.id)) {

    notices.value = notices.value.filter(
      n => n.id !== 'fluent_cart_license_activated'
    );
  }

  /**
   * Upsert by ID
   */
  const index = notices.value.findIndex(n => n.id === notice.id);

  if (index !== -1) {
    notices.value.splice(index, 1, notice);
  } else {
    notices.value.push(notice);
  }
    
  // Update global appVar
  appVars.admin_notices = [...notices.value];
};

/**
 * Auto-remove notice
 */
const autoRemove = (notice) => {
  if (!notice.timeout) return;

  setTimeout(() => {
    notices.value = notices.value.filter(n => n.id !== notice.id);
  }, notice.timeout);
};

window.addEventListener('fct-license-updated', (e) => {
  upsertNotice(e.detail);
});
</script>

<template>
  <div :class="adminNoticeClass" v-if="hasNotices">
    <Alert
      v-for="(notice, index) in notices"
      :key="notice.id || index"
      :type="noticeType(notice.id)"
      :icon="noticeIcon(notice.id)"
      :data-fct-notice-wrapper="notice.type"
    >
      <div v-html="notice.html" />
    </Alert>
  </div>
</template>
