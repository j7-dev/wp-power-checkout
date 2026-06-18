<script setup lang="ts">
import { useMutation } from '@tanstack/vue-query'
import { ref, onMounted, onUnmounted } from 'vue'

import apiClient from '@/api'
import { ERROR_CODE, isErrorCode } from '@/utils/error-code'

import { IOrderData, DEFAULT_ORDER_DATA } from './types'

enum EOrderStatus {
	NAME = 'order_status',
	REFUNDED = 'wc-refunded',
}

const orderForm = document.querySelector(
	'form#order, form#post'
) as HTMLFormElement
if (!orderForm) {
	// eslint-disable-next-line no-console
	console.error('找不到 form#order, form#post 訂單表單')
}
const fromFormData = new FormData(orderForm)
const fromOrderStatus = fromFormData.get(EOrderStatus.NAME)

const showDialog = ref(false)

// 退款失敗且為「不支援線上退款」（UNSUPPORTED）時，於 dialog 內顯眼提示
// 改走手動退款；通知本身仍由 interceptor 統一彈出，此處只是補一塊行動指引。
const showUnsupportedHint = ref(false)

const orderData = (window?.power_checkout_order_data ||
	DEFAULT_ORDER_DATA) as IOrderData

const gatewayName =
	orderData?.gateway?.method_title || DEFAULT_ORDER_DATA.gateway.method_title
const order = orderData?.order || DEFAULT_ORDER_DATA.order
const dialogContent = `<p>執行退款，會將此訂單剩餘可退金額 ${order.remaining_refund_amount} 退還給用戶</p>`

function handleSubmit(e: Event) {
	const toFormData = new FormData(orderForm)
	const toOrderStatus = toFormData.get(EOrderStatus.NAME)

	if (toOrderStatus !== EOrderStatus.REFUNDED) {
		return
	}

	if (fromOrderStatus === toOrderStatus) {
		return
	}

	e.preventDefault()
	e.stopPropagation()

	showDialog.value = true
}

const { mutateAsync: refundManual, isPending: isPendingManual } = useMutation({
	mutationFn: async () => {
		return apiClient.post('refund/manual', {
			order_id: order.id,
		})
	},
	onSuccess(data) {
		// eslint-disable-next-line no-alert
		window.alert(`${data?.data?.message || '手動退款成功'}，即將刷新頁面`)
		window.location.reload()
	},
})

const handleRefundManual: () => Promise<void> = async () => {
	await refundManual()
}

const { mutateAsync: refund, isPending } = useMutation({
	mutationFn: async () => {
		return apiClient.post('refund', {
			order_id: order.id,
		})
	},
	onSuccess(data) {
		// eslint-disable-next-line no-alert
		window.alert(`${data?.data?.message || '退款成功'}，即將刷新頁面`)
		window.location.reload()
	},
	onError(error) {
		// interceptor 已彈出錯誤通知；此處僅針對 UNSUPPORTED 補一塊
		// 「請改用手動退款」的行動指引（不重複觸發通知）。
		showUnsupportedHint.value = isErrorCode(error, ERROR_CODE.UNSUPPORTED)
	},
})

const handleRefundViaGateway: () => Promise<void> = async () => {
	showUnsupportedHint.value = false
	await refund()
}

onMounted(() => {
	// form#order = HPOS、form#post = 傳統訂單頁，兩者都要監聽
	const el = document.querySelector('form#order, form#post')
	if (el) {
		el.addEventListener('submit', handleSubmit)
	}
})

onUnmounted(() => {
	const el = document.querySelector('form#order, form#post')
	el?.removeEventListener('submit', handleSubmit)
})
</script>

<template>
	<el-dialog
		v-model="showDialog"
		title="請選擇退款方式"
		width="600"
		align-center
		:z-index="999999"
	>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-html="dialogContent"></div>

		<el-alert
			v-if="showUnsupportedHint"
			class="mt-4"
			type="warning"
			title="此付款方式不支援線上退款"
			description="請改用「手動退款」，並至金流後台手動完成實際退款作業。"
			show-icon
			:closable="false"
		/>

		<template #footer>
			<div class="dialog-footer">
				<el-button @click="showDialog = false">取消</el-button>

				<el-button
					type="primary"
					plain
					:loading="isPendingManual"
					@click="handleRefundManual"
				>
					手動退款
				</el-button>
				<el-button
					type="primary"
					:loading="isPending"
					@click="handleRefundViaGateway"
				>
					使用 {{ gatewayName }} 自動退款
				</el-button>
			</div>
		</template>
	</el-dialog>
</template>
