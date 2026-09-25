<template>
  <div v-if="!isHidden" class="fct-review-prompt" role="region" :aria-labelledby="titleId">
    <button
        type="button"
        class="fct-review-prompt__close"
        :aria-label="translate('Dismiss feedback request')"
        @click="hide"
    >
      <DynamicIcon name="Cross"/>
    </button>

    <h3 :id="titleId" class="fct-review-prompt__title">
      {{ translate("How's FluentCart working for you?") }}
    </h3>
    <p class="fct-review-prompt__text">
      {{ translate('A short review on WordPress.org helps other store owners decide, and tells us what to improve.') }}
    </p>

    <el-button
        tag="a"
        :href="REVIEW_URL"
        target="_blank"
        rel="noopener noreferrer"
        @click="hide"
    >
      {{ translate('Share your feedback') }}
      <DynamicIcon name="External" class="ml-1.5 w-3.5 h-3.5"/>
    </el-button>
  </div>
</template>

<script setup>
import {ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import {REVIEW_URL, isReviewPromptDismissed, dismissReviewPrompt} from "@/Pages/Dashboard/reviewPrompt";

const titleId = 'fct-review-prompt-title';

const isHidden = ref(isReviewPromptDismissed());

// Both "dismiss" and "share feedback" hide the card for good in this browser.
const hide = () => {
  isHidden.value = true;
  dismissReviewPrompt();
};
</script>
