<script lang="ts" setup>
/**
 * 全域設定頁（/settings）— placeholder（待開發）
 *
 * 目前 /settings REST 端點（SettingApiService::get_settings_callback）僅回傳
 * gateways / invoices / receipts / logistics 各 provider 列表，
 * 尚無「全域層級」設定項可顯示。各 provider 設定由其各自的設定頁處理。
 *
 * 待未來新增全域設定（如預設地址格式、共用 log 設定等）時，於此渲染表單。
 * 此路由由 router/index.ts 引用，請勿移除。
 */
</script>

<template>
	<ElEmpty description="全域設定（開發中）" />
</template>

<style scoped>

</style>