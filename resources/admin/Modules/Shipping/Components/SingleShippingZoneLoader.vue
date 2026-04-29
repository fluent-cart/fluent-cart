<template>
  <div class="single-shipping-zone-loader">
    <div class="single-page-header flex items-center justify-between">
      <el-breadcrumb :separator-icon="ArrowRight">
        <el-breadcrumb-item>
          <el-skeleton animated class="h-4">
            <template #template>
              <el-skeleton-item variant="p" class="w-[90px] h-4" />
            </template>
          </el-skeleton>
        </el-breadcrumb-item>
        <el-breadcrumb-item>
          <el-skeleton animated class="h-4">
            <template #template>
              <el-skeleton-item variant="p" class="w-[90px] h-4" />
            </template>
          </el-skeleton>
        </el-breadcrumb-item>
        <el-breadcrumb-item>
          <el-skeleton animated class="h-4">
            <template #template>
              <el-skeleton-item variant="p" class="w-[60px] h-4" />
            </template>
          </el-skeleton>
        </el-breadcrumb-item>
      </el-breadcrumb>

      <div class="fct-page-header__actions">
        <el-skeleton animated class="h-9 w-[172px]">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-9 rounded" />
          </template>
        </el-skeleton>
      </div>
    </div>

    <CardContainer class="mb-4">
      <CardHeader :title="translate('Zone Details')" border_bottom title_size="small"/>
      <CardBody>
        <el-skeleton :rows="4" :animated="true"/>
      </CardBody>
    </CardContainer>

    <CardContainer class="mb-4">
      <CardHeader :title="translate('Shipping Methods')" border_bottom title_size="small"/>
      <CardBody>
        <el-skeleton :rows="4" :animated="true"/>
      </CardBody>
    </CardContainer>

  </div>
</template>

<script setup>
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";

</script>
