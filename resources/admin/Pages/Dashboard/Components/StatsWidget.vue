<template>

  <div class="fct-dashboard-stat-widgets">

    <el-skeleton v-if="loading" v-for="i in 4" :key="i" animated
                 class="bg-white dark:bg-dark-700 flex flex-col items-start py-5 px-4.5 rounded-[12px] gap-3">
      <template #template>
        <el-skeleton-item variant="circle" class="w-10 h-10 flex-none"/>
        <div class="right w-full">
          <el-skeleton-item variant="p" class="w-full"/>
          <el-skeleton-item variant="p" class="w-10"/>
        </div>
      </template>
    </el-skeleton>
    <template v-else>
      <div v-for="(stat, index) in stats" :key="index" class="fct-dashboard-stat-widget">
        <a v-if="stat.url" :href="stat.url" class="fct-dashboard-stat-widget__inner no-underline">
          <div class="fct-dashboard-stat-widget__icon">
            <DynamicIcon class="w-5" :name="stat.icon"/>
          </div>
          <div class="fct-dashboard-stat-widget__content">
            <h3 class="fct-dashboard-stat-widget__title">
              <span>
              {{ stat.title }}
              </span>
            </h3>
            <span class="fct-dashboard-stat-widget__value inner-value">
              <span v-if="stat.has_currency" v-html="CurrencyFormatter.scaled(stat.current_count)">

              </span>
              <template v-else>
                {{ translateNumber(formatNumber(stat.current_count)) || 0 }}
              </template>
            </span>
          </div>
        </a>
        <div v-else class="fct-dashboard-stat-widget__inner">
          <div class="fct-dashboard-stat-widget__icon">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                  d="M7.20248 2.62165L5.7818 3.27834C3.59393 4.28965 2.5 4.7953 2.5 5.62533C2.5 6.45535 3.59393 6.961 5.7818 7.97231L7.20249 8.629C8.57936 9.26544 9.26781 9.58366 10 9.58366C10.7322 9.58366 11.4206 9.26544 12.7975 8.629L14.2182 7.97231C16.4061 6.961 17.5 6.45535 17.5 5.62533C17.5 4.7953 16.4061 4.28965 14.2182 3.27834L12.7975 2.62165C11.4206 1.98521 10.7322 1.66699 10 1.66699C9.26781 1.66699 8.57937 1.98521 7.20248 2.62165Z"
                  stroke="#122368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              <path
                  d="M17.3233 9.24805C17.4411 9.41368 17.5 9.5863 17.5 9.77614C17.5 10.5944 16.4061 11.0928 14.2182 12.0897L12.7975 12.7371C11.4206 13.3644 10.7322 13.6781 10 13.6781C9.26781 13.6781 8.57936 13.3644 7.20249 12.7371L5.7818 12.0897C3.59394 11.0928 2.5 10.5944 2.5 9.77614C2.5 9.5863 2.55889 9.41368 2.67667 9.24805"
                  stroke="#122368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              <path
                  d="M16.9806 13.5557C17.3269 13.8315 17.5 14.1064 17.5 14.4319C17.5 15.2501 16.4061 15.7486 14.2182 16.7455L12.7975 17.3928C11.4206 18.0202 10.7322 18.3339 10 18.3339C9.26781 18.3339 8.57936 18.0202 7.20249 17.3928L5.7818 16.7455C3.59394 15.7486 2.5 15.2501 2.5 14.4319C2.5 14.1064 2.67315 13.8315 3.01945 13.5557"
                  stroke="#122368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="fct-dashboard-stat-widget__content">
            <h3 class="fct-dashboard-stat-widget__title">
              <a class="link" v-if="stat.url" :href="stat.url">
                {{ stat.title }}
              </a>
              <span v-else>
              {{ stat.title }}
            </span>
            </h3>
            <span class="fct-dashboard-stat-widget__value">{{ stat.current_count }}</span>
          </div>
        </div>
      </div>
      <DynamicTemplates :data="{stats}" filter="dashboard_stats_widget"/>
    </template>

  </div>
</template>

<script setup>
import {ref, onMounted} from 'vue';

import DynamicTemplates from "@/Bits/Components/DynamicTemplates/DynamicTemplates.vue";
import Rest from "@/utils/http/Rest";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Notify from "@/utils/Notify";
import { formatNumber } from "@/Modules/Reports/Utils/formatNumber"
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {translateNumber} from "@/utils/translator/Translator";

const loading = ref(true);
const stats = ref([])

const fetchStat = () => {
  loading.value = true;
  Rest.get('dashboard/stats')
      .then(response => {
        stats.value = response.stats;
      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false;
      });
}

onMounted(() => {
  fetchStat();
});
</script>
