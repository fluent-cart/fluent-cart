<script setup>
import translate from '@/utils/translator/Translator';
</script>

<!--
  Shown in a Product Gallery tab while its component chunk downloads. A tab is
  registered as a lazy import (see galleryTabs.js), so on a cold cache the pane
  would otherwise sit empty for as long as the request takes.

  The shape mirrors the FluentPlayer tab — one attached-item row above two
  boxed sections — so the pane keeps its height when the real content lands.
-->
<template>
  <div
    class="fct-gallery-tab-loading"
    data-fct-gallery-tab-loading
    role="status"
    :aria-label="translate('Loading...')"
  >
    <el-skeleton animated>
      <template #template>
        <div class="fct-gallery-tab-loading__card">
          <el-skeleton-item variant="image" class="fct-gallery-tab-loading__thumb" />
          <div class="fct-gallery-tab-loading__lines">
            <el-skeleton-item variant="h3" class="fct-gallery-tab-loading__title" />
            <el-skeleton-item variant="text" class="fct-gallery-tab-loading__meta" />
          </div>
        </div>
        <div class="fct-gallery-tab-loading__section" v-for="row in 2" :key="row">
          <el-skeleton-item variant="text" class="fct-gallery-tab-loading__label" />
          <el-skeleton-item variant="button" class="fct-gallery-tab-loading__field" />
        </div>
      </template>
    </el-skeleton>
  </div>
</template>
