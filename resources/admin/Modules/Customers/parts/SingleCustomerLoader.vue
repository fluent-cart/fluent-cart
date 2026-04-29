<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
</script>

<template>
  <div class="fct-single-customer-loader-wrap fct-layout-width">
    <div class="single-page-header fct-page-loader-header flex items-start justify-between">
      <el-skeleton animated class="flex items-start gap-2">
        <template #template>
          <el-skeleton-item v-for="index in 3" :key="index" class="max-w-[100px]" />
        </template>
      </el-skeleton>
    </div>

    <div class="single-page-body">
      <div class="fct-single-order">
        <div class="fct-single-order-main">

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom title_size="small" :title="$t('Orders')">
            </Card.Header>

            <Card.Body>
              <el-skeleton animated :rows="5" />
            </Card.Body>
          </Card.Container>
        </div>

        <!-- .fct-single-order-main -->

        <div class="fct-single-order-aside">
          <div class="fct-admin-sidebar">
            <Card.Container>
              <Card.Header
                  :title="$t('Customer Informations')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated :rows="5" />
              </Card.Body>
            </Card.Container>

            <Card.Container>
              <Card.Header
                  :title="$t('Labels')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated :rows="1" />
              </Card.Body>
            </Card.Container>

          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>

</style>
