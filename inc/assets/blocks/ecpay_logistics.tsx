/**
 * 綠界 ECPay 全方位物流 — WC Block Checkout 選店整合（第二期 P2-E）
 *
 * 與付款方式 block（ecpay_aio / ecpay_ecpg）不同：物流屬「運送方式 / shipping」，
 * WC Blocks 沒有 registerShippingMethod 對應 API，故改用官方 slot fill
 * `ExperimentalOrderShippingPackages`（scope: woocommerce-checkout）將自訂 UI
 * 插入結帳頁「運送方式」步驟下方。
 *
 * 行為：
 *   1. 訂閱 wc/store/cart 取得目前選定的運送方式（shippingRates → selected rate）。
 *   2. 僅當選定的運送方式為「綠界超商取貨子類型（FAMI/UNIMART/HILIFE）」時，顯示
 *      「選擇門市」按鈕（宅配 HOME 不需選店）。
 *   3. 已選門市時，於按鈕旁顯示門市名稱 + 地址（從 cart extensions 的本外掛 namespace 讀回）。
 *
 * cart/session 級串接（後端已就緒）：block checkout 在「下單前」無正式訂單，故走 cart 級選店：
 *   - 點「選擇門市」→ POST power-checkout/v1/logistics/store-selection（cart 級，session 權杖綁定）
 *     → 取得綠界 RWD 選店頁整頁 HTML → document.write 開啟。
 *   - 顧客選店後綠界以 session 權杖（pc_st）回呼寫入 WC session；
 *     回到結帳頁時由 cart.extensions['ecpay_logistics'] 同步已選門市（後端 ExtendSchema 注入）。
 *   - 下單時 woocommerce_store_api_checkout_order_processed 把 session 門市搬進 order meta。
 *
 * @see inc/classes/Domains/Logistics/Ecpay/Services/BlocksLogisticsIntegration.php（後端串接）
 * @see .claude/rules/react-blocks.rule.md（WP/WC window globals、不加 npm dep）
 * @see specs/ui/物流選店頁面.md
 * @see inc/classes/Domains/Logistics/Ecpay/Services/WC_EcpayLogisticsShipping.php（classic 對照）
 */

import {
	ExperimentalOrderShippingPackages,
	extensionCartUpdate,
} from '@woocommerce/blocks-checkout'
import { getSetting } from '@woocommerce/settings'
import { useSelect } from '@wordpress/data'
import {
	createElement,
	useState,
	useEffect,
	useCallback,
	Fragment,
} from '@wordpress/element'
import { decodeEntities } from '@wordpress/html-entities'
import { __ } from '@wordpress/i18n'
import { registerPlugin } from '@wordpress/plugins'

/** 本外掛在 WC Block / Store API 的 namespace（與後端 register_update_callback 對應） */
const NAMESPACE = 'ecpay_logistics'

/** 運送方式 id（對齊 WC_EcpayLogisticsShipping::METHOD_ID） */
const SHIPPING_METHOD_ID = 'ecpay_logistics'

/** 需選店的超商取貨子類型（對齊 LogisticsSubType::is_cvs() === true；HOME 宅配不需選店） */
const CVS_SUB_TYPES = ['FAMI', 'UNIMART', 'HILIFE'] as const

/**
 * 後端 BlocksIntegration（或 SettingApiService）提供的物流靜態設定
 *
 * 由 `getSetting('ecpay_logistics_data', {})` 取得（後端 BlocksLogisticsIntegration::register_asset_data
 * 透過 AssetDataRegistry 注入）。欄位皆為可選；缺資料時 UI 退化為提示。
 */
type TLogisticsBlockSettings = {
	/** 結帳頁可選的物流子類型白名單（settings.enabled_methods） */
	enabled_methods?: string[]

	/** 取得「cart 級」選店導轉頁 HTML 的 REST 端點（power-checkout/v1/logistics/store-selection） */
	store_selection_url?: string

	/** wp_rest nonce（X-WP-Nonce），cart 級選店端點以 ApiBase nonce 機制保護 */
	nonce?: string
}

const settings: TLogisticsBlockSettings = getSetting(`${NAMESPACE}_data`, {})

/** 已選門市資訊（從 cart extensions 讀回 / 選店回呼寫入 session 後同步） */
type TSelectedStore = {
	store_id: string
	store_name: string
	store_addr: string
	sub_type: string
}

/**
 * 從 cart extensions 取出本 namespace 的已選門市（後端 ExtendSchema 註冊後才有值）
 *
 * @param extensions cart.extensions（camelCase）
 * @return 已選門市，或 null
 */
const readSelectedStore = (
	extensions: Record<string, unknown> | undefined
): TSelectedStore | null => {
	const ext = extensions?.[NAMESPACE] as Partial<TSelectedStore> | undefined
	if (!ext || !ext.store_id) {
		return null
	}
	return {
		store_id: String(ext.store_id ?? ''),
		store_name: String(ext.store_name ?? ''),
		store_addr: String(ext.store_addr ?? ''),
		sub_type: String(ext.sub_type ?? ''),
	}
}

/**
 * 判斷目前選定的運送方式是否為「綠界超商取貨（需選店）」
 *
 * shippingRates 結構：packages[].shipping_rates[]，每個 rate 有 rate_id / selected。
 * rate_id 形如 `ecpay_logistics:3`；另以 rate 的 meta_data 帶回 sub_type（後端可加）。
 *
 * @param shippingRates cart.shippingRates（camelCase）
 * @return 若選定為超商取貨子類型，回傳該 sub_type；否則 null
 */
const getSelectedCvsSubType = (
	shippingRates: Array<{
		shipping_rates?: Array<{
			rate_id?: string
			selected?: boolean
			method_id?: string
			meta_data?: Array<{ key: string; value: string }>
		}>
	}>
): string | null => {
	for (const pkg of shippingRates ?? []) {
		for (const rate of pkg.shipping_rates ?? []) {
			if (!rate.selected) {
				continue
			}

			// 以 method_id 或 rate_id 前綴判斷是否為本物流運送方式
			const isOurMethod =
				rate.method_id === SHIPPING_METHOD_ID ||
				(rate.rate_id ?? '').startsWith(`${SHIPPING_METHOD_ID}:`)
			if (!isOurMethod) {
				continue
			}

			// sub_type 由後端在 rate meta_data 帶回（key: sub_type）；無則視為需選店
			const subTypeMeta = (rate.meta_data ?? []).find(
				(m) => m.key === 'sub_type'
			)
			const subType = subTypeMeta?.value ?? ''
			if (subType && !CVS_SUB_TYPES.includes(subType as never)) {
				// 明確為宅配（HOME）等不需選店的子類型
				return null
			}
			return subType || CVS_SUB_TYPES[0]
		}
	}
	return null
}

/**
 * 請求「cart 級」選店頁 HTML（POST cart 級選店端點，session 權杖綁定）
 *
 * 打 settings.store_selection_url（帶 sub_type + X-WP-Nonce），後端產生 session 權杖綁定
 * 當前 cart 並回傳綠界 RWD 選店頁的整頁 HTML（含自動送出表單）。選店回呼以權杖寫入 session，
 * 回到結帳頁時由 cart extensions 同步已選門市。
 *
 * @param subType 選定的超商取貨子類型
 * @return 選店頁整頁 HTML，或 null（端點錯誤 / provider 未啟用）
 */
const requestStoreSelectionHtml = async (
	subType: string
): Promise<string | null> => {
	if (!settings.store_selection_url) {
		return null
	}
	try {
		const resp = await fetch(settings.store_selection_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce ?? '',
			},
			credentials: 'same-origin',
			body: JSON.stringify({ sub_type: subType }),
		})
		const result = (await resp.json()) as {
			code?: string
			data?: { redirect_target?: string }
		}
		if (!resp.ok || result.code !== 'success') {
			return null
		}
		return result.data?.redirect_target ?? null
	} catch {
		return null
	}
}

/**
 * 以綠界回傳的整頁 HTML（含 auto-submit 表單）開啟選店頁
 *
 * @param html 綠界 RedirectToLogisticsSelection 回傳的整頁 HTML
 */
const openSelectionHtml = (html: string): void => {
	document.open()
	document.write(html)
	document.close()
}

/**
 * 觸發 Store API cart 重算，讓 cart.extensions['ecpay_logistics'] 同步最新 session 門市
 *
 * 選店回呼（綠界 RWD 頁 → selection-callback）以權杖把門市寫進 WC session 後，前端的
 * cart 快取仍為舊值；呼叫 extensionCartUpdate（命中本 namespace 的 register_update_callback）
 * 會強制 Store API 重新計算並重新輸出 cart extensions，使 useSelect 訂閱者即時刷新。
 */
const refreshCartExtensions = async (): Promise<void> => {
	try {
		await extensionCartUpdate({ namespace: NAMESPACE, data: {} })
	} catch {
		// 刷新失敗不阻塞；使用者可手動點「重新整理門市」再試
	}
}

/**
 * 「選擇門市」按鈕 + 已選門市顯示（render 進運送方式步驟）
 *
 * @return slot fill 內容元素
 */
const StoreSelector = (): unknown => {
	const [isLoading, setIsLoading] = useState(false)
	const [notice, setNotice] = useState('')

	// 訂閱 wc/store/cart：取得運送方式選擇與 cart extensions（已選門市）
	const { selectedSubType, selectedStore } = useSelect((select) => {
		const store = select('wc/store/cart')
		const cartData = store?.getCartData?.() ?? {}
		return {
			selectedSubType: getSelectedCvsSubType(cartData.shippingRates ?? []),
			selectedStore: readSelectedStore(cartData.extensions),
		}
	}, [])

	// 掛載即刷新 cart extensions：涵蓋「從綠界選店頁導轉回結帳頁」後 cart 快取仍為舊值的情境，
	// 讓選店回呼寫入 session 的門市能即時顯示（無此步驟需手動重整頁面才看得到已選門市）。
	useEffect(() => {
		void refreshCartExtensions()
	}, [])

	// 手動「重新整理門市」：選店頁於新分頁開啟時，回到結帳分頁可手動同步
	const handleRefresh = useCallback(async (): Promise<void> => {
		setNotice('')
		await refreshCartExtensions()
	}, [])

	// 非「綠界超商取貨」運送方式 → 不顯示任何 UI
	if (!selectedSubType) {
		return null
	}

	/** 點擊「選擇門市」：取得綠界 RWD 選店頁 HTML → 覆寫文件開啟選店頁 */
	const handleSelectStore = async (): Promise<void> => {
		setNotice('')
		setIsLoading(true)
		const html = await requestStoreSelectionHtml(selectedSubType)
		setIsLoading(false)
		if (!html) {
			// 取不到選店頁（端點錯誤 / provider 未啟用）→ 以 inline notice 提示，不阻塞
			setNotice(
				__('無法開啟綠界門市選擇頁，請稍後再試或聯繫商家。', 'power_checkout')
			)
			return
		}
		openSelectionHtml(html)
	}

	/** 依狀態決定按鈕文字（避免巢狀三元） */
	const resolveButtonLabel = (): string => {
		if (isLoading) {
			return __('處理中…', 'power_checkout')
		}
		if (selectedStore) {
			return __('重新選擇門市', 'power_checkout')
		}
		return __('選擇門市', 'power_checkout')
	}

	const buttonLabel = resolveButtonLabel()

	return createElement(
		'div',
		{
			className: 'pc-ecpay-logistics-store-selector',
			style: { marginTop: '12px' },
		},
		createElement(
			'button',
			{
				type: 'button',
				className: 'wc-block-components-button',
				disabled: isLoading,
				onClick: handleSelectStore,
				style: {
					padding: '8px 16px',
					borderRadius: '8px',
					cursor: isLoading ? 'default' : 'pointer',
				},
			},
			buttonLabel
		),

		// 「重新整理門市」：選店頁於另一分頁完成時，回到結帳分頁可手動同步 cart extensions
		createElement(
			'button',
			{
				type: 'button',
				className: 'wc-block-components-button',
				onClick: handleRefresh,
				style: {
					marginLeft: '8px',
					padding: '8px 16px',
					borderRadius: '8px',
					cursor: 'pointer',
					background: 'transparent',
					border: '1px solid #ddd',
				},
			},
			__('重新整理門市', 'power_checkout')
		),
		selectedStore
			? createElement(
					'div',
					{
						className: 'pc-ecpay-logistics-selected-store',
						style: { marginTop: '8px', fontSize: '13px', lineHeight: 1.5 },
					},
					createElement(
						'strong',
						null,
						decodeEntities(selectedStore.store_name)
					),
					createElement('br', null),
					createElement(
						'span',
						{ style: { color: '#6b7280' } },
						decodeEntities(selectedStore.store_addr)
					)
				)
			: null,
		notice
			? createElement(
					'div',
					{
						className: 'pc-ecpay-logistics-notice',
						style: { marginTop: '8px', fontSize: '13px', color: '#cc1818' },
						role: 'alert',
					},
					notice
				)
			: null
	)
}

/**
 * Slot fill render：把 StoreSelector 包進 ExperimentalOrderShippingPackages
 *
 * ExperimentalOrderShippingPackages 會 render 進結帳「運送方式」步驟，
 * 其 children 顯示在運送方式清單下方。
 */
const render = (): unknown =>
	createElement(
		Fragment,
		null,
		createElement(
			ExperimentalOrderShippingPackages,
			null,
			createElement(StoreSelector, null)
		)
	)

registerPlugin('pc-ecpay-logistics-store-selection', {
	render,
	scope: 'woocommerce-checkout',
})
