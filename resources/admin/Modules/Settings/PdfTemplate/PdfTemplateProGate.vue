<template>
  <div class="fct-pdf-pro-gate">
    <div class="fct-pdf-pro-gate-inner">
      <div class="fct-pdf-pro-heading">
        <div class="fct-pdf-pro-icon">
          <DynamicIcon name="Crown"/>
        </div>
        <span class="fct-pdf-pro-label">{{ translate('PDF Templates') }}</span>
        <span class="fct-pdf-pro-badge">{{ translate('Pro') }}</span>
      </div>
      <p class="fct-pdf-pro-desc">{{ translate('Customizable PDF receipts, invoices, and e-invoices with ZUGFeRD support.') }}</p>
      <el-button type="primary" size="small" tag="a" href="https://fluentcart.com/pricing/" target="_blank">
        <DynamicIcon name="Crown"/>
        {{ translate('Upgrade to Pro') }}
      </el-button>
    </div>
  </div>
</template>

<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
</script>

<style scoped>
.fct-pdf-pro-gate {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
}

.fct-pdf-pro-gate-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  max-width: 360px;
}

.fct-pdf-pro-heading {
  display: flex;
  align-items: center;
  gap: 6px;
}

.fct-pdf-pro-icon {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #FFFAEB;
  color: #F6B51E;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.fct-pdf-pro-icon :deep(svg) {
  width: 15px;
  height: 15px;
}

.fct-pdf-pro-label {
  font-size: 15px;
  font-weight: 600;
  color: var(--fct-text-primary, #0E121B);
  line-height: 1;
}

.fct-pdf-pro-badge {
  font-size: 11px;
  font-weight: 600;
  color: #F6B51E;
  background: #FFFAEB;
  border-radius: 4px;
  padding: 1px 6px;
  line-height: 1.4;
}

.fct-pdf-pro-desc {
  font-size: 13px;
  color: var(--fct-text-secondary, #525866);
  margin: 8px 0 14px;
  line-height: 1.5;
}

.fct-pdf-pro-gate-inner :deep(.el-button .icon svg) {
  width: 14px;
  height: 14px;
}
</style>
