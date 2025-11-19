<template>
  <div class="fct-single-order-loader-wrap fct-layout-width">
    <div class="single-page-header fct-page-loader-header min-h-18 flex items-start justify-between">
      <el-skeleton animated class="flex flex-col items-start gap-2">
        <template #template>
          <el-skeleton-item class="max-w-180" />
          <el-skeleton-item class="max-w-[100px]" />
        </template>
      </el-skeleton>
    </div>

    <div class="single-page-body">
      <div class="fct-single-order">
        <div class="fct-single-order-main">

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Subscription Details')">
            </Card.Header>
            <Card.Body class="grid gap-5 grid-cols-[repeat(auto-fit,minmax(200px,1fr))]">
              <el-skeleton v-for="i in 9" :key="i" :animated="true">
                <template #template>
                  <div>
                    <el-skeleton-item variant="p" class="w-full" />
                    <el-skeleton-item variant="p" style="width: 50%" />
                  </div>
                </template>
              </el-skeleton>
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Related Orders')">
            </Card.Header>

            <Card.Body>
              <el-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Related Licenses')">
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
                  :title="$t('Subscription Plan')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <el-skeleton animated :rows="2" />
              </Card.Body>
            </Card.Container>

            <Card.Container>
              <Card.Header
                  :title="$t('Customer Details')"
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
<script>
export default {
  props: {
    loading: {
      type: Boolean,
      default: false
    }
  }
}

</script>


<style scoped>

</style>
