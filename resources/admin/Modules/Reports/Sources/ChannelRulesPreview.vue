<script setup>
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import translate from "@/utils/translator/Translator";

// The Channel Rules dialog without Pro: the same dialog, with a locked preview inside
defineProps({
  modelValue: {type: Boolean, default: false},
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
  <el-dialog
      :model-value="modelValue"
      :title="translate('Channel Rules')"
      class="fct-channel-rules-dialog"
      @update:model-value="value => emit('update:modelValue', value)"
  >
    <ProReportPreview
        :title="translate('Name your own channels')"
        :text="translate('SourceRpt_Group_Orders')"
        :bullets="[translate('Partner, affiliate or newsletter channels'), translate('Your other sites count as direct')]"
        placement="feature_lock_report_channel_rules"
    >
      <ProReportSample
          :title="translate('Channel Rules')"
          :columns="[translate('Channel'), translate('Field'), translate('Value')]"
          :rows="[[translate('Partners'), translate('Source'), 'partner'], [translate('Newsletter'), translate('Medium'), 'email']]"
      />
    </ProReportPreview>
  </el-dialog>
</template>
