<script setup>
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import Image from "@/utils/support/Image";
import Arr from "@/utils/support/Arr";
import useProductTable from "@/utils/table-new/ProductTable";
import {formatNumber} from "@/Bits/productService";
import translate from "@/utils/translator/Translator";
import AddProductModal from "@/Modules/Products/AddProductModal.vue";
import {getCurrentInstance, onMounted, onUnmounted, ref} from "vue";
import CreateDummyProduct from "@/Modules/Products/parts/CreateDummyProduct.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import ProductsLoader from "./parts/ProductsLoader.vue";
import ProductsLoaderMobile from "./parts/ProductsLoaderMobile.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ProductsTable from "@/Modules/Products/parts/ProductsTable.vue";
import ProductsTableMobile from "@/Modules/Products/parts/ProductsTableMobile.vue";


const productTable = useProductTable({
  instance: getCurrentInstance()
});

const isAddProductModalVisible = ref(false);

const isMobileView = ref(false);

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768; // You can adjust this breakpoint
};
const getImage = (detail, dark = false) => {
  return Arr.get(detail, 'featured_media.url') ??
      Image.emptyImage(dark);
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

const getAvailableCount = (product) => {
  if (Array.isArray(product.variants)) {

  }
  return 100;
}


onMounted(() => {
  checkMobileView(); // Initial check
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
          <CreateDummyProduct @onProductCreated="productTable.fetch()" :products="productTable.getTableData()"/>

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
          <ProductsLoader v-if="productTable.isLoading()" :productTable="productTable"
                          :next-page-count="productTable.nextPageCount"/>
          <div v-else>
            <ProductsTable :product-table="productTable" @delete="deleteProduct"/>
          </div>
          <template #mobile>
            <ProductsLoaderMobile v-if="productTable.isLoading()"/>
            <ProductsTableMobile :product-table="productTable" @delete="deleteProduct"/>
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
    </UserCan>

  </div>
</template>
