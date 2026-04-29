<template>
  <div class="fct-attr-group-modal">
    <el-form label-position='top' :data="group">
      <el-row :gutter="24">
        <el-col :span="12">
          <el-form-item :label="$t('Group Title')" required>
              <el-input type="text" v-model="group.title" :placeholder="$t('Group Title')" @blur="handleSlug" size="large"/>
              <template v-if="validationErrors['title']">
                <span class="error" v-for="(error, index) in validationErrors['title']" :key="index">{{error}}</span>
              </template>
            </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item :label="$t('Group Slug')" required>
              <el-input type="text" v-model="group.slug" :placeholder="$t('Unique Slug')" size="large"/>
              <template v-if="validationErrors['slug']">
                <span class="error" v-for="(error, index) in validationErrors['slug']" :key="index">{{error}}</span>
              </template>
            </el-form-item>
        </el-col>
        <el-col :span="24">
          <el-form-item :label="$t('Group Description')">
            <el-input type="textarea" v-model="group.description" :placeholder="$t('Group Description...')" size="large"  :autosize="{ minRows: 8 }"/>
            <template v-if="validationErrors['description']">
              <span class="error" v-for="(error, index) in validationErrors['description']" :key="index">{{error}}</span>
            </template>
          </el-form-item>
        </el-col>
      </el-row>

      <div class="dialog-footer">
        <el-button 
          :disabled="loading"
          :loading="loading"
          @click="createGroup()"
          type="primary"
          size="large"
        >
          Add Group
        </el-button>
      </div>
    </el-form>
  </div>
</template>

<script setup>
import {reactive, ref, defineModel} from "vue";
import Api from "@/utils/http/Rest";
import {handleSuccess, handleError} from "@/Bits/common";


defineOptions({
  name: 'AddGroupModal'
})

// const count = defineModel<Number>('count')

const emit = defineEmits('whenGroupCreateIsDone')

const loading = ref(false);

const validationErrors = ref({});

const group = reactive({
  title: '',
  slug: '',
  description: '',
  settings: ''
});

const createGroup = () => {
  loading.value = true;
  validationErrors.value = {}

  Api.post('options/attr/group/', {
    ...group
  }).then(response => {
    handleSuccess(response.message);
    emit('whenGroupCreateIsDone');
  }).catch(errors => {
    console.log(errors)
    if(errors.message) {
      return handleError(errors);
    }
    if (typeof errors === 'object') {
      return validationErrors.value = errors
    } 
  }).finally(() => {
    loading.value = false;

  });
}



const handleSlug = () => {
  if (!group.slug && group.title) {
    group.slug = slugify(group.title);
  }
}

const slugify = (str) => str.trim()
    .toLowerCase()
    .replace(/ /g, '-')
    .replace(/&/g, 'and')
    .replace(/'/g, '_')

</script>
