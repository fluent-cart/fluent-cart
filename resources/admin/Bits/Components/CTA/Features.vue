<script setup>
  import {ElMessageBox} from "element-plus";
  import translate from "@/utils/translator/Translator";
  import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";

  const props = defineProps({
    featureName: String,
    featureDescription: String,
    features: Array,
    onClose: Function
  })
</script>

<template>
  <div class="fluent-cart-admin-pages">
    <ProFeatureNotice>
        <h4 class="fct-pro-feature-title">
          {{
            /* translators: %s is the feature name */
            translate('%s requires FluentCart Pro', featureName)
          }}
        </h4>
        <p class="fct-pro-feature-text" v-if="featureDescription" v-html="featureDescription"></p>
    </ProFeatureNotice>

    <ul v-if="features.length" class="fct-feature-list">
      <li v-for="feature in features" :key="feature" v-html="feature"></li>
    </ul>
  </div>
</template>

<style scoped>

</style>
