<script lang="ts" setup>
/**
 * <TrimmedInput> — el-input 的薄殼包裝，在失焦（@blur）時自動修剪
 * v-model 綁定值的前後不可見字元。
 *
 * Issue #16 — 給管理員從外部來源複製貼上金鑰時的即時 UX 回饋；
 * 後端 ProviderUtils::update_option 為最終防線，本元件僅負責前端體驗加分。
 *
 * 行為：
 *  - 輸入中（@input）不修剪，避免打字到一半被改值
 *  - 失焦時（@blur）對 modelValue 套用 trimInvisible 並 emit 新值
 *  - 修剪靜默（無提示通知，符合規格 Q5 決策 A）
 *  - 中間不可見字元保留（規格場景「中間空白不被移除」）
 *
 * 用法：
 *   <TrimmedInput v-model="form.apiKey" :disabled="isTestMode" clearable />
 *
 * 特性：
 *  - 透過 defineModel() 雙向綁定 v-model
 *  - inheritAttrs: false + v-bind="$attrs" 透傳所有 el-input prop
 *  - slot 透傳 prefix / suffix / prepend / append
 */
import { useAttrs } from 'vue'
import { ElInput } from 'element-plus'
import { trimInvisible } from '@/utils/trim'

defineOptions({
    name: 'TrimmedInput',
    inheritAttrs: false,
})

const modelValue = defineModel<string>({ default: '' })

const emit = defineEmits<{
    blur: [event: FocusEvent]
}>()

// 向 parent 露出 useAttrs 結果（v-bind 模板層用 $attrs 即可）
useAttrs()

const handleBlur = (event: FocusEvent): void => {
    const trimmed = trimInvisible(modelValue.value)
    if (trimmed !== modelValue.value) {
        modelValue.value = trimmed
    }
    emit('blur', event)
}
</script>

<template>
    <el-input
        v-bind="$attrs"
        v-model="modelValue"
        @blur="handleBlur"
    >
        <template
            v-for="(_, name) in $slots"
            #[name]="slotData"
            :key="name"
        >
            <slot :name="name" v-bind="slotData || {}" />
        </template>
    </el-input>
</template>
