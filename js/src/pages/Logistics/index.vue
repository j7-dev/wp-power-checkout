<script lang="ts" setup>
import { computed } from 'vue'
import Card from '@/components/Card.vue'
import { useQuery } from '@tanstack/vue-query'
import apiClient from '@/api'
import { TIGateway } from '@/types'

const { isPending, data, error } = useQuery({
	queryKey: [
		'settings',
	],
	queryFn: async () => await apiClient.get('settings'),
	select: (response) => {
		return response?.data?.data?.logistics || response
	},
})

// 監聽錯誤
if (error.value) {
	console.log('API Error:', error.value)
}

const gateways = computed(() => Object.values(data?.value || {}) as TIGateway[])
</script>

<template>
	<div
		:class="[
			'pb-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5',
			{
				'opacity-25': isPending,
			},
		]"
	>
		<Card v-for="gateway in gateways" :key="gateway.id" v-bind="gateway" />
	</div>
</template>
