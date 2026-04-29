<template>
  <div class="fct-copy-wrap">
    <div class="fct-copy-basic" v-if="showMode === 'basic_copy_btn'">
      <span ref="copyTooltip" class="copy-tooltip">{{ tooltipText }}</span>
      <button class="fct-copy-btn" @click="copyToClipboard">
        <svg viewBox="0 0 20 20" aria-hidden="true" width="20" height="20"><path fill-rule="evenodd" d="M6.515 4.75a2 2 0 0 1 1.985-1.75h3a2 2 0 0 1 1.985 1.75h.265a2.25 2.25 0 0 1 2.25 2.25v7.75a2.25 2.25 0 0 1-2.25 2.25h-7.5a2.25 2.25 0 0 1-2.25-2.25v-7.75a2.25 2.25 0 0 1 2.25-2.25h.265Zm1.985-.25h3a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5Zm-1.987 1.73.002.02h-.265a.75.75 0 0 0-.75.75v7.75c0 .414.336.75.75.75h7.5a.75.75 0 0 0 .75-.75v-7.75a.75.75 0 0 0-.75-.75h-.265a2 2 0 0 1-1.985 1.75h-3a2 2 0 0 1-1.987-1.77Z"></path></svg>
      </button>
    </div>

    <div class="fct-copy-basic-wrap" v-else-if="showMode === 'icon_with_text'">
      <div class="fct-copy-basic">
        <span ref="copyTooltip" class="copy-tooltip">{{ tooltipText }}</span>
        <el-button class="fct-copy-text-btn" text @click="copyToClipboard">
          <DynamicIcon name="Copy"/>
          {{ buttonText }}
        </el-button>
      </div>
      <el-tooltip
          v-if="tooltipContent"
          popper-class="fct-tooltip"
          :content="tooltipContent"
          :placement="placement"
          :show-after="400"
          :hide-after="0"
      >
        <span><DynamicIcon name="InformationFill" class="w-4.5 h-4.5"/></span>
      </el-tooltip>
    </div>

    <div v-else class="fct-copy-advanced flex">
      <input type="text" class="w-full" readonly :value="textToCopy" :placeholder="placeholder"/>
      <span ref="copyTooltip" hidden class="copy-tooltip">{{ $t('Copied!') }}</span>
      <button class="fct-copy-btn flex" @click="copyToClipboard" :disabled="!textToCopy">
        <DynamicIcon name="Copy"/>
        {{ $t('Copy') }}
      </button>
    </div>
  </div>
</template>
<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {ref, defineProps, watch} from "vue";
import Clipboard from "@/utils/Clipboard";
import translate from "@/utils/translator/Translator";

const props = defineProps({
    showMode: {
      type: String,
      default: ''
    },
    tooltipText: {
      type: String,
      default: translate('Copy')
    },
    text: String,
    placeholder: String,
    buttonText: {
      type: String,
      default: translate('Copy')
    },
    tooltipContent: {
      type: String,
      default: ''
    },
    placement: {
      type: String,
      default: 'top-end'
    }
})

const textToCopy = ref(props.text);

const copyTooltip = ref(null);
const copyToClipboard = (e) => {
    e.preventDefault();
    const plainText = textToCopy.value;
    Clipboard.copy(plainText);
}

watch(() => props.text, (text) => {
    textToCopy.value = text;
}, { deep: true });

</script>

