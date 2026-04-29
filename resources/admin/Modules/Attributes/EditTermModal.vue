<template>
	<div class="fct-edit-term-modal">
		<el-form label-position='top' :data="editing">
			<el-form-item :label="$t('Title')">
				<el-input size="large" type="text" v-model="editing.title" :placeholder="$t('Unique title...')"/>
			</el-form-item>

			<el-form-item :label="$t('Slug')">
				<el-input size="large" type="text" disabled v-model="editing.slug" :placeholder="$t('Slug')"/>
			</el-form-item>

			<el-form-item :label="$t('Description')">
				<el-input size="large" type="textarea" v-model="editing.description" :placeholder="$t('Description ...')"/>
			</el-form-item>

			<el-form-item :label="$t('Serial')">
				<el-input size="large" type="number" v-model="editing.serial" :placeholder="$t('Term serial...')"/>
			</el-form-item>
		</el-form>

		<div class="dialog-footer">
			<el-button size="large" :disabled="!editing.title || loading"
				:loading="loading"
				@click="updateTerm()"
				type="primary"> 
				{{ $t('Save changes')}}
			</el-button>
		</div>
	</div>
</template>

<script>
import {ref} from "vue";
import Api from "@/utils/http/Rest";
import {handleSuccess, handleError} from "@/Bits/common";

export default {
	name: "EditTermModal",
	props: ['groupId', 'editing'],
	setup(props, ctx) {

		const loading = ref(false);

		const updateTerm = () => {

			loading.value = true;
			props.editing.title.trim();

			Api.post('options/attr/group/' + props.groupId + '/term/'+ props.editing.id, {
				...props.editing
			}).then(response => {
				handleSuccess(response.message);
			}).catch(errors => {
				handleError(errors);

			}).finally(() => {
				loading.value = false;
				ctx.emit('isTermEditingDone', props.editing);
			});
		}

		return {
			loading,
			updateTerm,
		}
	},
	mounted() {
		//this.term = this.theTerm
	}
}
</script>

<style scoped>

</style>
