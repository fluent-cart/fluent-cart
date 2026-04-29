<template>
	<div class="fct-add-term-modal">
		<el-form label-position='top' :data="term">
			<el-form-item :label="$t('Title')">
				<el-input size="large" type="text" v-model="term.title" :placeholder="$t('Unique title')" @blur="titleIsBlurred" />
			</el-form-item>

			<el-form-item :label="$t('Slug')">
				<el-input size="large" type="text" v-model="term.slug" :placeholder="$t('Unique slug....')"/>
			</el-form-item>

			<el-form-item :label="$t('Description')">
				<el-input size="large" type="textarea" v-model="term.description" :placeholder="$t('Description ...')"/>
			</el-form-item>

			<el-form-item :label="$t('Serial')">
				<el-input size="large" type="number" v-model="term.serial" :placeholder="$t('Term serial...')"/>
			</el-form-item>
		</el-form>
		<div class="dialog-footer">
			<el-button size="large" :disabled="!term.title || loading"
					:loading="loading"
					@click="createTerm()"
					type="primary">
				{{ $t('Add term')}}
			</el-button>
		</div>
	</div>
</template>

<script type="text/babel">

import {reactive, ref} from "vue";
import Api from '@/utils/http/Rest'
import {handleSuccess, handleError} from "@/Bits/common";

export default {
	name: 'AddTermModal',
	props: ['groupId', 'group'],
	setup(props, ctx) {

		const loading = ref(false);

		const term = reactive({
			title: '',
			slug: '',
			serial: 10,
			description: '',
			settings: '',
			group_id: props.groupId,
		});

		const createTerm = () => {

			loading.value = true;

			Api.post('options/attr/group/' + props.groupId + '/term', {
				...term
			}).then(response => {
				handleSuccess(response.message);

			}).catch(errors => {
				handleError(errors);

			}).finally(() => {
				loading.value = false;
				ctx.emit('isTermCreatingDone');
			});
		}

		const titleIsBlurred = () => {
			if(!term.slug && term.title) {
				term.slug = makeASlug(term.title);
			}
		}

		const makeASlug = ($str) => {
			let str = $str.toLowerCase();
			str = str.trim();
			str = str.replace(/ /g, '-');
			str = str.replace(/&/g, 'and');
			str = str.replace(/'/g, '_');

			return str;
		}

		return {
			term,
			loading,
			createTerm,
			titleIsBlurred
		}
	}
}
</script>
