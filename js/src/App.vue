<script lang="ts" setup></script>

<template>
	<div class="min-h-[40rem] flex">
		<div class="w-28">
			<el-menu background-color="transparent" default-active="/payments" router>
				<el-menu-item index="/payments"> 金流 </el-menu-item>
				<el-menu-item index="/logistics"> 物流 </el-menu-item>
				<el-menu-item index="/invoices"> 電子發票 </el-menu-item>
				<el-menu-item index="/receipts"> 電子收據 </el-menu-item>
			</el-menu>
		</div>
		<div class="flex-1 px-6">
			<router-view />
		</div>
	</div>
</template>

<style scoped>
.el-menu--vertical {
	--el-menu-item-height: 2rem;
}
</style>

<style>
#wpcontent {
	height: fit-content;
}
</style>
