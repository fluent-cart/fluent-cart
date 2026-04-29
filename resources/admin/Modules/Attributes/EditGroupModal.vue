<template>
	<div class="fct-attr-group-edit-modal">
		<el-form label-position='top' :data="group">
			<el-form-item :label="$t('Group Title')">
				<el-input size="large" type="text" v-model="group.title" :placeholder="$t('Group Title')"/>
				<template v-if="validationErrors['title']">
					<span class="error" v-for="(error, index) in validationErrors['title']" :key="index">{{error}}</span>
				</template>
			</el-form-item>
			<el-form-item :label="$t('Group Description')">
				<el-input size="large" :autosize="{ minRows: 4 }" type="textarea" v-model="group.description" :placeholder="$t('Group Description ...')"/>
				<template v-if="validationErrors['description']">
					<span class="error" v-for="(error, index) in validationErrors['description']" :key="index">{{error}}</span>
				</template>
			</el-form-item>
		</el-form>
		<div class="dialog-footer">
			<el-button 
				size="large" 
				:disabled="!group.title || loading"
				:loading="is_group_loading"
				@click="updateGroup()"
				type="primary"
			> 
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
	name: "EditGroupModal",
	props: ['group', 'groupId'],
	setup(props, ctx) {

		const loading = ref(false)
		const is_group_loading = ref(false)
		const validationErrors = ref({})

		const updateGroup = () => {

			loading.value = true;
  			validationErrors.value = {}
			Api.put('options/attr/group/' + props.groupId, {
				...props.group
			}).then(response => {
				handleSuccess(response.message);

			}).catch(errors => {
				if(errors.message) {
					return handleError(errors);
				}
				if (typeof errors === 'object') {
					return validationErrors.value = errors
				} 
			}).finally(() => {
				loading.value = false;
				ctx.emit('whenGroupEditIsDone', props.group);
			});
		}

		return {
			loading,
			is_group_loading,
			updateGroup,
			validationErrors
		}
	}
}
</script>

<style scoped>

</style>
