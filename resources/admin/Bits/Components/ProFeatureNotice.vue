<template>
  <div class="fct-pro-feature-notice-wrap">
    <div class="fct-pro-feature-content">
      <div class="fct-pro-feature-icon">
        <DynamicIcon name="Crown"/>
      </div>

      <h4 v-if="title" class="fct-pro-feature-title">
        {{ title }}
      </h4>

      <p v-if="text" class="fct-pro-feature-text">
        {{text}}
      </p>

      <slot></slot>

      <el-button v-if="showButton" type="primary" tag="a" :href="buttonUrl || upgradeUrl(placement)" :target="buttonUrl ? '_self' : '_blank'">
        <DynamicIcon name="Crown"/>
        {{ buttonText || translate('Upgrade to Pro') }}
      </el-button>
    </div>
  </div>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import {upgradeUrl} from "@/Bits/common";

export default {
  name: "ProFeatureNotice",
  components: {
    DynamicIcon
  },
  props: {
    title: {
      type: String
    },
    text: {
      type: String
    },
    placement: {
      type: String,
      default: 'upgrade_page'
    },
    // Optional in-admin action instead of the upgrade link, e.g. "Update FluentCart Pro".
    buttonText: {
      type: String,
      default: ''
    },
    buttonUrl: {
      type: String,
      default: ''
    },
    showButton: {
      type: Boolean,
      default: true
    }
  },
  methods: {translate, upgradeUrl}
}
</script>
