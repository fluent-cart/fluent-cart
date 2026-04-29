<template>
  <div class="fct-license-stats-wrap">
    <CardContainer>
      <CardHeader :title="translate('Overall License Stats')" border_bottom title_size="small"/>
      <CardBody>
        <div class="licenses-stats-row">
          <LicensesStatsCard :licenseOverview="licenseOverview" :loading="loading"/>
        </div>
      </CardBody>
    </CardContainer>

    <el-row :gutter="30">
      <el-col :md="24" :lg="16">
        <LicenseStatistics/>
      </el-col>

      <el-col :md="24" :lg="8">
        <CardContainer>
          <CardHeader :title="translate('Usage Statistics')" border_bottom title_size="small"/>
          <CardBody>
            <div class="fct-usage-statistics-wrap">
              <el-skeleton v-if="loading" animated :rows="4" />
              <UsageStatistics v-else :activatedSites="totalActivatedSites"/>
            </div>
          </CardBody>
        </CardContainer>
      </el-col>
    </el-row>
  </div>
</template>

<script>
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import dayjs from "dayjs";
import LicenseStatistics from "@/Charts/LicenseStatistics.vue";
import UsageStatistics from "@/Charts/UsageStatistics.vue";
import LicensesStatsCard from "./_LicensesStatsCard.vue";
import translate from "@/utils/translator/Translator";

export default {
  name: 'LicenseStats',
  props: {
    licenses: {
      type: Array,
      required: true
    },
  },
  components: {
    DynamicIcon,
    CardBody,
    CardHeader,
    CardContainer,
    LicenseStatistics,
    UsageStatistics,
    LicensesStatsCard
  },
  data() {
    return {
      loading: false,
      licenseSummary: {}
    };
  },
  methods: {
    translate,
    fetchSummary(){
      this.loading = true;

      this.$get("reports/license-summary", {
        params: {
          start_date: dayjs().startOf("month").format("YYYY-MM-DD"),
          end_date: dayjs().format("YYYY-MM-DD"),
        },
      })
        .then((response) => {
          this.licenseSummary = response.summaryData;
        })
        .catch((error) => {
          this.handleError(error);
        }).finally(() => {
          this.loading = false;
        });
    }
  },
  computed: {
    totalLicensedProducts() {
      return this.licenseSummary?.totalLicensedProducts || 0;
    },
    totalActivatedSites() {
      return this.licenseSummary?.totalActivatedSites || 0;
    },
    totalActiveLicense() {
      return this.licenseSummary?.totalActiveLicense ||
          this.licenses.filter((license) => license.status === "active").length;
    },
    totalExpiredLicense() {
      return this.licenseSummary?.totalExpiredLicense ||
          this.licenses.filter((license) => license.status === "expired").length;
    },
    totalInactiveLicense() {
      return this.licenseSummary?.totalInactiveLicense ||
          this.licenses.filter((license) => license.status === "inactive").length;
    },
    licenseOverview(){
      return {
        "license-product": {
          title: "Licensed Product",
          value: this.totalLicensedProducts,
          text: `Active License ${this.totalActiveLicense}`,
          icon: "LicensedProduct",
        },
        "total-active": {
          title: "Total Active",
          value: this.totalActiveLicense,
          text: `New Active License ${this.totalActiveLicense}`,
          icon: "CheckCircle",
        },
        "total-expired": {
          title: "Total Expired",
          value: this.totalExpiredLicense,
          text: `New Expired License ${this.totalExpiredLicense}`,
          icon: "Warning",
        },
        "inactive-license": {
          title: "Inactive License",
          value: this.totalInactiveLicense,
          text: "Individual Sites",
          icon: "LicensedInactive",
        }
      };
    }
  },
  mounted() {
    this.fetchSummary();
  }
}
</script>
