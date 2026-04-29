<template>
    <div class="all_licenses">
      <Heading title="All Licenses" />
      <div class="bg-white dark:bg-dark-700">
        <el-table stripe :data="licenses">
          <el-table-column label="ID" :width="100">
            <template #default="scope">
              <router-link
                :to="{
                  name: 'view_license',
                  params: { license_id: scope.row.id },
                }"
              >
                #{{ scope.row.id }}
              </router-link>
            </template>
          </el-table-column>
          <el-table-column :width="290" label="License Key">
            <template #default="scope">
              <router-link
                :to="{
                  name: 'view_license',
                  params: { license_id: scope.row.id },
                }"
              >
                {{ scope.row.license_key }}
              </router-link>
            </template>
          </el-table-column>
          <el-table-column :width="100" label="Status">
            <template #default="scope">
              <span>{{ scope.row.status }}</span>
            </template>
          </el-table-column>
          <el-table-column :min-width="300" label="Product">
            <template #default="scope">
              {{ getProductName(scope.row.product_details) }}
            </template>
          </el-table-column>
          <el-table-column :min-width="120" label="Customer">
            <template #default="scope">
              <router-link
                :to="{
                  name: 'view_customer',
                  params: { customer_id: scope.row.customer_id },
                }"
              >
                {{ scope.row?.customer.full_name || "No Customer Found" }}
              </router-link>
            </template>
          </el-table-column>
          <el-table-column :width="120" label="Activations">
            <template #default="scope">
              {{ scope.row.activation_count }} /
              {{ scope.row.limit || "Unlimited" }}
            </template>
          </el-table-column>
          <el-table-column :width="160" label="Expires">
            <template #default="scope">
              <span v-if="scope.row.expiration_date">{{
                scope.row.expiration_date
              }}</span>
              <span v-else>Lifetime</span>
            </template>
          </el-table-column>
          <el-table-column :width="160" label="Purchased">
            <template #default="scope">
              <router-link
                :to="{
                  name: 'view_order',
                  params: { order_id: scope.row.order_id },
                }"
              >
                {{ scope.row.created_at }}
              </router-link>
            </template>
          </el-table-column>
        </el-table>
  
        <div class="py-2.5 px-7.5 dark:bg-gray-800">
          <div class="flex justify-between">
            <div class="flex items-center gap-2">
              <pagination
                :pager-only="true"
                :hide_on_single="false"
                :pagination="paginate"
                @fetch="fetch"
                @current-page-changed="onCurrentPageChanged"
                @on-per-page-changed="onPerPageChanged"
              />
            </div>
            <pagination
              :hide_on_single="false"
              :pagination="paginate"
              @fetch="fetch"
              @current-page-changed="onCurrentPageChanged"
              @on-per-page-changed="onPerPageChanged"
            />
          </div>
        </div>
      </div>
    </div>
  </template>
  
  <script type="text/babel">
  import Heading from "@/Bits/Components/Layout/Heading.vue";
  import Pagination from "@/Bits/Components/Pagination.vue";
  
  export default {
    name: "AllLicenses",
    components: {
      Pagination,
      Heading,
    },
    data() {
      return {
        licenses: [],
        paginate: {
          per_page: 10,
          current_page: 1,
          total: 0,
          last_page: 1,
        },
        loading: false,
        is_loaded: false,
        search: "",
      };
    },
    methods: {
      fetch() {
        this.loading = true;
        this.$get("licensing/licenses/customer/"+1, {
          page: this.paginate.current_page,
          per_page: this.paginate.per_page,
          search: this.search,
        })
          .then((response) => {
            this.licenses = response.licenses.data;
            this.paginate.total = response.licenses.total;
            this.paginate.last_page = response.licenses.last_page;
          })
          .catch((error) => {
            this.handleError(error);
          })
          .finally(() => {
            this.loading = false;
            this.is_loaded = true;
          });
      },
      getProductName(variation) {
        if (!variation) {
          return "n/a";
        }
        return variation?.product.post_title + " - " + variation.variation_label;
      },
      onCurrentPageChanged({page, currentPageCount}) {
        this.paginate.current_page = page;
        this.fetch();
      },
      onPerPageChanged(perPage) {
        this.paginate.per_page = perPage;
        this.fetch();
      },
    },
    mounted() {
      this.fetch();
    },
  };
  </script>
  