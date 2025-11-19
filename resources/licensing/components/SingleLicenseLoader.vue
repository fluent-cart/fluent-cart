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
            <Card.Header border_bottom title_size="small" :title="$t('Manage License Details')" />

            <Card.Body>
              <el-skeleton :animated="true" class="mb-5">
                <template #template>
                  <el-skeleton-item variant="p" class="w-full" />
                  <el-skeleton-item variant="p" style="width: 50%" />
                </template>
              </el-skeleton>
              <div class="grid gap-5 grid-cols-[repeat(auto-fit,minmax(200px,1fr))]">
                <el-skeleton v-for="i in 6" :key="i" :animated="true">
                  <template #template>
                    <div>
                      <el-skeleton-item variant="p" class="w-full" />
                      <el-skeleton-item variant="p" style="width: 50%" />
                    </div>
                  </template>
                </el-skeleton>
              </div>
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom title_size="small" :title="$t('Related Orders')" />

            <Card.Body>
              <el-skeleton :animated="true" class="mb-5" :rows="2" />
            </Card.Body>
          </Card.Container>

            <Card.Container class="overflow-hidden">
                <Card.Header border_bottom title_size="small" :title="$t('Activated Sites')" />

                <Card.Body>
                    <el-skeleton :animated="true" class="mb-5" :rows="3" />
                </Card.Body>
            </Card.Container>
        </div>

        <!-- .fct-single-order-main -->

        <div class="fct-single-order-aside">
          <div class="fct-admin-sidebar">
            <Card.Container>
              <Card.Header
                  :title="$t('Customer Information')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated :rows="6" />
              </Card.Body>
            </Card.Container>

            <Card.Container>
              <Card.Header
                  :title="$t('Actions')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated>
                  <template #template>
                    <el-skeleton-item variant="p" class="w-1/2" />
                  </template>
                </el-skeleton>
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
