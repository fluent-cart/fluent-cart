<template>
  <div class="fct-report-header" ref="reportHeaderWrap">
    <div class="fct-report-header-top">
      <div class="fct-report-header-top-inner">
        <ReportNavLinks />
      </div>
    </div>
  </div>
</template>

<script setup>
import ReportNavLinks from "@/Modules/Reports/Components/ReportNavLinks.vue";
import {computed, onMounted, onUnmounted, ref} from "vue";
import {useRoute} from "vue-router";
const route = useRoute();

const props = defineProps({
  filterState: {
    type: [Object]
  },
});

const filter = props.filterState;
const reportHeaderWrap = ref(null);
const menuWrapHeight = ref(0);

const updateHeaderOffset = () => {
  const menuWrap = document.querySelector('.fct_admin_menu_wrap');
  if (menuWrap && reportHeaderWrap.value) {
    const baseHeight = menuWrap.offsetHeight;
    menuWrapHeight.value = baseHeight + 32;
    reportHeaderWrap.value.style.top = `${menuWrapHeight.value}px`;
  }
};

const handleScroll = () => {
  if (!reportHeaderWrap.value) return;

  if (window.scrollY > 0) {
    reportHeaderWrap.value.classList.add("is-fixed");
  } else {
    reportHeaderWrap.value.classList.remove("is-fixed");
  }
};

const shouldRenderFilter = computed(() => {
  return !["reports_overview", "reports_customer"].includes(route.name);
});

onMounted(() => {
  updateHeaderOffset();
  window.addEventListener("scroll", handleScroll);
  window.addEventListener("resize", updateHeaderOffset);
});

onUnmounted(() => {
  window.removeEventListener("scroll", handleScroll);
  window.removeEventListener("resize", updateHeaderOffset);
});

</script>

