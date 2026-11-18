<template>
    <fct-dialog v-model="showModal" :title="$t('Add new order')" width="30%" class="add-order-popover">
        <div>
            <fct-choice
                type="radio"
                v-model="order.status"
                :label="$t('Order Status')"
                :options="[{value: 'draft', label: $t('Draft')}, {value: 'publish', label: $t('Publish')}]"
            />
        </div>
        <div class="dialog-footer">
            <fct-button
                v-loading="creating_order"
                @click="createOrder()"
                type="primary">
                {{$t('Create Order')}}
            </fct-button>
        </div>
    </fct-dialog>
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
