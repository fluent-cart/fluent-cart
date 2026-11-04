<template>
  <div v-if="items.length" class="fct-carrier-notices">
    <Alert
        v-for="(notice, index) in items"
        :key="index"
        :type="notice.type"
        :icon="noticeIcon(notice.type)"
    >
      <template #content>
        <div class="fct-carrier-notice-body">
          <span>{{ notice.message }}</span>
          <router-link v-if="notice.path" :to="notice.path" class="fct-text-link fct-carrier-notice-action">
            {{ notice.action.label }}
          </router-link>
        </div>
      </template>
    </Alert>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import Alert from '@/Bits/Components/Alert.vue';
import { carrierNotices, noticeIcon, noticeRoutePath } from './carrierNotices';

const props = defineProps({
  carrier: {
    type: Object,
    default: null
  }
});

const router = useRouter();

const items = computed(() => carrierNotices(props.carrier).map((notice) => ({
  ...notice,
  path: notice.action ? noticeRoutePath(router, notice.action.route) : null,
})));
</script>

<style scoped lang="scss">
.fct-carrier-notices {
  @apply grid gap-2 mb-5;
}

.fct-carrier-notice-body {
  @apply flex flex-1 flex-wrap items-baseline gap-x-3 gap-y-1 text-sm;
}

.fct-carrier-notice-action {
  @apply whitespace-nowrap;
}
</style>
