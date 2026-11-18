<script setup>
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import useProductTable from "@/utils/table-new/ProductTable";
import {formatNumber} from "@/Bits/productService";
import translate from "@/utils/translator/Translator";
import AddProductModal from "@/Modules/Products/AddProductModal.vue";
import {computed, getCurrentInstance, onMounted, onUnmounted, ref} from "vue";
import CreateDummyProduct from "@/Modules/Products/parts/CreateDummyProduct.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import Permission from "@/utils/permission/Permission";
import ProductsLoader from "./parts/ProductsLoader.vue";
import ProductsLoaderMobile from "./parts/ProductsLoaderMobile.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ProductsTable from "@/Modules/Products/parts/ProductsTable.vue";
import ProductsTableMobile from "@/Modules/Products/parts/ProductsTableMobile.vue";
import ProductActionModal from "@/Modules/Products/parts/ProductActionModal.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {ArrowDown} from '@element-plus/icons-vue';


const productTable = useProductTable({
  instance: getCurrentInstance()
});

const canCreateProducts = Permission.has('products/create');
const isAddProductModalVisible = ref(false);
const isProductActionModalVisible = ref(false);
const currentAction = ref('duplicate'); // 'duplicate' or 'delete'
const selectedProduct = ref(null);
const selectedProducts = ref([]);
const selectedBulkAction = ref('');

const isMobileView = ref(false);

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768;
};

const getPrice = (detail) => {
  if (detail.min_price === detail.max_price) {
    return formatNumber(detail.min_price, true);
  }
  return `${formatNumber(detail.min_price, true)} - ${formatNumber(detail.max_price, true)}`;
}

const deleteProduct = (id) => {
  Rest.delete(`products/${id}`)
      .then(response => {
        Notify.success(response);
        productTable.fetch();
      })
      .catch(errors => {
        if (errors.status_code.toString() === '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      });
}

const duplicateProduct = (product) => {
  selectedProduct.value = product;
  selectedProducts.value = [];
  currentAction.value = 'duplicate';
  isProductActionModalVisible.value = true;
}

const handleProductDuplicated = () => {
  productTable.fetch();
  selectedProduct.value = null;
  selectedProducts.value = [];
}

const handleBulkProductsCompleted = () => {
  productTable.fetch();
  selectedProducts.value = [];
  selectedBulkAction.value = '';
  productTableRef.value?.clearSelection?.();
}

const handleSelectionChange = (products) => {
  selectedProducts.value = products;
}

const handleBulkDuplicate = () => {
  if (selectedProducts.value.length === 0) {
    Notify.warning({
      message: translate('Please select at least one product to duplicate')
    });
    return;
  }
  selectedProduct.value = null;
  currentAction.value = 'duplicate';
  isProductActionModalVisible.value = true;
}

const handleBulkDelete = () => {
  if (selectedProducts.value.length === 0) {
    Notify.warning({
      message: translate('Please select at least one product to delete')
    });
    return;
  }
  selectedProduct.value = null;
  currentAction.value = 'delete';
  isProductActionModalVisible.value = true;
}

const handleBulkAction = () => {
  if (!selectedBulkAction.value) {
    Notify.warning({
      message: translate('Please select a bulk action')
    });
    return;
  }

  if (selectedBulkAction.value === 'duplicate') {
    handleBulkDuplicate();
  } else if (selectedBulkAction.value === 'delete') {
    handleBulkDelete();
  }
}

const selectedProductIds = computed(() => {
  return selectedProducts.value.map(product => product.ID);
});

const hasSelectedProducts = computed(() => {
  return selectedProducts.value.length > 0;
});

const isBulkAction = computed(() => {
  return selectedProducts.value.length > 0;
});

const productTableRef = ref(null);

const getAvailableCount = (product) => {
  if (Array.isArray(product.variants)) {
    // Your logic here
  }
  return 100;
}

onMounted(() => {
  checkMobileView();
  window.addEventListener('resize', checkMobileView);
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobileView);
});
</script>

<template>
  <div class="fct-all-products-page fct-layout-width">
    <PageHeading :title="translate('Products')">
      <template #action>
        <UserCan permission="products/view">
          <fct-dropdown
              trigger="click"
              placement="bottom-end"
              :items="canCreateProducts ? [
                {name: 'dummy', class: 'item-link'},
                {command: 'product_bulk_insert', label: translate('Bulk Product Insert'), icon: 'Insert'},
                {command: 'product_bulk_edit', label: translate('Bulk Product Edit'), icon: 'Edit'}
              ] : []"
              @command="(command) => command && $router.push({ name: command })"
          >
            <fct-button>
              {{ translate('More actions') }}
              <el-icon>
                <ArrowDown/>
              </el-icon>
            </fct-button>
            <template #item="{item}">
              <CreateDummyProduct v-if="item.name === 'dummy'" @on-product-created="productTable.fetch()" :products="productTable.getTableData()"/>
              <template v-else>
                <DynamicIcon :name="item.icon" />
                {{ item.label }}
              </template>
            </template>
          </fct-dropdown>
        </UserCan>

        <UserCan permission="products/create">
          <fct-button type="primary" @click="()=>{
            isAddProductModalVisible = true;
          }">
            {{ translate('Add Product') }}
          </fct-button>
        </UserCan>
      </template>
    </PageHeading>

    <UserCan permission="products/view">
      <div class="fct-all-products-wrap">

        <TableWrapper :table="productTable" :classicTabStyle="true" :has-mobile-slot="true">

          <transition name="slide-fade">
            <div v-if="hasSelectedProducts" class="bulk-actions-bar">
              <!-- Left side actions -->
              <div class="bulk-left flex items-center gap-2">
                <fct-select
                    size="small"
                    class="bulk-select min-w-[180px]"
                    :placeholder="translate('Select Bulk Action')"
                    v-model="selectedBulkAction"
                    :options="[{label: translate('Duplicate'), value: 'duplicate'}, {label: translate('Delete'), value: 'delete'}]"
                />

                <fct-button
                    size="small"
                    :type="selectedBulkAction === 'delete' ? 'danger' : 'primary'"
                    :disabled="!selectedBulkAction"
                    @click="handleBulkAction"
                >
                  <DynamicIcon v-if="selectedBulkAction === 'delete'" name="Delete" class="w-4 h-4"/>
                  <DynamicIcon v-else-if="selectedBulkAction === 'duplicate'" name="Copy" class="w-4 h-4"/>
                  {{ translate('Confirm') }}
                </fct-button>
              </div>

              <!-- Right side count -->
              <div class="bulk-right">
                {{ selectedProducts.length }} {{ translate('item(s) selected') }}
              </div>
            </div>
          </transition>

<!--          <BulkAction :selected-products="selectedProductIds" @reload="productTable.fetch()" />-->

          <ProductsLoader v-if="productTable.isLoading()" :productTable="productTable"
                          :next-page-count="productTable.nextPageCount"/>
          <div v-else class="fct-products-table-wrap">
            <ProductsTable
                ref="productTableRef"
                :product-table="productTable"
                @delete="deleteProduct"
                @duplicate="duplicateProduct"
                @selection-change="handleSelectionChange"
            />
          </div>
          <template #mobile>
            <ProductsLoaderMobile v-if="productTable.isLoading()"/>
            <ProductsTableMobile
                :product-table="productTable"
                @delete="deleteProduct"
                @duplicate="duplicateProduct"
            />
          </template>
        </TableWrapper>
      </div>
    </UserCan>


    <UserCan permission="products/create">
      <fct-dialog :append-to-body="true" v-model="isAddProductModalVisible" :title="translate('Add New Product')">
        <template v-if="isAddProductModalVisible">
          <AddProductModal/>
        </template>
      </fct-dialog>

      <ProductActionModal
          v-model="isProductActionModalVisible"
          :action="currentAction"
          :product-id="selectedProduct?.ID"
          :product-name="selectedProduct?.post_title"
          :products="isBulkAction ? selectedProducts : []"
          :product-ids="isBulkAction ? selectedProductIds : []"
          @completed="isBulkAction ? handleBulkProductsCompleted() : handleProductDuplicated()"
      />
    </UserCan>

  </div>
</template>

<style scoped>
.bulk-actions-bar {
  padding: 0 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.selected-count {
  font-size: 14px;
  font-weight: 500;
  color: #303133;
}

.bulk-actions {
  display: flex;
  gap: 8px;
}

/* Transition animations */
.slide-fade-enter-active {
  transition: all 0.3s ease-out;
}

.slide-fade-leave-active {
  transition: all 0.2s cubic-bezier(1, 0.5, 0.8, 1);
}

.slide-fade-enter-from,
.slide-fade-leave-to {
  transform: translateY(-10px);
  opacity: 0;
}
</style>
