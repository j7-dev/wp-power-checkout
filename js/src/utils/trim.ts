/**
 * Trim invisible characters from start/end of a string.
 *
 * Issue #16 — 處理管理員從外部來源（後台、Email、Word、Slack）複製貼上時夾帶的
 * 肉眼看不見的前後字元（半形空白、Tab、CR/LF、全形空白、不換行空白、
 * 零寬字元、BOM 等）。
 *
 * 涵蓋字元集合（與後端 PHP `StrHelper::trim_invisible` 同步，若調整任一邊
 * 請務必同步另一邊；單一事實來源 single source of truth）：
 *   - U+0009 Tab
 *   - U+000A LF / U+000D CR
 *   - U+000B 垂直 Tab / U+000C Form Feed
 *   - U+0020 半形空白
 *   - U+00A0 不換行空白（NBSP）
 *   - U+3000 全形空白
 *   - U+200B 零寬空白 / U+200C 零寬非連接 / U+200D 零寬連接
 *   - U+FEFF BOM
 *
 * 設計原則：
 *   - 僅處理「前後」，欄位中間的不可見字元保留（可能是合法的金鑰內容）
 *   - 純不可見字元字串會回傳空字串（給 form validator 接手提示 required 紅字）
 *   - 不影響合法 UTF-8 字元（中文、Emoji 等）
 *
 * @param value 待修剪的字串（接受 null / undefined，視為空字串）
 * @returns 修剪後的字串
 *
 * @example
 *   trimInvisible('  sk_live_abc  ')           // 'sk_live_abc'
 *   trimInvisible('　sk_live_abc　')   // 'sk_live_abc'
 *   trimInvisible('sk_live abc 123')           // 'sk_live abc 123'（中間保留）
 *   trimInvisible('   ')                       // ''
 *
 * @see {@link inc/classes/Shared/Utils/StrHelper.php#trim_invisible}
 */

// 不可見字元集合（與後端 PHP 同步）。改成 codepoint 陣列方便閱讀與維護。
const INVISIBLE_CODEPOINTS = [
    '\\u0009', // Tab
    '\\u000A', // LF
    '\\u000B', // 垂直 Tab
    '\\u000C', // Form Feed
    '\\u000D', // CR
    '\\u0020', // 半形空白
    '\\u00A0', // 不換行空白（NBSP）
    '\\u3000', // 全形空白
    '\\u200B', // 零寬空白
    '\\u200C', // 零寬非連接
    '\\u200D', // 零寬連接
    '\\uFEFF', // BOM
] as const

const INVISIBLE_CHAR_CLASS = `[${INVISIBLE_CODEPOINTS.join('')}]`

// 用 RegExp 建構函式以避免字面量中夾帶肉眼不可見字元
const TRIM_PATTERN = new RegExp(
    `^${INVISIBLE_CHAR_CLASS}+|${INVISIBLE_CHAR_CLASS}+$`,
    'gu',
)

export function trimInvisible(value: string | null | undefined): string {
    if (value === null || value === undefined) {
        return ''
    }
    return value.replace(TRIM_PATTERN, '')
}
