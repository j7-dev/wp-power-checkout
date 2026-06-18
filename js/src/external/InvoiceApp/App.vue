<script setup lang="ts">
import { Tickets } from '@element-plus/icons-vue'
import { useMutation } from '@tanstack/vue-query'
import { ref } from 'vue'

import apiClient from '@/api'
import { resolveErrorMessage, getNormalizedError } from '@/utils/error-code'

import Steps from './Steps/index.vue'

import { appData, isAdmin, MAPPER } from './index'

const dialogVisible = ref(false)

const { mutate: cancelInvoice, isPending: isCanceling } = useMutation({
	mutationFn: async (orderId: string) =>
		await apiClient.post(`/invoices/cancel/${orderId}`),
	onError: (err) => {
		// interceptor 已用 error_code 對照彈出使用者通知；此處解析出同一則
		// 精確訊息供 debug log（取代純粹的 console.error）。
		const normalized = getNormalizedError(err)
		// eslint-disable-next-line no-console
		console.error(
			'作廢電子發票失敗',
			resolveErrorMessage(normalized),
			normalized?.raw_code ?? '',
			err
		)
	},
})

const handleCancel = () => {
	const orderId = appData?.order?.id
	cancelInvoice(orderId)
}
</script>

<template>
	<div
		v-if="isAdmin && appData?.is_issued"
		class="flex gap-2 items-center text-md font-bold text-gray-700 mb-4"
	>
		<el-icon><Tickets /></el-icon>
		<span>發票號碼：{{ appData?.invoice_number }}</span>
	</div>
	<div class="flex justify-between items-center">
		<el-button
			v-if="isAdmin && appData?.is_issued"
			type="danger"
			:loading="isCanceling"
			@click="handleCancel"
			>作廢發票</el-button
		>
		<el-button
			v-if="!appData?.is_issued"
			type="primary"
			@click="dialogVisible = true"
			>{{ MAPPER.ISSUE_INVOICE }}</el-button
		>
	</div>

	<el-dialog
		v-model="dialogVisible"
		:title="MAPPER.ISSUE_INVOICE"
		width="600"
		align-center
		:z-index="999999"
		class="p-8"
	>
		<Steps :dialog-visible="dialogVisible" @close="dialogVisible = false" />
	</el-dialog>
</template>

<style scoped></style>
