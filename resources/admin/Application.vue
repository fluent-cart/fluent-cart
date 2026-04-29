<script setup>
import {onMounted, ref, watch, computed, nextTick} from "vue";
import {useRoute} from "vue-router";
import ThemeToggle from "@/Bits/Components/Buttons/ThemeToggle.vue";
import GlobalSearch from "@/Modules/GlobalSearch/GlobalSearch.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";


defineOptions({
  name: 'DashboardApplication'
})

const currentUrl = ref('');

onMounted(() => {
  jQuery('.notice:not(.fluent-cart), .error:not(.fluent-cart), #ehp-admin-cb').remove();
  currentUrl.value = getCurrentUrl()

  // Scroll effect on header
  const header = document.querySelector('.fct_admin_menu_wrap');
  const handleScroll = () => {
    if (window.scrollY > 10) {
      header?.classList.add('scroll-header');
    } else {
      header?.classList.remove('scroll-header');
    }
  };
  window.addEventListener('scroll', handleScroll);
});

const getCurrentUrl = () => {
  return window.location.href
}

const teleportReady = ref(false);
const route = useRoute()

watch(
    () => route.fullPath,
    async () => {
      currentUrl.value = getCurrentUrl()
      teleportReady.value = false;
      await nextTick(() => {
        setTimeout(() => {
          teleportReady.value = true;
        }, 100);
      })
    }
);

const shouldShowAdminNotice = computed(() => {
  return !(route.fullPath.includes('reports') || route.fullPath.includes('settings'));
});
</script>
<template>
  <div>
    <GlobalSearch/>
    <AdminNotice v-if="shouldShowAdminNotice"/>

    <div class="fl_app fluent-cart-admin-pages">
      <router-view></router-view>
    </div>

    <teleport to="#theme-button-container">
      <ThemeToggle/>
    </teleport>
  </div>
</template>

