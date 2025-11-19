<script setup>
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";

const loader1 = [
  {
    title: translate('Gross Summary'),
    key: 'gross_summary',
  },
  {
    title: translate('Gross Revenue Quarterly'),
    key: 'net_summary',
  }
];

const loader2 = [
  {
    title: translate('Net Revenue'),
    key: 'net_revenue',
  },
  {
    title: translate('Net Revenue Quarterly'),
    key: 'net_revenue_quarterly',
  }
];

const loader3 = [
  {
    title: translate('Top Country Gross Volume'),
    key: 'top_country_gross',
  },
  {
    title: translate('Top Country Net Volume'),
    key: 'top_country_net',
  }
];

const loader4 = [
  {
    title: translate('Top Countries Gross Revenue'),
    key: 'top_country_revenue',
  },
  {
    title: translate('Top Countries Net Revenue'),
    key: 'top_country_net_revenue',
  }
];

</script>

<template>
  <div class="fct-overview-report-loader-wrap">
    <div class="report-overview-col-2 grid gap-x-7.5 grid-cols-2">
      <div v-for="(loader, key) in loader1" :key="key">
        <Card.Container>
          <Card.Body>
            <Card.Header :border_bottom="true">
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ loader.title }}</h3>
                <div class="flex gap-2 mt-1">
                  <el-skeleton animated>
                    <template #template>
                      <el-skeleton-item variant="p" class="w-[100px]"/>
                    </template>
                  </el-skeleton>
                </div>
              </template>
            </Card.Header>
            <div class="py-6">
              <el-skeleton animated :rows="7"/>
            </div>
          </Card.Body>
        </Card.Container>
      </div>
    </div>
    <div class="grid grid-cols-1">
      <div v-for="index in 2" :key="index">
        <Card.Container>
          <Card.Body>
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item variant="p" class="w-2/3"/>
              </template>
            </el-skeleton>
            <Card.Header :border_bottom="true">
              <template #title>
                <h2 class="fct-card-header-title is-small">
                  {{translate('Gross Volume Breakdown')}}
                </h2>

              </template>

            </Card.Header>
            <div class="py-6">
              <el-skeleton animated :rows="7"/>
            </div>
          </Card.Body>
        </Card.Container>
      </div>
    </div>
    <div class="report-overview-col-2 grid gap-x-7.5 grid-cols-2">
      <div v-for="(loader, key) in loader2" :key="key">
        <Card.Container>
          <Card.Body>
            <Card.Header :border_bottom="true">
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ loader.title }}</h3>
                <div class="flex gap-2 mt-1">
                  <el-skeleton animated>
                    <template #template>
                      <el-skeleton-item variant="p" class="w-[100px]"/>
                    </template>
                  </el-skeleton>
                </div>
              </template>
            </Card.Header>
            <div class="py-6">
              <el-skeleton animated :rows="7"/>
            </div>
          </Card.Body>
        </Card.Container>
      </div>
    </div>
    <div class="grid grid-cols-1">
      <div v-for="(loader, index) in loader3" :key="index">
        <Card.Container>
          <Card.Body>
            <Card.Header :border_bottom="true">
              <template #title>
                <h2 class="fct-card-header-title is-small">
                  {{loader.title }}
                </h2>

              </template>

            </Card.Header>
            <div class="py-6">
              <el-skeleton animated :rows="7"/>
            </div>
          </Card.Body>
        </Card.Container>
      </div>
    </div>
    <div class="report-overview-col-2 grid gap-x-7.5 grid-cols-2">
      <div v-for="(loader, key) in loader4" :key="key">
        <Card.Container>
          <Card.Body>
            <Card.Header :border_bottom="true">
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ loader.title }}</h3>
              </template>
            </Card.Header>
            <div class="py-6">
              <el-skeleton animated :rows="7"/>
            </div>
          </Card.Body>
        </Card.Container>
      </div>
    </div>
  </div>
</template>
