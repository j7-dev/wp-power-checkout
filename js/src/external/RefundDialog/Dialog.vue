<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { IOrderData, DEFAULT_ORDER_DATA } from './types'
import apiClient from '@/api'
import { useMutation } from '@tanstack/vue-query'

enum EOrderStatus {
	NAME = 'order_status',
	REFUNDED = 'wc-refunded',
}

const form = document.querySelector('form#order, form#post') as HTMLFormElement
if (!form) {
	console.error('找不到 form#order, form#post 訂單表單')
}
const fromFormData = new FormData(form)
const fromOrderStatus = fromFormData.get(EOrderStatus.NAME)

const showDialog = ref(false)

const orderData = (window?.power_checkout_order_data ||
	DEFAULT_ORDER_DATA) as IOrderData

const gatewayName =
	orderData?.gateway?.method_title || DEFAULT_ORDER_DATA.gateway.method_title
const order = orderData?.order || DEFAULT_ORDER_DATA.order
const dialogContent = `<p>執行退款，會將此訂單剩餘可退金額 ${order.remaining_refund_amount} 退還給用戶</p>`

function handleSubmit(e: Event) {
	const toFormData = new FormData(form)
	const toOrderStatus = toFormData.get(EOrderStatus.NAME)

	console.log(`handleSubmit ${EOrderStatus.NAME} ${EOrderStatus.REFUNDED}`, {
		fromOrderStatus,
		toOrderStatus,
	})

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
		alert(`${data?.data?.message || '手動退款成功'}，即將刷新頁面`)
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
		alert(`${data?.data?.message || '退款成功'}，即將刷新頁面`)
		window.location.reload()
	},
})

const handleRefundViaGateway: () => Promise<void> = async () => {
	await refund()
}

onMounted(() => {
	// form#order = HPOS、form#post = 傳統訂單頁，兩者都要監聽
	const form = document.querySelector('form#order, form#post')
	if (form) {
		form.addEventListener('submit', handleSubmit)
	}
})

onUnmounted(() => {
	const form = document.querySelector('form#order, form#post')
	form?.removeEventListener('submit', handleSubmit)
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
		<div v-html="dialogContent"></div>
		<template #footer>
			<div class="dialog-footer">
				<el-button @click="showDialog = false">取消</el-button>

				<el-button
					type="primary"
					@click="handleRefundManual"
					plain
					:loading="isPendingManual"
				>
					手動退款
				</el-button>
				<el-button
					type="primary"
					@click="handleRefundViaGateway"
					:loading="isPending"
				>
					使用 {{ gatewayName }} 自動退款
				</el-button>
			</div>
		</template>
	</el-dialog>
</template>
