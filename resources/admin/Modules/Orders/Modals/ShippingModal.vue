<template>
	<div class="fct-shipping-modal">
		<el-form label-position='top' require-asterisk-position="right">
			<el-row :gutter="20">
				<el-col :md="24" :sm="24" :xs="24">
					<el-form-item :label="$t('')" required>
						<el-radio-group v-model="shippingType">
							<el-radio v-for="(item, itemIndex) in availableOptions" :label="item.value" :key="itemIndex">
								{{ item.label }}
							</el-radio>
						</el-radio-group>
					</el-form-item>
				</el-col>
			</el-row>
			<el-row v-if="shippingType === 'custom'" :gutter="15">
				<el-col :md="12" :sm="12" :xs="24">
					<el-form-item :label="$t('Name')">
						<el-input type="text" v-model="shipping.rate_name"/>
					</el-form-item>
				</el-col>
				<el-col :md="12" :sm="12" :xs="24">
					<el-form-item :label="$t('Price')">
						<el-input type="number" :min="1" v-model.number="shipping.custom_price" @keyup="isDisabledApplyButton">
							<template #prefix>
								<span v-html="appVars.shop.currency_sign"></span>
							</template>
						</el-input>
					</el-form-item>
				</el-col>

			</el-row>
		</el-form>
		<div class="dialog-footer">
			<el-button type="danger" v-if="shipping.type" @click="removeShipping">
				{{ $t('Remove Shipping')}}
			</el-button>
			<el-button type="primary" @click="updatingIsDone" :disabled="isDisabledApplyButton()">
				{{ $t('Apply')}}
			</el-button>
		</div>
	</div>
</template>

<script>
import {ref} from "vue";

export default {
	name: "ShippingModal",
	props: ['shipping', 'shipping_type'],
	setup(props, {emit}) {
		const shippingType = ref(props.shipping_type);

		const availableOptions = ref([
			// {
			// 	label: 'Free shipping',
			// 	value: 'free'
			// },
			{
				label: 'Custom',
				value: 'custom'
			}
		]);

		const isDisabledApplyButton = () => {
			const parsePrice = parseInt(props.shipping.custom_price);
			return isNaN(parsePrice) || parsePrice < 0
		};

		const updatingIsDone = () => {
			if(parseInt(props.shipping.custom_price) < 0) {
				return props.shipping.custom_price = 0;
			}
			if(parseInt(props.shipping.custom_price) == 0) {
				props.shipping.custom_price = 0;
			}
			props.shipping.rate_name = (props.shipping.rate_name == '' || props.shipping.rate_name == undefined) ? 'Custom' : props.shipping.rate_name;
			props.shipping.type = shippingType.value;
			return emit('whenShippingEditIsDone', false);
		}

		const removeShipping = () => {
			props.shipping.type = '';
			props.shipping.custom_price = '';
			props.shipping.rate_name = '';
			emit('whenShippingEditIsDone', false);
		}

		return {
			availableOptions,
			updatingIsDone,
			removeShipping,
			isDisabledApplyButton,
			shippingType,
		}
	}
}
</script>

<style scoped>

</style>



