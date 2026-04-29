<template>
  <div class="fc_dashboard_sidebar bg-white dark:bg-dark-700 p-5 rounded">

    <UserCan permission="is_super_admin">
      <Onboarding/>
    </UserCan>

    <RecentActivity @reload=""/>
  </div>
</template>

<script setup>
import Onboarding from "@/Pages/Dashboard/Components/Onboarding.vue";
import RecentActivity from "@/Pages/Dashboard/Components/RecentActivity.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
</script>
