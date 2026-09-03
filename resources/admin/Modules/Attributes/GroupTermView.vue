<script setup>
import {ref, onMounted, markRaw} from 'vue';
import {useRoute} from 'vue-router';
import translate from "@/utils/translator/Translator";
import Rest from '@/utils/http/Rest';
import Confirmation from "@/utils/Confirmation";
import Notify from "@/utils/Notify";
import useAttrTermsTable from "@/utils/table-new/AttrTermsTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import AttrTermsTableBody from "./AttrTermsTableBody.vue";
import AttrTermsTableLoader from "./AttrTermsTableLoader.vue";
import AddTermModal from "./AddTermModal.vue";
import EditTermModal from "./EditTermModal.vue";
import {ArrowRight} from '@element-plus/icons-vue';

const route = useRoute();
const groupId = route.params.group_id;
const group = ref({});
const editingTerm = ref({});
const editModalIsOpen = ref(false);
const createModalIsOpen = ref(false);

const ArrowRightIcon = markRaw(ArrowRight);

const attrTermsTable = useAttrTermsTable({fetch: false});
attrTermsTable.setGroupId(groupId);
attrTermsTable.fetch();

onMounted(() => {
  Rest.get('options/attr/group/' + groupId, {})
      .then(response => {
        group.value = response.group;
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      });
});

const handleEdit = (row) => {
  editModalIsOpen.value = true;
  editingTerm.value = row;
};

const handleDelete = (row) => {
  Confirmation.ofDelete(
      translate('Are you sure you want to delete this term? This action is not recoverable.'),
      translate('Confirm Delete!'),
      { confirmButtonText: translate('Yes, Delete') }
  ).then(() => {
    Rest.delete('options/attr/group/' + groupId + '/term/' + row.id)
        .then(response => {
          Notify.success(response.message);
          attrTermsTable.fetch();
        })
        .catch((errors) => {
          if (errors.status_code == '422') {
            Notify.validationErrors(errors);
          } else {
            Notify.error(errors.data?.message);
          }
        });
  }).catch(() => {});
};

const handleReorder = (ids) => {
  Rest.post('options/attr/group/' + groupId + '/terms/reorder', { ids })
      .then(response => {
        Notify.success(response.message);
      })
      .catch((errors) => {
        Notify.error(errors.data?.message || translate('Failed to save order'));
        attrTermsTable.fetch();
      });
};

const termCreatingIsDone = () => {
  createModalIsOpen.value = false;
  attrTermsTable.fetch();
};

const termEditingIsDone = () => {
  editingTerm.value = {};
  editModalIsOpen.value = false;
  attrTermsTable.fetch();
};
</script>

<template>
  <div class="fct-single-term-page fct-layout-width">
    <div class="single-page-header flex items-start justify-between">
      <div class="single-page-header-title-wrap">
        <el-breadcrumb :separator-icon="ArrowRightIcon">
          <el-breadcrumb-item :to="{ name: 'attributes' }">
            {{ translate('Attributes') }}
          </el-breadcrumb-item>

          <el-breadcrumb-item :to="{ name: 'attributes' }">
            {{ translate('Group') }}
          </el-breadcrumb-item>

          <el-breadcrumb-item :to="{ name: 'attributes' }">
            {{ (group.title || translate('loading...')) }}
          </el-breadcrumb-item>

          <el-breadcrumb-item>
            #{{ groupId }}
          </el-breadcrumb-item>
        </el-breadcrumb>
      </div>
      <div class="fct-btn-group sm">
        <el-button type="primary" @click="createModalIsOpen = true">
          {{ translate('Add Term') }}
        </el-button>
      </div>
    </div>

    <TableWrapper :table="attrTermsTable" :has-mobile-slot="false">
      <AttrTermsTableLoader v-if="attrTermsTable.isLoading()"/>
      <div v-else>
        <AttrTermsTableBody
            :rows="attrTermsTable.getTableData()"
            :has-more="false"
            :is-loading-more="false"
            :group="group"
            @edit="handleEdit"
            @delete="handleDelete"
            @reorder="handleReorder"
            @add="createModalIsOpen = true"
        />
      </div>
    </TableWrapper>

    <el-dialog :append-to-body="true" width="40%" v-model="createModalIsOpen" :title="translate('Add new attribute term')">
      <template v-if="createModalIsOpen">
        <AddTermModal :group-id="groupId" :group="group" @is-term-creating-done="termCreatingIsDone"/>
      </template>
    </el-dialog>

    <el-dialog :append-to-body="true" width="40%" v-model="editModalIsOpen" :title="translate('Edit the term')">
      <template v-if="editModalIsOpen">
        <EditTermModal :group-id="groupId" :group="group" :editing="editingTerm" @is-term-editing-done="termEditingIsDone"/>
      </template>
    </el-dialog>
  </div>
</template>
