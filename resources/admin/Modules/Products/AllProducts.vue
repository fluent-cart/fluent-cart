<script setup>
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import Image from "@/utils/support/Image";
import Arr from "@/utils/support/Arr";
import useProductTable from "@/utils/table-new/ProductTable";
import {formatNumber} from "@/Bits/productService";
import translate from "@/utils/translator/Translator";
import AddProductModal from "@/Modules/Products/AddProductModal.vue";
import {computed, getCurrentInstance, onMounted, onUnmounted, ref} from "vue";
import CreateDummyProduct from "@/Modules/Products/parts/CreateDummyProduct.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
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

const getImage = (detail, dark = false) => {
  return Arr.get(detail, 'featured_media.url') ?? Image.emptyImage(dark);
}

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
        <UserCan permission="products/create">
          <el-dropdown
              trigger="click"
              popper-class="fct-dropdown"
              @command=""
              placement="bottom-end"
          >
            <el-button>
              {{ translate('More actions') }}
              <el-icon>
                <ArrowDown/>
              </el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item class="item-link">
                  <CreateDummyProduct @onProductCreated="productTable.fetch()" :products="productTable.getTableData()"/>
                </el-dropdown-item>

                <el-dropdown-item @click="$router.push({ name: 'product_bulk_insert' })">
                  <DynamicIcon name="Insert" />
                  {{ translate('Bulk Product Insert') }}
                </el-dropdown-item>

                <el-dropdown-item @click="$router.push({ name: 'product_bulk_edit' })">
                  <DynamicIcon name="Edit" />
                  {{ translate('Bulk Product Edit') }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>



          <el-button type="primary" @click="()=>{
            isAddProductModalVisible = true;
          }">
            {{ translate('Add Product') }}
          </el-button>
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
                <el-select
                    size="small"
                    class="bulk-select min-w-[180px]"
                    :placeholder="translate('Select Bulk Action')"
                    v-model="selectedBulkAction"
                >
                  <el-option
                      :label="translate('Duplicate')"
                      value="duplicate"
                  />
                  <el-option
                      :label="translate('Delete')"
                      value="delete"
                  />
                </el-select>

                <el-button
                    size="small"
                    :type="selectedBulkAction === 'delete' ? 'danger' : 'primary'"
                    @click="handleBulkAction"
                >
                  <DynamicIcon v-if="selectedBulkAction === 'delete'" name="Delete" class="w-4 h-4"/>
                  <DynamicIcon v-else name="Copy" class="w-4 h-4"/>
                  {{ translate('Confirm') }}
                </el-button>
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
          <div v-else>
            <ProductsTable
                ref="productTableRef"
                :product-table="productTable"
                @delete="deleteProduct"
                @duplicate="duplicateProduct"
                @selectionChange="handleSelectionChange"
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
      <el-dialog :append-to-body="true" v-model="isAddProductModalVisible" :title="translate('Add New Product')">
        <template v-if="isAddProductModalVisible">
          <AddProductModal/>
        </template>
      </el-dialog>

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
  //background-color: #f5f7fa;
  //border: 1px solid #e4e7ed;
  //border-radius: 4px;
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
