<template>
  <div class="fct_layout">
    <card>
      <card-header title="All Attributes Groups" border_bottom class="!mb-0">
        <template #action>
          <el-button @click="createGroupModal = !createGroupModal" type="primary">
            <el-icon class="el-icon--left">
              <Plus/>
            </el-icon>
            {{ $t('Add Group')}}
          </el-button>
        </template>
      </card-header>
      <card-body class="p-0">
        <div class="fct-attr-groups-content">
          <TableFilterWrapper :performing-bulk-action="groupSelection.length > 0">
            <template v-slot:status>

              <!--            <ul class="flex m-0 gap-3.5">-->
              <!--              <li @click="changeStatus('')"-->
              <!--                  class="cursor-pointer m-0 text-center text-xs leading-5  box-border flex justify-center items-center py.5 px-2.5 rounded-xl border border-solid border-transparent"-->
              <!--                  :class="[-->
              <!--                      {'bg-transparent font-medium': status },-->
              <!--                      {'bg-primary-50 text-primary-900 font-bold': !status }-->
              <!--                  ]">All</li>-->
              <!--              <li v-for="(statusName, statusSlug) in appVars.product_statuses"-->
              <!--                  @click="changeStatus(statusSlug)"-->
              <!--                  class="cursor-pointer m-0 text-center text-xs leading-5 box-border flex justify-center items-center py.5 px-2.5 rounded-xl border border-solid border-transparent"-->

              <!--                  :class="[-->
              <!--                      {'bg-transparent font-medium': !(status === statusSlug) },-->
              <!--                      {'bg-primary-50 text-primary-900 font-bold': (status === statusSlug) }-->
              <!--                  ]"-->

              <!--                  :key="statusSlug">-->
              <!--                {{ statusName }}-->
              <!--              </li>-->
              <!--            </ul>-->


            </template>

            <template v-slot:search>
              <div class="search-bar">
                <el-input @clear="fetchAttributes()" @keyup.enter="fetchAttributes()" v-model="search" :prefix-icon="SearchIcon" type="text" :placeholder="$t('Search by id, title, slug')" clearable/>
              </div>
            </template>

            <template v-slot:filters>
              <group-filters
                :class="{'pointer-events-none':loading}"
                :filterOptions="filterOptions"
                @fetch="fetchAttributes"
                :filters="filters"
                :sorting="sorting"
                @filtersUpdated="filtersUpdated"
                v-model:columns="columns"/>
            </template>

            <template v-slot:bulk-actions>
              <AttrGroupBulkAction
                @reload="fetchAttributes"
                @refetch="fetchAttributes()"
                :selections="groupSelection"
                :groups="groups"
              />
            </template>

            <template v-slot:filterIndicator>
              <filter-indicator @remove-all-filter="removeAllFilter()" v-if="Object.keys(this.filters).length !== 0">
                <template #tags>
                  <ul class="fct-search-tags">
                    <li v-for="(filter, filterName) in filters" :key="filterName">
                      <span class="tag-title"> {{ getFilterLabel(filterName, filter) }} </span>: 
                      {{ getFilterValues(filterName, filter) }}
                      <span @click="removeFilter(filterName)" class="tag-action" title="Remove filters">
                        <DynamicIcon name="Close"/>
                      </span>
                    </li>
                  </ul>
                </template>
              </filter-indicator>
            </template>
          </TableFilterWrapper>

          <el-skeleton class="px-6 pt-4" :loading="loading" :rows="5" animated>
            <el-table :data="groups" @selection-change="onSelection">
              <el-table-column type="selection" :width="45"/>

              <el-table-column :width="50" :label="$t('ID')">
                <template #default="scope">
                  <router-link class="link" :to="{ name: 'attr_view_group', params: { group_id: scope.row.id } }">
                    #{{ scope.row.id }}
                  </router-link>
                </template>
              </el-table-column>

              <el-table-column v-if="columns.indexOf('title') != -1" :min-width="150" :label="$t('Title')">
                <template #default="scope">
                  {{ scope.row.title || $t('No Name') }}
                </template>
              </el-table-column>

              <el-table-column v-if="columns.indexOf('slug') != -1" :min-width="200" :label="$t('Slug')">
                <template #default="scope">
                  {{ scope.row.slug }}
                </template>
              </el-table-column>

              <el-table-column v-if="columns.indexOf('description') != -1" :min-width="120" :label="$t('Description')">
                <template #default="scope">
                  {{ scope.row.description }}
                </template>
              </el-table-column>

              <el-table-column v-if="columns.indexOf('term_count') != -1" :min-width="100" :label="$t('Total terms')">
                <template #default="scope">
                  {{ (scope.row.terms_count ? scope.row.terms_count : 0) }}
                </template>
              </el-table-column>

              <el-table-column v-if="columns.indexOf('created_at') !== -1" :min-width="100" :label="$t('Date')">
                <template #default="scope">
                  <ConvertedTime :date-time="scope.row.created_at"/>
                </template>
              </el-table-column>

              <el-table-column :min-width="100" label="Actions" header-align="right" v-if="groupSelection.length<2">
                <template #default="scope">
                  <div class="fct-btn-group sm">
                    <el-tooltip
                      effect="dark"
                      content="Edit"
                      placement="top"
                      popper-class="fct-tooltip"
                    >
                      <icon-button border="success" @click="handleEdit(scope.$index, scope.row)">
                        <Edit/>
                      </icon-button>
                    </el-tooltip>

                    <el-tooltip
                      effect="dark"
                      content="Delete"
                      placement="top"
                      popper-class="fct-tooltip"
                    >
                      <icon-button border="danger" @click="handleDelete(scope.$index, scope.row)">
                        <Delete/>
                      </icon-button>
                    </el-tooltip>

                    <el-tooltip
                      effect="dark"
                      content="Setting"
                      placement="top"
                      popper-class="fct-tooltip"
                    >
                      <icon-button border="primary" @click="handleSettings(scope.$index, scope.row)">
                        <Setting/>
                      </icon-button>
                    </el-tooltip>
                  </div>
                </template>
              </el-table-column>

            </el-table>
          </el-skeleton>
        </div>

        <div class="pagination-wrap">
          <pagination
              :pager-only="true"
              :hide_on_single="false"
              :pagination="paginate"
              @fetch="fetchAttributes"
              @current-page-changed="onCurrentPageChanged"
              @on-per-page-changed="onPerPageChanged"
          />
          <pagination
              :hide_on_single="false"
              :pagination="paginate"
              @fetch="fetchAttributes"
              @current-page-changed="onCurrentPageChanged"
              @on-per-page-changed="onPerPageChanged"
          />
        </div>
      </card-body>
    </card>

    <el-dialog :append-to-body="true" width="50%" v-model="createGroupModal" :title="$t('Add new attribute group')">
      <template v-if="createGroupModal">
        <add-group-modal @whenGroupCreateIsDone="groupCreatingDone"/>
      </template>
    </el-dialog>

    <el-dialog :append-to-body="true" width="50%" v-model="editGroupModal" :title="$t('Edit attribute group')">
      <template v-if="editGroupModal">
        <edit-group-modal :group="editGroup" :group-id="editGroup.id" @whenGroupEditIsDone="groupEditingDome"/>
      </template>
    </el-dialog>
  </div>
</template>


<script type="text/babel">
import Pagination from '@/Bits/Components/Pagination.vue';
import {markRaw} from 'vue';

import AttrGroupBulkAction from "./AttrGroupBulkAction.vue";
import EditGroupModal from "./EditGroupModal.vue";
import AddGroupModal from "./AddGroupModal.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {Edit, Delete, Setting, Plus, Search} from '@element-plus/icons-vue';
import Card from '@/Bits/Components/Card/Card.vue';
import CardHeader from '@/Bits/Components/Card/CardHeader.vue';
import CardBody from '@/Bits/Components/Card/CardBody.vue';
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Notify from "@/utils/Notify";

export default {
  name: 'AttrGroups',
  components: {
    ConvertedTime,
    DynamicIcon,
    IconButton,
    AddGroupModal,
    EditGroupModal,
    AttrGroupBulkAction,

    Pagination,
    Edit,
    Setting,
    Delete,
    Card,
    CardHeader,
    CardBody,
    Plus
  },
  data() {
    return {
      SearchIcon: markRaw(Search),
      loading: false,
      createGroupModal: false,
      editGroupModal: false,
      editGroup: {},
      editGroupId: 0,
      groups: [],
      groupSelection: [],
      filters: {},
      paginate: {
        current_page: 1,
        per_page: 10,
        last_page: 1,
        total: 0
      },
      search: '',
      columns: [],
      sorting: {
        sortBy: 'id',
        sortType: 'DESC'
      },
      isLoaded: false,
      filterOptions: {
        title: {
          label: 'Title',
          type: 'text'
        },
        slug: {
          label: 'Slug',
          type: 'text'
        },
        terms_count: {
          label: 'Terms Count',
          type: 'number'
        },
      },
      iconSize: 20
    }
  },
  methods: {

    onCurrentPageChanged({page, currentPageCount}) {
      this.paginate.current_page = page;
      this.fetchAttributes()
    },

    onPerPageChanged(perPage) {
      this.paginate.per_page = perPage;
      this.fetchAttributes()
    },

    fetchAttributes() {
      this.loading = true;
      this.groupSelection = [];

      let searchAttributes = (this.search) ? {
				"title" : { column: "title", operator: "rlike", value: this.search.trim() },
        "slug" : { column: "slug", operator: "or_like_all", value: this.search.trim() },
				"id":{ column: "id", operator: "or_like_all", value: this.search.trim()},
			} : null;

      let queryParams = {
        with: ['terms', 'usedTerms'],
        search: searchAttributes,
				per_page : this.paginate.per_page,
				page: this.paginate.current_page,
				filters: this.filters,
        order_by: this.sorting.sortBy,
        order_type: this.sorting.sortType
			};

      this.$get('options/attr/groups', {
        params: queryParams
      })
        .then(response => {
          this.groups = response.groups.data;
          this.paginate.total = response.groups.total;
          this.paginate.last_page = response.groups.last_page;
          if (!this.isLoaded) {
            this.isLoaded = true;
          }
        })
        .catch((errors) => {
          if (errors.status_code == '422') {
            Notify.validationErrors(errors);
          } else {
            Notify.error(errors.data?.message);
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
    onSelection(orders) {
      this.selection = !!orders.length;

      this.groupSelection = orders;

      this.selectionCount = orders.length;
    },
    filtersUpdated(filterValues) {
      this.filters = filterValues;
      this.fetchAttributes();
    },
    getFilterLabel(filterName, filterItem) {
      const label = this.filterOptions[filterName]?.label || filterName;
      if (filterItem.operator == 'IN') {
        return label + ':';
      }
      return label + ' ' + filterItem.operator;
    },
    getFilterValues(filterName, filterItem) {
      if (Array.isArray(filterItem.value)) {
        return filterItem.value.map(item => {
          return this.filterOptions[filterName]?.options[item] || item;
        }).join(', ');
      }
      return filterItem.value;
    },
    removeFilter(filterName) {
      delete this.filters[filterName];
      this.fetchAttributes();
    },
    removeAllFilter() {
      this.filters = {};
      this.fetchAttributes();
    },
    handleEdit(idx, row) {
      this.createGroupModal = false;
      this.editGroup = row;
      this.editGroupId = row.id;
      this.editGroupModal = true;
    },

    handleDelete(idx, row) {

      if (row.used_terms.length) {

        this.handleError('Group\'s terms are already in use, please remove the terms from product first.');

        return;
      }

      this.$confirm('Are you sure want to delete this group? This action is not recoverable.', 'Confirm Delete!',
          {
            confirmButtonText: 'Yes, Delete this group',
            cancelButtonText: 'Cancel',
            confirmButtonClass: 'el-button--primary',
            type: 'warning'
          }
      )
          .then(() => {

            this.loading = true;
            this.groupSelection = [];

            this.$del('options/attr/group/' + row.id, {})
                .then(response => {
                  this.handleSuccess(response);
                  this.groups.splice(idx, 1);
                })
                .catch((errors) => {
                  if (errors.status_code == '422') {
                    Notify.validationErrors(errors);
                  } else {
                    Notify.error(errors.data?.message);
                  }
                })
                .finally(() => {
                  this.loading = false;
                });

          })
          .catch(() => {
            // cancel response....
          });
    },

    handleSettings(idx, row) {
      this.$notify.error({
        title: 'Error',
        message: 'Setting will implement later....',
        offset: 16
      })
    },

    groupEditingDome() {
      this.editGroup = {};
      this.editGroupId = 0;
      this.editGroupModal = false;
    },
    groupCreatingDone(group) {
      this.editGroupModal = false;
      this.createGroupModal = false;
      this.fetchAttributes();
    },
  },
  mounted() {
    const customerColumns = this.Storage().get('attr_group_columns', false);

    if (customerColumns && Array.isArray(customerColumns)) {
      this.columns = customerColumns;
    } else {
      this.columns = ['title', 'description', 'term_count', 'created_at',];
      this.Storage().set('attr_group_columns', this.columns);
    }
    this.fetchAttributes();
  }
}
</script>

<style scoped>

.cell .el-icon {
  margin-right: 5px;
  cursor: pointer;
}

</style>
