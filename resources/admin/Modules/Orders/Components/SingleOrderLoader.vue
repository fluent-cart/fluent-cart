<template>
  <div class="fct-single-order-loader-wrap fct-layout-width">
    <div class="single-page-header fct-page-loader-header flex items-start justify-between">
      <el-skeleton animated class="flex flex-col items-start gap-2">
        <template #template>
          <el-skeleton-item class="max-w-180" />
          <el-skeleton-item class="max-w-[100px]" />
        </template>
      </el-skeleton>

      <el-skeleton animated class="flex gap-2 max-w-180">
        <template #template>
          <el-skeleton-item class="w-1/4" />
          <el-skeleton-item class="w-3/4" />
        </template>
      </el-skeleton>
    </div>

    <div class="single-page-body">
      <div class="fct-single-order">
        <div class="fct-single-order-main">

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Order items')">
            </Card.Header>

            <Card.Body>
              <el-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Payments')">
            </Card.Header>

            <Card.Body>
              <el-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Transaction Details')">
            </Card.Header>

            <Card.Body>
              <el-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Activity')">
            </Card.Header>

            <Card.Body>
              <el-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

        </div>

        <!-- .fct-single-order-main -->

        <div class="fct-single-order-aside">
          <div class="fct-admin-sidebar">
            <Card.Container>
              <Card.Header
                  :title="$t('Notes')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated :rows="1" />
              </Card.Body>
            </Card.Container>

            <Card.Container>
              <Card.Header
                  :title="$t('Customer Information')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated :rows="3" />
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

<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";

</script>

<style scoped>

</style>
