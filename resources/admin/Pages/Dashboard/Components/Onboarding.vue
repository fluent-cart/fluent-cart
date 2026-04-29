<script setup>

import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import StepCard from "@/Bits/Components/StepCard/StepCard.vue";
import {getCurrentInstance, onMounted, ref, computed} from "vue";
import {inject} from "vue"
import Rest from "@/utils/http/Rest";


const completed = ref(0)
const loading = ref(false);
const selfRef = getCurrentInstance().ctx;
const steps = ref([]);
const shouldShowOnboardings = ref(true)

const isCollapsed = ref(localStorage.getItem('started_content_collapse') === 'true' || false);

const toggleCollapse = () => {
  isCollapsed.value = !isCollapsed.value;
  localStorage.setItem('started_content_collapse', isCollapsed.value.toString());
}

// Computed property to calculate the completion percentage
const completionPercentage = computed(() => {
  const stepsLength = Object.keys(steps.value).length;
  return stepsLength ? Math.round((completed.value / stepsLength) * 100) : 0;
});

const dashboardTogglePageNotification = inject("dashboardTogglePageNotification")

onMounted(async () => {
  loading.value = true;

  
  Rest.get('dashboard').then((response) => {
    steps.value = response.data.steps
    completed.value = response.data.completed
    shouldShowOnboardings.value = Object.keys(steps.value).length !== completed.value;
    loading.value = false;    

    let pageSetupCopleted = steps.value.page_setup?.completed || false;
    if (dashboardTogglePageNotification) {
       dashboardTogglePageNotification(!pageSetupCopleted)
    }


  })
      .catch((error) => {
        loading.value = false;
      })


})
</script>

<template>
  <div v-if="shouldShowOnboardings">
    <el-skeleton v-if="loading" :rows="3" animated/>

    <div v-else class="fc_dashboard_onboarding_wrap">
      <h3 class="fc_dashboard_onboarding_title">{{ $t('Onboarding Checklist') }}</h3>

      <div class="fc_dashboard_onboarding_lists">
        <a v-for="(item, itemIndex) in steps" :key="itemIndex" :href="item.url"
           :class="item.completed === true ? 'active' : ''" class="fc_dashboard_onboarding_item">
          <span class="circle-shape">
            <el-icon v-if="item.completed === true"><Check/></el-icon>
          </span>
          <div class="fc_dashboard_onboarding_item__title">
            {{ item.title }}
          </div>
        </a>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>