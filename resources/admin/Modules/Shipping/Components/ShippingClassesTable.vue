<template>
  <div class="shipping-classes-table">

    <el-table
        :data="shipping_classes"
        v-loading="loading"
        class="w-full responsive"
    >
      <el-table-column prop="name" :label="translate('Class Name')">
        <template #default="scope">
          {{ scope.row.name }}
        </template>
      </el-table-column>

      <el-table-column prop="cost" :label="translate('Cost')" width="150">
        <template #default="scope">
          {{ scope.row.cost }}{{ scope.row.type === 'percentage' ? '%' : '' }}
        </template>
      </el-table-column>

      <el-table-column prop="type" :label="translate('Type')" width="150">
        <template #default="scope">
          {{ scope.row.type === 'fixed' ? translate('Fixed Amount') : translate('Percentage') }}
        </template>
      </el-table-column>

      <el-table-column prop="per_item" :label="translate('Per Item')" width="150">
        <template #default="scope">
          {{ scope.row.per_item == 1 ? translate('Yes') : translate('No') }}
        </template>
      </el-table-column>

      <el-table-column :label="translate('Actions')" width="200" align="right">
        <template #default="scope">
          <div class="fct-btn-group sm justify-end">
            <icon-button size="small"
                         @click="openEditClassDrawer(scope.row)"
                         class="cursor-pointer">
              <DynamicIcon name="Edit"/>
            </icon-button>

            <el-popconfirm
                :title="translate('Are you sure to delete this shipping class?')"
                @confirm="deleteClass(scope.row.id)"
                width="220"
            >
              <template #reference>
                <icon-button size="small" class="cursor-pointer">
                  <DynamicIcon name="Delete"/>
                </icon-button>
              </template>
            </el-popconfirm>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <!-- Use the new ShippingClassDrawer component -->
    <ShippingClassDrawer
      v-model="showClassDrawer"
      :class-data="selectedClass"
      @saved="onClassSaved"
    />
  </div>
</template>

<script setup>
import {ref} from 'vue';
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Notify from "@/utils/Notify";
import ShippingClassDrawer from './ShippingClassDrawer.vue';


const props = defineProps({
  shipping_classes: {
    type: Array,
    required: true
  },
  columns: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['refresh']);
const loading = ref(false);
const showClassDrawer = ref(false);
const selectedClass = ref(null);

const deleteClass = (id) => {
  loading.value = true;
  Rest.delete(`/shipping/classes/${id}`)
      .then(response => {
        Notify.success(response.message);
        emit('refresh');
      })
      .catch(error => {
        console.error('Error deleting shipping class:', error);
        Notify.error(translate('Failed to delete shipping class'));
      })
      .finally(() => {
        loading.value = false;
      });
};

const openEditClassDrawer = (row) => {
  selectedClass.value = row;
  showClassDrawer.value = true;
};

const onClassSaved = () => {
  emit('refresh');
};

</script>
