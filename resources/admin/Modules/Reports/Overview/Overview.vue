<script setup>
import {onMounted, ref, computed} from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import Rest from "@/utils/http/Rest";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Arr from "@/utils/support/Arr";
import OverviewChart from "@/Modules/Reports/Overview/OverviewChart.vue";
import GrossVolumeChart from "@/Modules/Reports/Overview/GrossVolumeChart.vue";
import GrossVolumeSummary from "@/Modules/Reports/Overview/GrossVolumeSummary.vue";
import {monthlySummary, quarterlySummary} from "@/Modules/Reports/Overview/grossVolumeSummary";
import CountryVolumeChart from "@/Modules/Reports/Overview/CountryVolumeChart.vue";
import CountryNetChart from "@/Modules/Reports/Overview/CountryNetChart.vue";
import OverviewLoading from "@/Modules/Reports/Overview/OverviewLoading.vue";
import Summary from "./Summary.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  reportFilter: {
    type: Object,
    required: true,
  },
});

// The overview endpoint takes no currency and always reports the base one
const baseCurrency = computed(() => props.reportFilter.data.baseCurrency || AppConfig.get('shop.currency'));
import Empty from "@/Bits/Components/Table/Empty.vue";

const data = ref({});

const loading = ref(true);

const hasError = ref(false);

const growths = ref({
  'gross_revenue_quarterly' : {},
  'net_revenue_quarterly' : {}
});

const fetchData = () => {
  hasError.value = false;
  Rest.get('reports/overview')
      .then((response) => {
        data.value = response.data;
        calculateGrowths();
      })
      .catch(() => {
        hasError.value = true;
      })
      .finally(() => {
        loading.value = false;
      });
};

const calculateGrowths = () => {
  // growths.value['gross_revenue'] = getGrowthPercentage(data.value.gross_revenue);
  growths.value['gross_revenue_quarterly'] = getGrowthPercentage(data.value.gross_revenue_quarterly, 'current', 'prev_year');
  growths.value['net_revenue_quarterly'] = getGrowthPercentage(data.value.net_revenue_quarterly, 'current', 'prev_year');
  // growths.value['gross_summary'] = getGrowthPercentage(data.value.gross_summary, 'total', 'total_prev');
  // growths.value['net_summary'] = getGrowthPercentage(data.value.net_summary, 'total', 'total_prev');

};


const getGrowthPercentage = (data, currentKey = 'current', prevKey = 'prev') => {
  const totalCurrent = Arr.sum(data, '*.' + currentKey);
  const totalPrev = Arr.sum(data, '*.' + prevKey);

  const value = totalPrev === 0 ? null : parseFloat((((totalCurrent - totalPrev) / totalPrev) * 100).toFixed(2));

  return {
    value,
    prev: totalPrev,
    current: totalCurrent
  };
};

const monthlyVolumeSummary = computed(() => monthlySummary(data.value.gross_revenue, baseCurrency.value));

const quarterlyVolumeSummary = computed(() => quarterlySummary(data.value.gross_revenue_quarterly, baseCurrency.value));

const addGrossClass = computed(() => {
  if (data.value.gross_summary?.yoy_growth < 0) {
    return 'danger';
  } else if (data.value.gross_summary?.yoy_growth === null) {
    return 'warning';
  }

  return 'success';
});

onMounted(() => {
  fetchData();
});
</script>

<template>
  <div class="report-overview-page">

    <div class="flex items-center justify-start gap-1 mb-2 text-xs text-system-mid dark:text-system-light">
      <DynamicIcon name="InformationFill" class="w-4 h-4 text-system-light" />

      {{
        /* translators: %s - number of months */
        translate('Last %s Months', translateNumber(12))
      }}
      <span aria-hidden="true">&middot;</span>
      {{
        /* translators: %1$s: base currency code */
        translate('Base currency (%1$s)', baseCurrency)
      }}
    </div>

    <OverviewLoading v-if="loading" />
    <Card.Container v-else-if="hasError || !data.gross_summary">
      <Card.Body>
        <Empty
            icon="Empty/ListView"
            :has-dark="true"
            :text="translate('Could not load the overview report. Reload the page to try again.')"
            class="fct-report-empty-state"
        />
      </Card.Body>
    </Card.Container>
    <template v-else>
      <div class="report-overview-col-2 grid gap-x-7.5 grid-cols-1 xl:grid-cols-2">
        <Card.Container>
          <Card.Body>
            <Card.Header border_bottom>
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ translate('Gross Summary') }}</h3>

                <Summary
                    :info="translate('Total paid on paid, partially paid and refunded orders created in the last 12 months, in the base currency, including tax and shipping. Refunds are not deducted. Compared with the same months a year earlier. Does not follow the date filter.')"
                    :current="data.gross_summary.total"
                    :previous="data.gross_summary.total_prev"
                    :fluctuation="data.gross_summary.yoy_growth"
                />
              </template>

            </Card.Header>

            <OverviewChart :data="data.gross_revenue" dataKey="current" date-range :is-empty="!data.gross_summary.total" />
          </Card.Body>
        </Card.Container>

        <Card.Container>
          <Card.Body>
            <Card.Header border_bottom>
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ translate('Gross Revenue Quarterly') }}</h3>

                <Summary
                    :info="translate('Gross sales for the last four quarters, including the current quarter so far, compared with the same quarters a year earlier. Does not follow the date filter.')"
                    :current="growths.gross_revenue_quarterly.current"
                    :previous="growths.gross_revenue_quarterly.prev"
                    :fluctuation="growths.gross_revenue_quarterly.value"
                />
              </template>
            </Card.Header>


            <OverviewChart :data="data.gross_revenue_quarterly" dataKey="current" :bar-max-width="80" :is-empty="!growths.gross_revenue_quarterly.current" />
          </Card.Body>
        </Card.Container>
      </div>

      <div class="grid grid-cols-1">
        <Card.Container>
          <Card.Body>
            <GrossVolumeChart :title="translate('Gross Volume Breakdown')"
                              :data="data.gross_revenue"
                              dataKey="current"
                              is-date
                              :is-empty="!data.gross_summary.total"
            >
              <template #subtitle>
                <GrossVolumeSummary :summary="monthlyVolumeSummary"/>
              </template>
            </GrossVolumeChart>
          </Card.Body>
        </Card.Container>
        <Card.Container>
          <Card.Body>
            <GrossVolumeChart :title="translate('Gross Revenue Breakdown')"
                              :data="data.gross_revenue_quarterly"
                              dataKey="current"
                              prevKey="prev_year"
                              labelKey="yy_growth"
                              :bar-max-width="80"
                              :is-empty="!data.gross_summary.total"
            >
              <template #subtitle>
                <GrossVolumeSummary :summary="quarterlyVolumeSummary"/>
              </template>
            </GrossVolumeChart>
          </Card.Body>
        </Card.Container>
      </div>

      <div class="report-overview-col-2 grid gap-x-7.5 grid-cols-1 xl:grid-cols-2">
        <Card.Container>
          <Card.Body>
            <Card.Header :border_bottom="true">
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ translate('Revenue') }}</h3>

                <Summary :title="translate('Revenue')"
                         :info="translate('Gross sales less refunds and taxes for the last 12 months, in the base currency. Shipping charges and payment fees are not deducted. Compared with the same months a year earlier. Does not follow the date filter.')"
                         :current="data.net_summary.total"
                         :previous="data.net_summary.total_prev"
                         :fluctuation="data.net_summary.yoy_growth"
                />
              </template>
            </Card.Header>


            <OverviewChart :data="data.net_revenue" dataKey="current" date-range :is-empty="!data.net_summary.total"/>
          </Card.Body>
        </Card.Container>

        <Card.Container>
          <Card.Body>
            <Card.Header :border_bottom="true">
              <template #title>
                <h3 class="fct-card-header-title is-small">{{ translate('Revenue Quarterly') }}</h3>

                <Summary :title="translate('Revenue Quarterly')"
                         :info="translate('Revenue for the last four quarters, including the current quarter so far, compared with the same quarters a year earlier. Does not follow the date filter.')"
                         :current="growths.net_revenue_quarterly.current"
                         :previous="growths.net_revenue_quarterly.prev"
                         :fluctuation="growths.net_revenue_quarterly.value"
                />
              </template>
            </Card.Header>


            <OverviewChart :data="data.net_revenue_quarterly" dataKey="current" :bar-max-width="80" :is-empty="!data.net_summary.total" />
          </Card.Body>
        </Card.Container>
      </div>
      <div class="grid gap-4 grid-cols-1">
        <Card.Container>
          <Card.Body>
            <CountryVolumeChart :data="data.top_country_gross" :title="translate('Top Country Gross Volume')" />
          </Card.Body>
        </Card.Container>
      </div>

      <div class="grid gap-4 grid-cols-1">
        <Card.Container>
          <Card.Body>
            <CountryVolumeChart :data="data.top_country_net" :title="translate('Top Country Volume')" />
          </Card.Body>
        </Card.Container>
      </div>

      <div class="report-overview-col-2 grid gap-7.5 grid-cols-1 xl:grid-cols-2">
        <Card.Container>
          <Card.Body>
            <CountryNetChart :data="data.top_country_gross?.by_countries" :title="translate('Top Countries Gross Revenue')" />
          </Card.Body>
        </Card.Container>
        <Card.Container>
          <Card.Body>
            <CountryNetChart :data="data.top_country_net?.by_countries" :title="translate('Top Countries Revenue')" />
          </Card.Body>
        </Card.Container>
      </div>
    </template>
  </div>
</template>
