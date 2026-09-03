<script setup>
import translate from "@/utils/translator/Translator";

const stateRows = [1, 2, 3];
</script>

<template>
  <div>


    <div class="fct-base-taxes">
      <div class="fct-tax-class-tabs">
        <div class="fct-tax-class-tabs__list">
          <div v-for="index in 3" :key="index" class="fct-tax-class-tabs__tab">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item variant="text" class="w-16 h-5"/>
              </template>
            </el-skeleton>
          </div>
          <el-skeleton animated>
            <template #template>
            <el-skeleton-item variant="text" class="w-5 h-5"/>
            </template>
         </el-skeleton>
        </div>
      </div>

      <div class="fct-base-taxes__header">
        <span class="fct-base-taxes__header-label">{{ translate('Regions') }}</span>
      </div>

      <div class="fct-base-taxes__state-row fct-base-taxes__state-row--federal">
        <div class="fct-base-taxes__region-name fct-base-taxes__region-name--federal">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="text" class="w-40 h-5"/>
            </template>
          </el-skeleton>
        </div>
        <div class="fct-base-taxes__state-fields">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="rect" class="fct-base-taxes__field-rate h-8"/>
            </template>
          </el-skeleton>
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="rect" class="fct-base-taxes__field-name h-8"/>
            </template>
          </el-skeleton>
          <div class="fct-base-taxes__field-compound"></div>
        </div>
      </div>

      <div class="fct-base-taxes__divider"></div>

      <div
          v-for="row in stateRows"
          :key="row"
          class="fct-base-taxes__state-row"
      >
        <div class="fct-base-taxes__region-name">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="text" class="w-32 h-4"/>
            </template>
          </el-skeleton>
        </div>
        <div class="fct-base-taxes__state-fields">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="rect" class="fct-base-taxes__field-rate h-8"/>
            </template>
          </el-skeleton>
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="rect" class="fct-base-taxes__field-name h-8"/>
            </template>
          </el-skeleton>
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item variant="rect" class="fct-base-taxes__field-compound h-8"/>
            </template>
          </el-skeleton>
        </div>
      </div>
    </div>
  </div>
</template>
