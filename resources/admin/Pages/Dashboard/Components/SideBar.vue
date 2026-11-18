<template>
  <div class="fc_dashboard_sidebar bg-white dark:bg-dark-700 p-5 rounded">

    <UserCan permission="is_super_admin">
      <Onboarding @status="onOnboardingStatus"/>

      <ReviewPrompt v-if="isSetupComplete"/>

      <AddonRecommendations :items="recommendations"/>
    </UserCan>

    <RecentActivity @reload=""/>
  </div>
</template>

<script setup>
import {ref} from "vue";
import Onboarding from "@/Pages/Dashboard/Components/Onboarding.vue";
import RecentActivity from "@/Pages/Dashboard/Components/RecentActivity.vue";
import ReviewPrompt from "@/Pages/Dashboard/Components/ReviewPrompt.vue";
import AddonRecommendations from "@/Pages/Dashboard/Components/AddonRecommendations.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";

// The feedback card is admin-only and waits until every Getting Started step is done.
const isSetupComplete = ref(false);
const recommendations = ref([]);

const onOnboardingStatus = (status) => {
  isSetupComplete.value = !!status.completed;
  recommendations.value = status.recommendations || [];
};
</script>
