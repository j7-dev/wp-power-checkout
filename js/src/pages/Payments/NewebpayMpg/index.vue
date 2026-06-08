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
import { ENewebpayMpgPaymentMethod } from '@/pages/Payments/NewebpayMpg/Shared/enums'
import {
	INSTALLMENT_PERIODS,
	PAYMENT_METHODS,
	TFormData,
	VERSION_OPTIONS,
} from '@/pages/Payments/NewebpayMpg/Shared/types'

const gatewayId = 'newebpay_mpg'
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

// 表單資料（對齊 MpgSettingsDTO）
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

	// --- MPG 版本與加密 --- //
	version: '2.3',
	encryptType: 0,

	// --- 付款方式與分期 --- //
	allowedPayments: [],
	installmentPeriods: [],
	twqrLifeTime: 300,
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

const showInstallment = computed(() =>
	form.allowedPayments.includes(ENewebpayMpgPaymentMethod.CREDIT)
)

const showTwqrLifeTime = computed(() =>
	form.allowedPayments.includes(ENewebpayMpgPaymentMethod.TWQR)
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

				// TWQR 需 version 2.3
				if (
					Array.isArray(value) &&
					value.includes(ENewebpayMpgPaymentMethod.TWQR) &&
					form.version !== '2.3'
				) {
					callback(new Error('TWQR 付款方式需將 MPG 版本設為 2.3'))
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
					<span>繳費天數</span>
					<el-tooltip
						content="ATM / 超商代碼 / 超商條碼的繳費期限（1-180 天），逾期失效"
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
				:max="180"
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
						content="開發人員專用，啟用後將使用藍新測試帳號測試付款"
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
			title="正式模式請填入藍新金流商家後台的商店代號、HashKey、HashIV，否則無法付款"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item prop="version" label="MPG 版本">
			<el-select v-model="form.version" class="w-full">
				<el-option
					v-for="opt in VERSION_OPTIONS"
					:key="opt.value"
					:label="opt.label"
					:value="opt.value"
				/>
			</el-select>
		</el-form-item>

		<el-form-item :required="!isTestMode" prop="merchantId">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>商店代號 MerchantID</span>
					<el-tooltip content="藍新金流商家後台分配的商店代號" placement="top">
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.merchantId"
				:disabled="isTestMode"
				clearable
			/>
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

		<el-form-item v-if="showTwqrLifeTime" prop="twqrLifeTime">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>TWQR 有效秒數</span>
					<el-tooltip
						content="TWQR QR code 的有效時間（秒），預設 300，最大 2678400（31 天）"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-input-number
				v-model="form.twqrLifeTime"
				:step="60"
				:min="60"
				:max="2678400"
				align="right"
				class="w-full"
			>
				<template #suffix>
					<span>秒</span>
				</template>
			</el-input-number>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
