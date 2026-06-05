<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import Checkbox from '@/components/Checkbox/index.vue'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'
import { TFormData } from '@/pages/Receipts/Ecpay/Shared/types'

const gatewayId = 'ecpay_receipt'
const isLocal = env?.IS_LOCAL ?? false

const { isPending, data } = useQuery({
	queryKey: ['settings', gatewayId],
	queryFn: async () =>
		await apiClient.get<{
			code: string
			message: string
			data: TFormData
		}>(`settings/${gatewayId}`),
	select: (res) => res.data?.data,
})

// Element Plus 表單 ref
const formRef = ref()

// 表單資料（對齊 EcpayReceiptSettingsDTO）
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',

	// --- API --- //
	mode: 'prod',
	merchant_id: '',
	hash_key: '',
	hash_iv: '',

	// --- 收據設定 --- //
	default_receipt_type: 1,
	retrieval_method: '2',
	donor_type: '1',
	identifier: '',
	payment_method: '1',

	// --- 自動化 --- //
	auto_issue_order_statuses: [],
	auto_cancel_order_statuses: ['wc-refunded'],
})

watch(
	data,
	(newData) => {
		if (newData) {
			// 深層合併，只合併 form 存在的屬性
			const filteredData = pick(newData, Object.keys(form))
			if (!isLocal) {
				filteredData.mode = 'prod'
			}
			merge(form, filteredData)
		}
	},
	{ immediate: true }
)

const isTestMode = computed(() => form.mode === 'test')

// 是否為捐贈類收據（公益 2 / 政治 4），需顯示捐贈相關欄位
const isDonation = computed(
	() => form.default_receipt_type === 2 || form.default_receipt_type === 4
)

// 是否為政治獻金（4），需顯示付款方式
const isPolitical = computed(() => form.default_receipt_type === 4)

const onSubmit = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (valid) {
			save(toRaw(form)) // 呼叫 mutation
		}
	})
}

const queryClient = useQueryClient()

// 定義 mutation
const { mutate: save, isPending: isSavePending } = useMutation({
	mutationFn: async (payload: TFormData) =>
		await apiClient.post(`/settings/${gatewayId}`, payload),
	onSuccess: () => {
		queryClient.invalidateQueries({ queryKey: ['settings', gatewayId] })
	},
	onError: (err) => {
		console.error('更新失敗', err)
	},
})

const rules = reactive<FormRules<TFormData>>({
	merchant_id: [{ required: true, message: '此欄位為必填' }],
	hash_key: [{ required: true, message: '此欄位為必填' }],
	hash_iv: [{ required: true, message: '此欄位為必填' }],
})
</script>

<template>
	<div
		class="flex items-center gap-x-2 mb-4 cursor-pointer"
		@click="$router.push('/receipts')"
	>
		<el-icon>
			<Back />
		</el-icon>
		回《電子收據》
	</div>

	<el-form
		ref="formRef"
		v-loading="isPending"
		element-loading-background="rgba(255, 255, 255, 0)"
		:model="form"
		label-position="right"
		label-width="auto"
		:class="{
			'opacity-25': isPending,
		}"
		:rules="rules"
		style="max-width: 40rem"
	>
		<el-divider>基本設定</el-divider>

		<el-form-item prop="title" label="顯示名稱">
			<el-input v-model="form.title" clearable />
		</el-form-item>
		<el-form-item prop="description" label="描述">
			<el-input v-model="form.description" clearable />
		</el-form-item>

		<el-divider>收據設定</el-divider>

		<el-form-item prop="default_receipt_type">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>預設收據類型</span>
					<el-tooltip
						content="公益 / 政治獻金收據需另向綠界申請開通權限，且使用不同特店編號"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-radio-group v-model="form.default_receipt_type">
				<el-radio :value="1">一般收據</el-radio>
				<el-radio :value="2">公益收據</el-radio>
				<el-radio :value="4">政治獻金</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-form-item prop="retrieval_method" label="領用方式">
			<el-radio-group v-model="form.retrieval_method">
				<el-radio value="2">電子（Email）</el-radio>
				<el-radio value="1">紙本</el-radio>
				<el-radio value="3">自行處理</el-radio>
			</el-radio-group>
		</el-form-item>

		<template v-if="isDonation">
			<el-form-item prop="donor_type" label="捐贈人類型">
				<el-select v-model="form.donor_type" style="width: 12rem">
					<el-option label="自然人" value="1" />
					<el-option label="公司法人" value="2" />
					<el-option v-if="isPolitical" label="人民團體" value="3" />
					<el-option v-if="isPolitical" label="政黨" value="4" />
					<el-option v-if="isPolitical" label="匿名" value="5" />
				</el-select>
			</el-form-item>

			<el-form-item prop="identifier">
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span>身分識別碼</span>
						<el-tooltip
							content="依捐贈人類型：自然人帶證號、公司法人帶統編、政黨帶登記字號"
							placement="top"
						>
							<el-icon><InfoFilled /></el-icon>
						</el-tooltip>
					</span>
				</template>
				<TrimmedInput v-model="form.identifier" clearable />
			</el-form-item>
		</template>

		<el-form-item v-if="isPolitical" prop="payment_method" label="付款方式">
			<el-radio-group v-model="form.payment_method">
				<el-radio value="1">匯款</el-radio>
				<el-radio value="2">票據</el-radio>
				<el-radio value="3">現金</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-form-item prop="auto_issue_order_statuses">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>自動開立收據的訂單狀態</span>
					<el-tooltip
						content="都不勾選，就不自動開立，但可以在後台手動開立"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>

			<el-checkbox-group v-model="form.auto_issue_order_statuses">
				<Checkbox
					v-for="orderStatus in env?.ORDER_STATUSES"
					:key="orderStatus.value"
					v-bind="orderStatus"
				/>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item prop="auto_cancel_order_statuses">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>自動作廢收據的訂單狀態</span>
					<el-tooltip
						content="都不勾選，就不自動作廢，但可以在後台手動作廢"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>

			<el-checkbox-group v-model="form.auto_cancel_order_statuses">
				<Checkbox
					v-for="orderStatus in env?.ORDER_STATUSES"
					:key="orderStatus.value"
					v-bind="orderStatus"
				/>
			</el-checkbox-group>
		</el-form-item>

		<el-divider>API 設定</el-divider>

		<el-form-item
			:class="{
				'tw-hidden': !isLocal,
			}"
		>
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>啟用測試模式</span>
					<el-tooltip
						content="開發人員專用，啟用後將使用綠界測試帳號測試開立"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-switch
				v-model="form.mode"
				active-value="test"
				inactive-value="prod"
			/>
		</el-form-item>

		<el-alert
			v-if="!isTestMode"
			title="正式模式請填入綠界商家後台的特店編號、HashKey、HashIV，否則無法開立收據"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item :required="!isTestMode" prop="merchant_id">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>特店編號 MerchantID</span>
					<el-tooltip
						content="綠界電子收據特店編號（一般/公益與政治獻金可能不同）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.merchant_id"
				:disabled="isTestMode"
				clearable
			/>
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="hash_key" label="HashKey">
			<TrimmedInput v-model="form.hash_key" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="hash_iv" label="HashIV">
			<TrimmedInput v-model="form.hash_iv" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
