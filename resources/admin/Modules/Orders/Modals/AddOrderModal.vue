<template>
    <el-dialog v-model="showModal" :title="$t('Add new order')" width="30%" class="add-order-popover">
        <el-form label-position='top' :data="order">
            <el-form-item :label="$t('Order Status')">
                <el-radio-group v-model="order.status">
                    <el-radio label="draft">{{$t('Draft')}}</el-radio>
                    <el-radio label="publish">{{$t('Publish')}}</el-radio>
                </el-radio-group>
            </el-form-item>
        </el-form>
        <div class="dialog-footer">
            <el-button
                v-loading="creating_order"
                @click="createOrder()"
                type="primary">
                {{$t('Create Order')}}
            </el-button>
        </div>
    </el-dialog>
</template>

<script type="text/babel">
import Notify from "@/utils/Notify";

export default {
    name: 'AddOrderModal',
    props: ['createOrderModel'],
    data() {
        return {
            order: {
                status: 'draft' 
            },
            creating_order: false
        }
    },
    computed: {
        showModal: {
            get() {
                return this.createOrderModel;
            }, 
            set (val) {
                this.$emit('update:createOrderModel', val)
            }
        }
    },
    methods: {
        createOrder() {
            this.creating_order = true;
            this.$post('orders', {
                order: this.order
            })
                .then(response => {
                    this.creating_order = false;
                    this.$router.push({name: 'view_order', params: {order_id: response.data.order.id}});
                })
                .catch((errors) => {
                  if (errors.status_code == '422') {
                    Notify.validationErrors(errors);
                  } else {
                    Notify.error(errors.data?.message);
                  }
                })
                .finally(() => {
                    this.creating_order = false;
                });
        }
    }
}
</script>
