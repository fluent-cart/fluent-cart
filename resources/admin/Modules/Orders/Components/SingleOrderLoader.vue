<template>
  <div class="fct-single-order-loader-wrap fct-layout-width">
    <div class="single-page-header fct-page-loader-header flex items-start justify-between">
      <fct-skeleton animated class="flex flex-col items-start gap-2">
          <fct-skeleton variant="text" class="max-w-180" />
          <fct-skeleton variant="text" class="max-w-[100px]" />
      </fct-skeleton>

      <fct-skeleton animated class="flex gap-2 max-w-180">
          <fct-skeleton variant="text" class="w-1/4" />
          <fct-skeleton variant="text" class="w-3/4" />
      </fct-skeleton>
    </div>

    <div class="single-page-body">
      <div class="fct-single-order">
        <div class="fct-single-order-main">

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Order items')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Payments')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Transaction Details')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="3" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom :title="$t('Activity')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="3" />
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
                <fct-skeleton animated :rows="1" />
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
                <fct-skeleton animated :rows="3" />
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
                <fct-skeleton animated :rows="1" />
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
