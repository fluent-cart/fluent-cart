<script setup>
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";
import ChurnRatesChart from "@/Modules/Reports/Subscription/ChurnRatesChart.vue";
import SubscriptionCountChart from "@/Modules/Reports/Subscription/SubscriptionCountChart.vue";


const props = defineProps({
  reportFilter: {
    type: Object,
    required: false,
  },
});
</script>

<template>
  <div class="fct-daily-signup-chart-wrap">
    <Card.Container>
      <Card.Header
          :title="translate('Churn Analytics')"
          border_bottom
      >
      </Card.Header>
      <Card.Body>
        <el-row>
          <el-col :lg="12">
            <ChurnRatesChart :reportFilter="reportFilter"/>
          </el-col>

          <el-col :lg="12">
            <SubscriptionCountChart :reportFilter="reportFilter"/>
          </el-col>
        </el-row>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<style scoped>

</style>
