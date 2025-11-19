<template>
  <div class="fct-taxes-page fct-layout-width">
    <PageHeading :title="translate('Taxes')">
        <template #action>
            <el-tooltip
                v-if="!enableTaxesModal"
                popper-class="fct-tooltip"
                effect="dark"
                :content="translate('Please select the taxes you want to file')"
                placement="top-start"
            >
                <el-button 
                    type="primary"
                    :disabled="!enableTaxesModal"
                >
                    {{ translate('Tax Filing') }}
                </el-button>
            </el-tooltip>

            <template v-else>
                <el-button 
                    type="primary" 
                    @click="openTaxesModal"
                    :disabled="!enableTaxesModal"
                >
                    {{ translate('Tax Filing') }}
                </el-button>
            </template>
            
        </template>
    </PageHeading>

    <TableWrapper :table="taxTable" :has-mobile-slot="false">
      <TaxesLoader
          v-if="taxTable.isLoading()"
          :taxTable="taxTable"
          :next-page-count="taxTable.nextPageCount"
      />

      <div v-else>
        <el-table 
            class="w-full"
            :data="tableData"
            @selection-change="handleSelectionChange"
        >

         <el-table-column type="selection" :width="45" />

         <el-table-column
                v-if="taxTable.isColumnVisible('id')" 
                :label="translate('ID')"
                :width="100"
            >
            <template #default="scope">
              <span>#{{ translateNumber(scope.row.id) }}</span>
            </template>
          </el-table-column>
          
          <el-table-column
                v-if="taxTable.isColumnVisible('order_id')" 
                :label="translate('Order ID')"
                :width="100"
            >
            <template #default="scope">
              <router-link class="link" :to="{ name: 'view_order', params: { order_id: scope.row.order_id } }">
                  #{{ scope.row.order_id }}
              </router-link>
            </template>
          </el-table-column>

          <el-table-column :label="translate('Tax Country')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.country }}</span>
            </template>
          </el-table-column>
          
          <el-table-column :label="translate('Tax Region')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.state }}</span>
            </template>
          </el-table-column>
          
          <el-table-column v-if="taxTable.isColumnVisible('postcode')" :label="translate('Zip Code')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.postcode }}</span>
            </template>
          </el-table-column>
          
          <el-table-column :label="translate('Tax Name')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.name }}</span>
            </template>
          </el-table-column>
          
          <el-table-column v-if="taxTable.isColumnVisible('rate')" :label="translate('Tax Rate')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.rate }}</span>
            </template>
          </el-table-column>
          
          <el-table-column :label="translate('Sale Taxes')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.total_tax }}</span>
            </template>
          </el-table-column>
          
          <el-table-column v-if="taxTable.isColumnVisible('filed_at')" :label="translate('Filed')" :width="120">
            <template #default="scope">
              <span>{{ scope.row.filed_at }}</span>
            </template>
          </el-table-column>

          <template #empty>
            <Empty icon="Empty/ListView" :text="translate('We could\'t find any taxes matching your filter.')"/>
          </template>

        </el-table>
      </div>

      
    </TableWrapper>


    <!-- taxes modal -->
    <el-dialog
        v-model="taxDialogVisible"
        :title="translate('Tax Filing')"
        @open="preselectRows"
        class="fct-tax-filling-modal"
    >
        <div class="fct-tax-filing-wrap">
            <div class="fct-tax-filing-summary">
                <div class="summary-item">
                    <span class="summary-label">{{ translate('Taxable Amount') }}</span>
                    <span class="summary-value">{{ modalSummary.totalTax }}</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">{{ translate('Total Orders') }}</span>
                    <span class="summary-value">{{ translateNumber(modalSummary.totalOrders) }}</span>
                </div>
            </div>

            <div class="fct-tax-filing max-h-[400px] overflow-y-auto">
                <el-table
                    ref="selectedTaxesTableRef"
                    :data="selectedTaxes"
                    @selection-change="handleFinalSelectionChange"
                >

                <el-table-column type="selection" :width="45" />

                <el-table-column :label="translate('ID')" :width="100">
                    <template #default="scope">
                        <span>{{ scope.row.id }}</span>
                    </template>
                </el-table-column>

                <el-table-column :label="translate('Order ID')" :width="100">
                    <template #default="scope">
                        <span>{{ scope.row.order_id }}</span>
                    </template>
                </el-table-column>

                <el-table-column :width="120" :label="translate('Tax Country')" >
                    <template #default="scope">
                    <span>{{ scope.row.country }}</span>
                    </template>
                </el-table-column>
                
                <el-table-column :width="120" :label="translate('Tax Region')" >
                    <template #default="scope">
                    <span>{{ scope.row.state }}</span>
                    </template>
                </el-table-column>
                
                <el-table-column :label="translate('Zip Code')" :width="120">
                    <template #default="scope">
                    <span>{{ scope.row.postcode }}</span>
                    </template>
                </el-table-column>
                
                <el-table-column :width="120" :label="translate('Tax Name')" >
                    <template #default="scope">
                    <span>{{ scope.row.name }}</span>
                    </template>
                </el-table-column>
                
                <el-table-column :label="translate('Tax Rate')" :width="120">
                    <template #default="scope">
                    <span>{{ scope.row.rate }}</span>
                    </template>
                </el-table-column>
                
                <el-table-column :width="150" :label="translate('Sale Taxes')" >
                    <template #default="scope">
                    <span>{{ scope.row.total_tax }}</span>
                    </template>
                </el-table-column>
                
                <template #empty>
                    <Empty icon="Empty/ListView" :text="translate('We could\'t find any taxes matching your filter.')"/>
                </template>

                </el-table>
            </div>


        </div>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="() => { taxDialogVisible = false; }">
                    {{ translate('Cancel') }}
                </el-button>

                <el-button type="primary" @click="exportAndContinue">
                    <DynamicIcon name="Download" style="width: 16px; height: 16px;"/>
                    {{ translate('Download & Continue') }}
                </el-button>
            </div>
        </template>
    </el-dialog>

    <!-- success modal -->
     <el-dialog
        v-model="successDialogVisible"
        class="fct-tax-filling-success-modal"
        :close-on-click-modal="false"
    >
        <div class="fct-tax-filing-success-wrap">
            <div class="fct-tax-filling-success-icon">
                <DynamicIcon name="CheckCircleFill"/>
            </div>
            <div>
                <h4>{{ translate("Don't Forget to Save Your Tax File") }}</h4>

                <p>
                    {{ translate("You're about to mark this tax report as filed.") }}
                </p>

                <p>
                    {{ translate("Make sure you've downloaded and saved the CSV file for your records before proceeding.") }}
                    <a @click.prevent="exportAndContinue" class="cursor-pointer">
                      {{ translate('Download Again') }}
                    </a>
                </p>
            </div>
        </div>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="closeSuccessModal">
                    {{ translate('Cancel') }}
                </el-button>
                <el-button type="primary" @click="markAsFiled">
                    {{ translate('Mark as Filed') }}
                </el-button>
            </div>
        </template>
    </el-dialog>

  </div>
</template>

<script setup>
import useTaxesTable from "@/utils/table-new/TaxesTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {getCurrentInstance, ref, nextTick, computed} from "vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translateNumber from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { exportCSV } from "@/utils/Export.js";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
// import { countryList } from "@/Bits/common";
import TaxesLoader from "@/Modules/Tax/TaxesLoader.vue";
import AllCouponsLoader from "@/Modules/Coupons/AllCouponsLoader.vue";



const selfRef = getCurrentInstance().ctx;
const taxTable = useTaxesTable();
const taxDialogVisible = ref(false);
const successDialogVisible = ref(false);
const enableTaxesModal = ref(false);
const selectedTaxes = ref([]);

const tableData = computed(() => 
  taxTable.getTableData().map(item => ({
    id: item.id,
    order_id: item.order_id,
    country: item.tax_rate?.country,
    state: item.tax_rate?.state,
    postcode: item.tax_rate?.postcode,
    name: item.tax_rate?.name,
    rate: item.tax_rate?.rate,
    total_tax: parseFloat(item.total_tax / 100).toFixed(2),
    filed_at: item.filed_at ? 'Yes' : 'No',
  }))
);

const openTaxesModal = () => {
    taxDialogVisible.value = true;
    successDialogVisible.value = false;
}

const closeTaxesModal = () => {
    taxDialogVisible.value = false;
    successDialogVisible.value = true;
}

const exportAndContinue = async () => {
    try {
        await exportCSV(modalSelectedTaxes.value);
        
        closeTaxesModal();
    } catch (error) {
        console.error('Export failed:', error);
    }
};

const closeSuccessModal = () => {
    successDialogVisible.value = false;
}

const markAsFiled = () => {
  const idsToMark = modalSelectedTaxes.value.map(item => item.id);

  Rest.post(taxTable.getFetchUrl(), { ids: idsToMark })
    .then(response => {
      Notify.success(response.message);

      taxTable.fetch();
    })
    .catch((errors) => {
      Notify.error(errors);
    })
    .finally(() => {
      closeSuccessModal();
    });
}

const handleSelectionChange = (selection) => {
    selectedTaxes.value = selection;
    enableTaxesModal.value = selection.length > 0;
};

const handleFinalSelectionChange = (selection) => {
    modalSelectedTaxes.value = selection;
};

const selectedTaxesTableRef = ref(null);
const modalSelectedTaxes = ref([]);

const modalSummary = computed(() => {
  const totalTax = modalSelectedTaxes.value.reduce((sum, item) => sum + item.total_tax, 0);
  const uniqueOrders = [...new Set(modalSelectedTaxes.value.map(item => item.order_id))];

  return {
    totalTax,
    totalOrders: uniqueOrders.length,
    items: modalSelectedTaxes.value,
  };
});

const preselectRows = () => {
  nextTick(() => {
    modalSelectedTaxes.value = [...selectedTaxes.value];

    selectedTaxes.value.forEach(row => {
      selectedTaxesTableRef.value.toggleRowSelection(row, true);
    });
  });
};
</script>
