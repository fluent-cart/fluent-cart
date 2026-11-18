<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";

</script>

<template>
  <div class="fct-single-order-loader-wrap fct-layout-width">
    <div class="single-page-header fct-page-loader-header flex items-start justify-between">
      <fct-skeleton animated class="flex flex-col items-start gap-2">
        <fct-skeleton variant="text" class="max-w-180" />
        <fct-skeleton variant="text" class="max-w-[100px]" />
      </fct-skeleton>

      <fct-skeleton animated class="flex gap-2 max-w-[100px]">
        <fct-skeleton variant="text" class="w-2/4" />
        <fct-skeleton variant="text" class="w-2/4" />
      </fct-skeleton>
    </div>

    <div class="single-page-body">
      <div class="fct-single-order">
        <div class="fct-single-order-main">

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom title_size="small" :title="$t('Title')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="5" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom title_size="small" :title="$t('Pricing')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="5" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom title_size="small" :title="$t('Inventory Management')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated :rows="6" />
            </Card.Body>
          </Card.Container>

          <Card.Container class="overflow-hidden">
            <Card.Header border_bottom title_size="small" :title="$t('Downloadable Asset(s)')">
            </Card.Header>

            <Card.Body>
              <fct-skeleton animated>
                <fct-skeleton variant="p" />
              </fct-skeleton>
            </Card.Body>
          </Card.Container>

        </div>

        <!-- .fct-single-order-main -->

        <div class="fct-single-order-aside">
          <div class="fct-admin-sidebar">
            <Card.Container>
              <Card.Header
                  :title="$t('Publishing')"
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
                  :title="$t('Media')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <fct-skeleton animated>
                  <fct-skeleton variant="image" class="rounded w-full h-[138px] mb-2"/>
                  <fct-skeleton variant="image" class="rounded w-[90px] h-[90px]"/>
                </fct-skeleton>
              </Card.Body>
            </Card.Container>

            <Card.Container>
              <Card.Header
                  :title="$t('Group By')"
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
                  :title="$t('Product Organizer')"
                  border_bottom
                  title_size="small"
              >
              </Card.Header>
              <Card.Body>
                <fct-skeleton animated :rows="3" />
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
