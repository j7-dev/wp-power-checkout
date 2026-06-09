<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'
import { TFormData } from '@/pages/Payments/PayuniUniEmbed/Shared/types'

const gatewayId = 'payuni_uni_embed'
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

// 表單資料（對齊 PayuniUniEmbedSettingsDTO，全 snake_case）
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',
	order_button_text: '',
	min_amount: 0,
	max_amount: 0,

	// --- API --- //
	mode: 'test',
	merchant_id: '',
	hash_key: '',
	hash_iv: '',

	// --- V3 特有欄位 --- //
	iframe_domain: '',
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
	iframe_domain: [
		{
			validator: (_, value, callback) => {
				// 未填允許（後端 fallback 由 site_url 衍生）；有填則必須含 https://
				if (value && !String(value).startsWith('https://')) {
					callback(new Error('IFrameDomain 必須以 https:// 開頭'))
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
		<el-alert
			title="PAYUNi UNi Embed（內嵌式信用卡，站內付不跳轉）僅支援信用卡。顧客於本站內完成 3D 驗證與付款，不導轉至 PAYUNi 頁面。"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-divider>基本設定</el-divider>

		<el-form-item prop="title" label="顯示名稱">
			<el-input v-model="form.title" clearable />
		</el-form-item>
		<el-form-item prop="description" label="描述">
			<el-input v-model="form.description" clearable />
		</el-form-item>

		<el-form-item prop="order_button_text" label="結帳按鈕文字">
			<el-input v-model="form.order_button_text" clearable />
		</el-form-item>

		<el-form-item prop="min_amount">
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
				v-model="form.min_amount"
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

		<el-form-item prop="max_amount">
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
				v-model="form.max_amount"
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
						content="開發人員專用，啟用後將使用 PAYUNi 測試帳號測試付款"
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
			title="正式模式請填入 PAYUNi 統一金流商家後台的商店代號、HashKey、HashIV，否則無法付款"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<el-form-item :required="!isTestMode" prop="merchant_id">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>商店代號 MerID</span>
					<el-tooltip content="PAYUNi 統一金流商家後台分配的商店代號" placement="top">
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

		<el-form-item prop="iframe_domain">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>IFrameDomain</span>
					<el-tooltip
						content="V3 內嵌元件來源網域（須含 https://，如 https://www.example.com）。留空則自動由本站網址推導。"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<TrimmedInput
				v-model="form.iframe_domain"
				placeholder="留空則自動由本站網址推導（須含 https://）"
				clearable
			/>
		</el-form-item>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
