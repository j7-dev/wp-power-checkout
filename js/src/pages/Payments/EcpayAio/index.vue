<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { computed, reactive, ref, toRaw, watch } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import apiClient from '@/api'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import {
	INSTALLMENT_PERIODS,
	PAYMENT_METHODS,
	TFormData,
} from '@/pages/Payments/EcpayAio/Shared/types'
import { EEcpayAioPaymentMethod } from '@/pages/Payments/EcpayAio/Shared/enums'
import Checkbox from '@/components/Checkbox/index.vue'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'

const gatewayId = 'ecpay_aio'
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

// 表單資料（對齊 AioSettingsDTO）
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',
	orderButtonText: '',
	minAmount: 0,
	maxAmount: 0,
	expireDate: 3,
	// --- API --- //
	mode: 'prod',
	merchantId: '',
	hashKey: '',
	hashIv: '',
	// --- 付款方式與分期 --- //
	allowedPayments: [],
	installmentPeriods: [],
	periodConfig: {},
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
			// 將 API 回傳資料輸入表單
		}
	},
	{ immediate: true },
)

const isTestMode = computed(() => form.mode === 'test')

const showInstallment = computed(() =>
	form.allowedPayments.includes(EEcpayAioPaymentMethod.CREDIT),
)

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
		// 成功後可刷新相關快取
		queryClient.invalidateQueries({ queryKey: ['settings', gatewayId] })
	},
	onError: (err) => {
		console.error('更新失敗', err)
	},
})

const rules = reactive<FormRules<TFormData>>({
	merchantId: [{ required: true, message: '此欄位為必填' }],
	hashKey: [{ required: true, message: '此欄位為必填' }],
	hashIv: [{ required: true, message: '此欄位為必填' }],
	allowedPayments: [
		{
			validator: (_, value, callback) => {
				if (Array.isArray(value) && value.length === 0) {
					callback(new Error('請至少選擇一種付款方式'))
					return
				}
				callback()
			},
		},
	],
})
</script>

<template>
	<div
		class="flex items-center gap-x-2 mb-4 cursor-pointer"
		@click="$router.push('/payments')"
	>
		<el-icon>
			<Back />
		</el-icon>
		回《金流》
	</div>

	<el-form
		v-loading="isPending"
		element-loading-background="rgba(255, 255, 255, 0)"
		:model="form"
		ref="formRef"
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

		<el-form-item prop="orderButtonText" label="結帳按鈕文字">
			<el-input v-model="form.orderButtonText" clearable />
		</el-form-item>

		<el-form-item prop="minAmount">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>最小金額限制</span>
					<el-tooltip
						content="低於此金額，無法使用此付款方式，輸入 0 則不限制"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.minAmount"
				step="1000"
				:min="0"
				:max="10000000"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>NT$</span>
				</template>
			</el-input-number>
		</el-form-item>

		<el-form-item prop="maxAmount">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>最大金額限制</span>
					<el-tooltip
						content="超過此金額，無法使用此付款方式，輸入 0 則不限制"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.maxAmount"
				step="1000"
				:min="0"
				:max="10000000"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>NT$</span>
				</template>
			</el-input-number>
		</el-form-item>

		<el-form-item prop="expireDate">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>ATM 繳費天數</span>
					<el-tooltip
						content="ATM 虛擬帳號繳費期限（1-60 天），逾期帳號失效"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.expireDate"
				:step="1"
				:min="1"
				:max="60"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>天</span>
				</template>
			</el-input-number>
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
						content="開發人員專用，啟用後將使用綠界測試帳號測試付款"
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
			title="正式模式請填入綠界商家後台的特店編號、HashKey、HashIV，否則無法付款"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item :required="!isTestMode" prop="merchantId">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>特店編號 MerchantID</span>
					<el-tooltip
						content="綠界商家後台分配的特店編號"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput v-model="form.merchantId" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="hashKey" label="HashKey">
			<TrimmedInput v-model="form.hashKey" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="hashIv" label="HashIV">
			<TrimmedInput v-model="form.hashIv" :disabled="isTestMode" clearable />
		</el-form-item>

		<el-divider>付款方式</el-divider>

		<el-form-item prop="allowedPayments" label="允許的付款方式">
			<el-checkbox-group v-model="form.allowedPayments">
				<Checkbox
					v-for="paymentMethod in PAYMENT_METHODS"
					:key="paymentMethod.value"
					v-bind="paymentMethod"
				/>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item
			v-if="showInstallment"
			prop="installmentPeriods"
			label="信用卡分期期數"
		>
			<el-checkbox-group v-model="form.installmentPeriods">
				<el-checkbox
					v-for="period in INSTALLMENT_PERIODS"
					:key="period"
					:label="period"
				>
					{{ period }} 期
				</el-checkbox>
			</el-checkbox-group>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
