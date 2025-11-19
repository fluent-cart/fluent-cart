<template>
  <LicenseStatLoader v-if="loading"/>
  <div
      v-else
      :class="`licenses-stats-card ${itemKey}`"
      v-for="(item, itemKey) in licenseOverview"
      :key="itemKey"
  >
    <div class="licenses-stats-card-head">
      <div class="licenses-icon">
        <DynamicIcon :name="item.icon" />
      </div>

      <div class="licenses-stats-card-head-content">
        <div class="title">{{ translate(item.title) }}</div>
        <div class="value" >{{ item.value }}</div>
      </div>
    </div>

    <div class="text">{{ translate(item.text) }}</div>
  </div>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LicenseStatLoader from "./LicenseStatLoader.vue";
import translate from "@/utils/translator/Translator";

export default {
  name: "LicensesStatsCard",
  props: {
    licenseOverview: {
      type: Object,
      required: true
    },
    loading: {
      type: Boolean
    }
  },
  components: {
    DynamicIcon,
    LicenseStatLoader
  },
  methods: {
    translate
  }
};
</script>
