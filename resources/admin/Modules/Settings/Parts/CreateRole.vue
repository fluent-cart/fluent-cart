<template>
    <el-dialog
        :append-to-body="true"
        :visible="modelValue"
        :title="$t('Add New Role')"
    >
        <div class="fct_edit_customer_popup" v-if="formModel.isReady">
            <VueForm
                :form="formModel"
                :validation-errors="validationErrors"
                :showSubmitButton="false"
                ref="formInstance"
                @on-change="(value) => {}"
            />
            <div class="dialog-footer is-border">
                <el-button type="info" soft @click="handleCloseModal">
                {{ $t('Cancel') }}
                </el-button>
                <el-button type="primary" @click="createRole" :loading="loading">
                    {{ loading ? $t('Creating') : $t('Create Role')}}
                </el-button>
            </div>
        </div>
    </el-dialog>
  </template>
  
  <script setup>
  import {getCurrentInstance} from "vue";
  import {useSaveShortcut} from "@/mixin/saveButtonShortcutMixin";
  import formModel from "@/utils/model/form/FormModel";
  </script>
  
  <script type="text/babel">
  import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
  import VueForm from "@/Bits/Components/Form/VueForm.vue";
  import {getCurrentInstance, ref} from "vue"
  import Notify from "@/utils/Notify";
  
  export default {
    name: 'CreateRoleModal',
    props: ['createRoleModal'],
    components: {
      ValidationError,
      VueForm,
      getCurrentInstance,
      ref,
    },
    data() {
      return {
        loading: false,
        validationErrors: {},
        fields: {
            grid: {
                type: 'grid',
                schema: {
                roleTitle: {
                    label: 'Title',
                    type: 'input',
                    value: '',
                    placeholder: 'Enter Role Title',
                    inputType: 'text'
                },
                },
                columns: {
                default: 1,
                md: 2
                },
                value: ''
            },
        }
      }
    },
    mounted() {
        this.validationErrors = {};
        formModel.setSchema(this.fields).setDefaults({}).initForm();
    },
    methods: {
      handleCloseModal(){
        this.$emit('close-modal');
      },
      createRole() {
        this.loading = true;
        
        let getFormData = this.$refs.formInstance.getState();
        getFormData.roleKey = "fluent_cart_" + getFormData.roleTitle.toLowerCase().replace(/ /g, "_");

        this.$post('roles', {
            role_title: getFormData.roleTitle,
            role_key: getFormData.roleKey,
        }).then(response => {
          this.handleSuccess(response.data.message);
          this.$emit('update:refresh-role', false, getFormData);
          this.validationErrors = {};
        })
        .catch((errors) => {
          if (errors.status_code == '422') {
            Notify.validationErrors(errors);
            this.validationErrors = errors.data;
          } else {
            Notify.error(errors.data?.message);
          }
        })
        .finally(() => {
            this.loading = false;
        });
      },
      
    }
  }
  </script>
