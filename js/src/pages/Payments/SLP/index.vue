<script lang="ts" setup>
import { Back, InfoFilled, WarningFilled } from '@element-plus/icons-vue'
import { computed, reactive, ref, toRaw, watch } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import apiClient from '@/api'
import type { FormRules } from 'element-plus'
import { merge, pick } from 'lodash-es'
import { PAYMENT_METHODS, TFormData } from '@/pages/Payments/SLP/Shared/types'
import { EPaymentMethods } from '@/pages/Payments/SLP/Shared/enums'
import Checkbox from '@/components/Checkbox/index.vue'
import TrimmedInput from '@/components/TrimmedInput.vue'
import { env } from '@/index'

const gatewayId = 'shopline_payment_redirect'
const apiUrl = env.API_URL
const isLocal = env.IS_LOCAL

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

// 表單資料
const form = reactive<TFormData>({
    // --- 一般設定 --- //
    title: '',
    description: '',
    order_button_text: '',
    min_amount: 0,
    max_amount: 0,
    expire_min: 360,
    // --- API --- //
    mode: 'prod',
    merchantId: '',
    apiKey: '',
    clientKey: '',
    signKey: '',
    allowPaymentMethodList: [],
    paymentMethodOptions: {
        CreditCard: {
            installmentCounts: [],
        },
        ChaileaseBNPL: {
            installmentCounts: [],
        },
    },
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

/**
 * 共用 validator：當該付款方式在 allowPaymentMethodList 中啟用時，
 * 對應的 installmentCounts 陣列必須至少勾選一個期數，否則擋下儲存。
 *
 * Issue #12：商家取消勾選中租分期的所有期數（包含 0 期）後儲存，
 * 送給 SLP 的 payload 會缺少 installmentCounts key，SLP 會 fallback
 * 顯示全部期數，造成「明明取消勾選卻仍顯示」的 bug。
 * A 案（UX 防呆）：強制至少勾一個期數，避免 silent failure。
 */
const createInstallmentCountsValidator = (methodKey: EPaymentMethods) =>
    (_: unknown, value: string[] | undefined, callback: (err?: Error) => void) => {
        // 如果商家沒啟用該付款方式，則跳過驗證
        if (!form.allowPaymentMethodList.includes(methodKey)) {
            callback()
            return
        }
        if (!Array.isArray(value) || value.length === 0) {
            callback(new Error('請至少選擇一個分期期數'))
            return
        }
        callback()
    }

const rules = reactive<FormRules<TFormData>>({
    merchantId: [
        { required: true, message: '此欄位為必填' },
    ],
    apiKey: [
        { required: true, message: '此欄位為必填' },
    ],
    clientKey: [
        { required: true, message: '此欄位為必填' },
    ],
    signKey: [
        { required: true, message: '此欄位為必填' },
    ],
    allowPaymentMethodList: [
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
    'paymentMethodOptions.CreditCard.installmentCounts': [
        {
            validator: createInstallmentCountsValidator(EPaymentMethods.CREDIT_CARD),
            trigger: ['change', 'blur'],
        },
    ],
    'paymentMethodOptions.ChaileaseBNPL.installmentCounts': [
        {
            validator: createInstallmentCountsValidator(EPaymentMethods.CHAILEASE_BNPL),
            trigger: ['change', 'blur'],
        },
    ],
})

const creditCardInstallment = [
    '0',
    '3',
    '6',
    '9',
    '12',
    '18',
    '24',
]
const chaileaseBNPLInstallment = [
    '0',
    '3',
    '6',
    '12',
    '18',
    '24',
    '30',
    '36',
]

const webhookEvents = [
    'trade.refund.succeeded',
    'trade.refund.failed',
    'trade.succeeded',
    'trade.failed',
    'trade.expired',
    'trade.processing',
    'trade.cancelled',
    'trade.customer_action',
    'session.created',
    'session.expired',
    'session.pending',
    'session.succeeded',
]


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
        <el-form-item prop="expire_time">
            <template #label>
				<span class="flex gap-x-2 items-center">
					<span>付款期限</span>
					<el-tooltip
                            content="預設 6 小時，輸入 0 會套用預設值"
                            placement="top"
                    >
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
            </template>
            <el-input-number
                    v-model="form.expire_min"
                    step="60"
                    :min="60"
                    :max="10000000"
                    align="right"
                    class="w-full"
            >
                <template #suffix>
                    <span>分鐘</span>
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
                            content="開發人員專用，啟用後將使用測試的串接碼測試付款"
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

        <!--		<el-form-item prop="platformId">-->
        <!--			<template #label>-->
        <!--				<span class="flex gap-x-2 items-center">-->
        <!--					<span>Platform Id</span>-->
        <!--					<el-tooltip-->
        <!--						content="SLP 平台 ID，平台特店必填，平台特店底下會有子特店"-->
        <!--						placement="top"-->
        <!--					>-->
        <!--						<el-icon><InfoFilled /></el-icon>-->
        <!--					</el-tooltip>-->
        <!--				</span>-->
        <!--			</template>-->
        <!--			<TrimmedInput v-model="form.platformId" :disabled="isTestMode" clearable />-->
        <!--		</el-form-item>-->

        <el-form-item :required="!isTestMode" prop="merchantId">
            <template #label>
				<span class="flex gap-x-2 items-center">
					<span>Merchant Id</span>
					<el-tooltip
                            content="直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID"
                            placement="top"
                    >
						<el-icon><InfoFilled /></el-icon>
					</el-tooltip>
				</span>
            </template>
            <TrimmedInput v-model="form.merchantId" :disabled="isTestMode" clearable />
        </el-form-item>

        <el-form-item :required="!isTestMode" prop="apiKey" label="Api Key">
            <TrimmedInput v-model="form.apiKey" :disabled="isTestMode" clearable />
        </el-form-item>

        <el-form-item :required="!isTestMode" prop="clientKey" label="Client Key">
            <TrimmedInput v-model="form.clientKey" :disabled="isTestMode" clearable />
        </el-form-item>

        <el-form-item
                :required="!isTestMode"
                prop="signKey"
                label="Sign Key"
                class="[&_p]:text-sm [&_p]:text-gray-500 [&_p]:mb-2 [&_p]:mt-0"
        >
            <TrimmedInput class="mb-4" v-model="form.signKey" clearable />
            <p>提供以下資訊給 Shopline 窗口後取得 Sign Key</p>
            <p>
                Webhook URL: <code>{{ apiUrl }}/power-checkout/slp/webhook</code>
            </p>
            <p class="flex flex-wrap gap-1">
                Webhook Event: <code v-for="event in webhookEvents">{{ event }}</code>
            </p>
        </el-form-item>

        <el-alert
                v-if="form.allowPaymentMethodList.includes(EPaymentMethods.LINE_PAY)"
                title="請先確認已在 SLP 後台啟用 LINE Pay，否則可能導致用戶無法結帳"
                type="warning"
                class="mb-4"
                :closable="false"
                show-icon
        >
            <template #icon>
                <WarningFilled />
            </template>
        </el-alert>
        <el-alert
                v-if="form.allowPaymentMethodList.includes(EPaymentMethods.JKO_PAY)"
                title="請先確認您已經在 Shopline Payment 後台申請並啟用街口支付，否則可能導致用戶無法結帳"
                type="error"
                class="mb-4"
                :closable="false"
                show-icon
        >
            <template #icon>
                <WarningFilled />
            </template>
        </el-alert>
        <el-form-item prop="allowPaymentMethodList" label="允許的付款方式">
            <el-checkbox-group v-model="form.allowPaymentMethodList">
                <Checkbox
                        v-for="paymentMethod in PAYMENT_METHODS"
                        :key="paymentMethod.value"
                        v-bind="paymentMethod"
                />
            </el-checkbox-group>
        </el-form-item>

        <el-form-item
                v-if="form.allowPaymentMethodList.includes(EPaymentMethods.CREDIT_CARD)"
                prop="paymentMethodOptions.CreditCard.installmentCounts"
                label="信用卡分期期數"
        >
            <el-checkbox-group
                    v-model="form.paymentMethodOptions.CreditCard.installmentCounts"
            >
                <el-checkbox
                        v-for="period in creditCardInstallment"
                        :key="period"
                        :label="period"
                >
                    {{ period }}
                </el-checkbox>
            </el-checkbox-group>
        </el-form-item>

        <el-form-item
                v-if="
				form.allowPaymentMethodList.includes(EPaymentMethods.CHAILEASE_BNPL)
			"
                prop="paymentMethodOptions.ChaileaseBNPL.installmentCounts"
                label="中租分期期數"
        >
            <el-checkbox-group
                    v-model="form.paymentMethodOptions.ChaileaseBNPL.installmentCounts"
            >
                <el-checkbox
                        v-for="period in chaileaseBNPLInstallment"
                        :key="period"
                        :label="period"
                >
                    {{ period }}
                </el-checkbox>
            </el-checkbox-group>
        </el-form-item>

        <el-form-item class="[&_.el-form-item\_\_content]:justify-center">
            <el-button :loading="isSavePending" type="primary" @click="onSubmit"
            >儲存
            </el-button
            >
        </el-form-item>
    </el-form>
</template>
