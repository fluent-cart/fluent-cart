<template>
    <PdfTemplateProGate v-if="!isProActive" />
    <router-view v-else />
</template>

<script setup>
import AppConfig from "@/utils/Config/AppConfig";
import PdfTemplateProGate from "./PdfTemplateProGate.vue";

const isProActive = AppConfig.get('app_config.isProActive');
</script>
