<script setup>
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import translate from "@/utils/translator/Translator";

// Each card: {key, title, value, info, route, change (null hides it), previous, money, points, liability, loading, failed}.
// A card's own loading shows a placeholder for its figure while the row's other cards already show theirs
defineProps({
  cards: {type: Array, required: true},
  loading: {type: Boolean, default: false},
});

/* translators: %1$s - left empty; the text follows a change in percentage points, e.g. "+1.2%" then " pts" */
const pointsSuffix = translate('%1$s pts', '');
</script>

<template>
  <SummaryLoader v-if="loading" :loading="true" :count="cards.length || 3"/>
  <div v-else class="fct-report-order-summary" :class="'summary-col-' + cards.length">
    <div class="summary-item" v-for="item in cards" :key="item.key">
      <div class="summary-item-inner">
        <div class="value">
          <el-skeleton v-if="item.loading" animated :rows="0" class="w-24" :aria-label="translate('Loading')"/>
          <!-- A failed request has no figure: never show its fallback 0 -->
          <div v-else-if="item.failed" class="inner-value text-sm text-system-light dark:text-gray-400">{{ translate('Unavailable') }}</div>
          <div v-else class="inner-value">
            <ReportSummary :amount="item.value" :title="item.title"/>
          </div>
          <Fluctuation
              v-if="!item.loading && !item.failed && item.change !== null && item.change !== undefined"
              :fluctuation="item.change"
              :amount="item.previous"
              :has-currency="!!item.money"
              :is-percentage="!!item.points"
              :is-liability="!!item.liability"
              :fluctuation-suffix="item.points ? pointsSuffix : ''"
          />
        </div>
        <div class="title">
          <router-link :to="{name: item.route}" class="text-inherit hover:underline">{{ item.title }}</router-link>
          <el-tooltip :content="item.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
            <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
              <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true"/>
            </button>
          </el-tooltip>
        </div>
      </div>
    </div>
  </div>
</template>
