<script setup>
import translate from "@/utils/translator/Translator";

const overrideRows = [1, 2, 3];
</script>

<template>
  <div>
    <div class="fct-card fct-card-border fct-vat-card">
      <div class="fct-card-body">
        <div class="fct-vat-card-header">
          <div class="fct-vat-card-title">
            <h3>{{ translate('Collect VAT cross-border') }}</h3>
          </div>
        </div>

        <div class="fct-vat-card-body">
          <div class="fct-sk w-[200px] h-3 rounded-xs" />
          <div class="fct-sk w-full h-3 rounded-xs mt-3" />
        </div>
      </div>
    </div>

    <div class="fct-card fct-card-border fct-vat-card mt-5">
      <div class="fct-card-body">
        <div class="fct-vat-card-header">
          <div class="fct-vat-card-title">
            <h3>{{ translate('Tax overrides') }}</h3>
         </div>
        </div>

        <div class="fct-tax-rates-country-view">
          <div class="border border-gray-200 rounded-md overflow-hidden dark:border-dark-300">
            <div class="grid grid-cols-[110px_1fr_1fr_1fr_100px_80px] gap-4 py-3 border-b border-gray-200 dark:border-dark-300">
              <div v-for="index in 6" :key="'header-' + index" class="fct-sk h-3 rounded-xs" />
            </div>

            <div
              v-for="row in overrideRows"
              :key="'override-row-' + row"
              class="grid grid-cols-[110px_1fr_1fr_1fr_100px_80px] gap-4 py-4 border-b border-gray-100 last:border-b-0 dark:border-dark-400"
            >
              <div class="fct-sk w-16 h-6 rounded-full" />
              <div class="fct-sk w-28 h-3.5 rounded-xs" />
              <div class="fct-sk w-24 h-3.5 rounded-xs" />
              <div class="fct-sk w-24 h-3.5 rounded-xs" />
              <div class="fct-sk w-12 h-3.5 rounded-xs" />
              <div class="fct-sk w-4 h-4 rounded-xs justify-self-end" />
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
