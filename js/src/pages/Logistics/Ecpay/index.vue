<script lang="ts" setup>
import { Back, InfoFilled } from '@element-plus/icons-vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { computed, reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'
import {
	EEcpayLogisticsAccountType,
	LOGISTICS_ACCOUNT_TYPE_OPTIONS,
	LOGISTICS_SUB_TYPE_OPTIONS,
} from '@/pages/Logistics/Ecpay/Shared/enums'
import { TFormData } from '@/pages/Logistics/Ecpay/Shared/types'

const gatewayId = 'ecpay_logistics'
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

// 表單資料（對齊 EcpayLogisticsSettingsDTO）
const form = reactive<TFormData>({
	// --- 一般設定 --- //
	title: '',
	description: '',

	// --- API / 模式 --- //
	mode: 'prod',

	// --- 帳號類型與兩組憑證 --- //
	account_type: 'b2c',
	b2c_merchant_id: '',
	b2c_hash_key: '',
	b2c_hash_iv: '',
	c2c_merchant_id: '',
	c2c_hash_key: '',
	c2c_hash_iv: '',

	// --- 物流方式與寄件人 --- //
	enabled_methods: [],
	sender_name: '',
	sender_phone: '',
	sender_zip_code: '',
	sender_address: '',
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
	{ immediate: true }
)

const isTestMode = computed(() => form.mode === 'test')
const isB2c = computed(
	() => form.account_type === EEcpayLogisticsAccountType.B2C
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

// 只驗證目前 account_type 對應且可見的憑證組（測試模式由 DTO 補預設憑證，故不強制）
const rules = computed<FormRules<TFormData>>(() => {
	if (isTestMode.value) {
		return {}
	}
	const requiredRule = [{ required: true, message: '此欄位為必填' }]
	return isB2c.value
		? {
				b2c_merchant_id: requiredRule,
				b2c_hash_key: requiredRule,
				b2c_hash_iv: requiredRule,
			}
		: {
				c2c_merchant_id: requiredRule,
				c2c_hash_key: requiredRule,
				c2c_hash_iv: requiredRule,
			}
})
</script>

<template>
	<div
		class="flex items-center gap-x-2 mb-4 cursor-pointer"
		@click="$router.push('/logistics')"
	>
		<el-icon>
			<Back />
		</el-icon>
		回《物流》
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
			<el-input
				v-model="form.description"
				type="textarea"
				:rows="2"
				clearable
			/>
		</el-form-item>

		<el-divider>物流方式</el-divider>

		<el-form-item prop="enabled_methods">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>啟用的物流方式</span>
					<el-tooltip
						content="勾選欲在結帳頁提供的取貨方式，超商需消費者選店、宅配為黑貓配送"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>

			<el-checkbox-group v-model="form.enabled_methods">
				<el-checkbox
					v-for="opt in LOGISTICS_SUB_TYPE_OPTIONS"
					:key="opt.value"
					:label="opt.value"
				>
					{{ opt.label }}
				</el-checkbox>
			</el-checkbox-group>
		</el-form-item>

		<el-divider>寄件人資訊</el-divider>

		<el-form-item prop="sender_name" label="寄件人姓名">
			<el-input v-model="form.sender_name" clearable />
		</el-form-item>
		<el-form-item prop="sender_phone" label="寄件人電話">
			<el-input v-model="form.sender_phone" clearable />
		</el-form-item>
		<el-form-item prop="sender_zip_code" label="寄件人郵遞區號">
			<el-input v-model="form.sender_zip_code" clearable />
		</el-form-item>
		<el-form-item prop="sender_address" label="寄件人地址">
			<el-input v-model="form.sender_address" clearable />
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
						content="開發人員專用，啟用後將使用綠界全方位物流公開測試帳號"
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

		<el-form-item prop="account_type">
			<template #label>
				<span class="flex gap-x-2 items-center">
					<span>帳號類型</span>
					<el-tooltip
						content="B2C 大宗寄倉與 C2C 店到店為兩組獨立憑證，請選擇與綠界後台一致的帳號類型"
						placement="top"
					>
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
			</template>
			<el-radio-group v-model="form.account_type">
				<el-radio
					v-for="opt in LOGISTICS_ACCOUNT_TYPE_OPTIONS"
					:key="opt.value"
					:value="opt.value"
				>
					{{ opt.label }}
				</el-radio>
			</el-radio-group>
		</el-form-item>

		<el-alert
			v-if="!isTestMode"
			title="正式模式請填入綠界全方位物流後台的特店編號、HashKey、HashIV，否則無法建立物流單"
			type="info"
			class="mb-4"
			:closable="false"
			show-icon
		/>

		<!-- B2C 憑證組 -->
		<template v-if="isB2c">
			<el-form-item :required="!isTestMode" prop="b2c_merchant_id">
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span>B2C 特店編號 MerchantID</span>
						<el-tooltip
							content="綠界全方位物流 B2C 大宗寄倉特店編號"
							placement="top"
						>
							<el-icon><InfoFilled /></el-icon>
						</el-tooltip>
					</span>
				</template>
				<TrimmedInput
					v-model="form.b2c_merchant_id"
					:disabled="isTestMode"
					clearable
				/>
			</el-form-item>

			<el-form-item
				:required="!isTestMode"
				prop="b2c_hash_key"
				label="B2C HashKey"
			>
				<TrimmedInput
					v-model="form.b2c_hash_key"
					:disabled="isTestMode"
					clearable
				/>
			</el-form-item>

			<el-form-item
				:required="!isTestMode"
				prop="b2c_hash_iv"
				label="B2C HashIV"
			>
				<TrimmedInput
					v-model="form.b2c_hash_iv"
					:disabled="isTestMode"
					clearable
				/>
			</el-form-item>
		</template>

		<!-- C2C 憑證組 -->
		<template v-else>
			<el-form-item :required="!isTestMode" prop="c2c_merchant_id">
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span>C2C 特店編號 MerchantID</span>
						<el-tooltip
							content="綠界全方位物流 C2C 店到店特店編號"
							placement="top"
						>
							<el-icon><InfoFilled /></el-icon>
						</el-tooltip>
					</span>
				</template>
				<TrimmedInput
					v-model="form.c2c_merchant_id"
					:disabled="isTestMode"
					clearable
				/>
			</el-form-item>

			<el-form-item
				:required="!isTestMode"
				prop="c2c_hash_key"
				label="C2C HashKey"
			>
				<TrimmedInput
					v-model="form.c2c_hash_key"
					:disabled="isTestMode"
					clearable
				/>
			</el-form-item>

			<el-form-item
				:required="!isTestMode"
				prop="c2c_hash_iv"
				label="C2C HashIV"
			>
				<TrimmedInput
					v-model="form.c2c_hash_iv"
					:disabled="isTestMode"
					clearable
				/>
			</el-form-item>
		</template>

		<el-form-item class="[&_.el-form-item\_\_content]:justify-center">
			<el-button :loading="isSavePending" type="primary" @click="onSubmit"
				>儲存</el-button
			>
		</el-form-item>
	</el-form>
</template>
