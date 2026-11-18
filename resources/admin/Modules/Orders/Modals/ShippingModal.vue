<template>
	<div class="fct-shipping-modal">
		<div>
			<div class="mb-[18px]">
				<fct-choice type="radio" v-model="shippingType" :options="availableOptions"/>
			</div>
			<div v-if="shippingType === 'custom'" class="grid grid-cols-1 md:grid-cols-2 gap-x-[15px]">
				<fct-input :label="$t('Name')" type="text" v-model="shipping.rate_name"/>
				<fct-input :label="$t('Price')" type="number" :controls="false" :min="1" v-model.number="shipping.custom_price" @keyup="isDisabledApplyButton">
					<template #prefix>
						<span v-html="appVars.shop.currency_sign"></span>
					</template>
				</fct-input>
			</div>
		</div>
		<div class="dialog-footer">
			<fct-button type="danger" v-if="shipping.type" @click="removeShipping">
				{{ $t('Remove Shipping')}}
			</fct-button>
			<fct-button type="primary" @click="updatingIsDone" :disabled="isDisabledApplyButton()">
				{{ $t('Apply')}}
			</fct-button>
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



