/**
 * 電子發票 — WC Block Checkout 發票表單（B5）
 *
 * 與付款方式 block（ecpay_aio / ecpay_ecpg）不同：發票資訊非付款方式，故改用官方 slot fill
 * `ExperimentalOrderMeta`（scope: woocommerce-checkout）把發票欄位插入結帳頁「訂單摘要」區。
 * 對應 classic 結帳發票表單（InvoiceApp/Steps）的語意：個人 / 公司統編 / 載具（手機條碼 /
 * 自然人憑證）/ 捐贈碼。
 *
 * cart/session 級串接（後端 BlocksInvoiceIntegration 已就緒）：block 結帳在「下單前」無正式訂單，
 * 故走 cart 級暫存：
 *   - 顧客填寫 → extensionCartUpdate({ namespace: 'pc_invoice', data })，後端
 *     handle_update_callback 以 InvoiceParamsValidator sanitize + validate 後暫存 WC session。
 *   - cart.extensions['pc_invoice'] 同步已填發票參數（後端 ExtendSchema 注入），刷新頁面 / 重整 cart
 *     仍保留已填值。
 *   - 下單時 woocommerce_store_api_checkout_order_processed 把 session 發票參數搬進 order meta
 *     （與 classic 同一個 `_pc_issue_invoice_params` key），下游開立發票邏輯零改動沿用。
 *
 * @see inc/classes/Domains/Invoice/Shared/Services/BlocksInvoiceIntegration.php（後端串接）
 * @see js/src/external/InvoiceApp/Steps/index.vue（classic 對照）
 * @see .claude/rules/react-blocks.rule.md（WP/WC window globals、不加 npm dep）
 */

import {
	ExperimentalOrderMeta,
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

/** 本外掛發票在 WC Block / Store API 的 namespace（對齊後端 BlocksInvoiceIntegration::NAMESPACE） */
const NAMESPACE = 'pc_invoice'

/** 發票服務（後端 get_block_data 提供：已啟用的 amego / ecpay） */
type TProvider = { id: string; title: string }

/** 後端 BlocksInvoiceIntegration::get_block_data() 提供的靜態設定 */
type TInvoiceBlockSettings = {
	/** 已啟用的發票服務清單 */
	providers?: TProvider[]

	/** wp_rest nonce（保留） */
	nonce?: string
}

const settings: TInvoiceBlockSettings = getSetting(`${NAMESPACE}_data`, {})
const providers: TProvider[] = settings.providers ?? []

/** 表單欄位（對齊 classic TFormData） */
type TInvoiceForm = {
	provider: string
	invoiceType: '' | 'individual' | 'company' | 'donate'
	individual: '' | 'cloud' | 'barcode' | 'moica' | 'paper'
	carrier: string
	moica: string
	companyName: string
	companyId: string
	donateCode: string
}

const EMPTY_FORM: TInvoiceForm = {
	provider: providers[0]?.id ?? '',
	invoiceType: 'individual',
	individual: 'cloud',
	carrier: '',
	moica: '',
	companyName: '',
	companyId: '',
	donateCode: '',
}

/** 從 cart extensions 讀回後端暫存的已填發票參數（恢復表單狀態用） */
const readStoredForm = (
	extensions: Record<string, unknown> | undefined
): Partial<TInvoiceForm> | null => {
	const ext = extensions?.[NAMESPACE] as
		| (Partial<TInvoiceForm> & { filled?: boolean })
		| undefined
	if (!ext || !ext.filled) {
		return null
	}
	return ext
}

const CARRIER_PATTERN = /^\/[0-9A-Z+\-.]{7}$/
const MOICA_PATTERN = /^[A-Z]{2}[0-9]{14}$/
const COMPANY_ID_PATTERN = /^[0-9]{8}$/
const DONATE_CODE_PATTERN = /^[0-9]{3,7}$/

/**
 * 前端驗證（與後端 InvoiceParamsValidator 同規則；後端為第二道防線）
 *
 * @param form 表單值
 * @return 錯誤訊息（空字串表示通過）
 */
const validateForm = (form: TInvoiceForm): string => {
	if (!form.provider) {
		return __('請選擇發票服務', 'power_checkout')
	}
	if (form.invoiceType === 'individual') {
		if (form.individual === 'barcode' && !CARRIER_PATTERN.test(form.carrier)) {
			return __('手機條碼載具格式不正確（/開頭共 8 碼）', 'power_checkout')
		}
		if (form.individual === 'moica' && !MOICA_PATTERN.test(form.moica)) {
			return __('自然人憑證格式不正確', 'power_checkout')
		}
		return ''
	}
	if (form.invoiceType === 'company') {
		if (!COMPANY_ID_PATTERN.test(form.companyId)) {
			return __('統一編號需為 8 碼數字', 'power_checkout')
		}
		if (!form.companyName.trim()) {
			return __('請填寫公司名稱', 'power_checkout')
		}
		return ''
	}
	if (form.invoiceType === 'donate') {
		if (!DONATE_CODE_PATTERN.test(form.donateCode)) {
			return __('捐贈碼需為 3~7 碼數字', 'power_checkout')
		}
		return ''
	}
	return __('請選擇發票類型', 'power_checkout')
}

/**
 * 把已驗證的發票參數推到 Store API（後端暫存 session）
 *
 * @param form 表單值
 */
const pushToCart = async (form: TInvoiceForm): Promise<void> => {
	try {
		await extensionCartUpdate({
			namespace: NAMESPACE,
			data: { ...form },
		})
	} catch {
		// 暫存失敗不阻塞結帳；下單前最後一次 onBlur / 再填寫會重試
	}
}

/** 清除後端暫存（取消開立發票） */
const clearCart = async (): Promise<void> => {
	try {
		await extensionCartUpdate({ namespace: NAMESPACE, data: { clear: '1' } })
	} catch {
		// 清除失敗不阻塞結帳
	}
}

/** 小工具：產生受控 input */
const field = (
	label: string,
	value: string,
	onChange: (v: string) => void,
	placeholder = ''
): unknown =>
	createElement(
		'label',
		{
			className: 'pc-invoice-field',
			style: { display: 'block', marginTop: '8px' },
		},
		createElement(
			'span',
			{ style: { display: 'block', fontSize: '13px', marginBottom: '4px' } },
			label
		),
		createElement('input', {
			type: 'text',
			className: 'wc-block-components-text-input',
			value,
			placeholder,
			onChange: (e: { target: { value: string } }) => onChange(e.target.value),
			style: {
				width: '100%',
				padding: '8px',
				borderRadius: '6px',
				border: '1px solid #ddd',
			},
		})
	)

/** 發票表單主元件（render 進 ExperimentalOrderMeta slot） */
const InvoiceForm = (): unknown => {
	const [enabled, setEnabled] = useState(false)
	const [form, setForm] = useState<TInvoiceForm>(EMPTY_FORM)
	const [notice, setNotice] = useState('')

	// 訂閱 cart extensions：恢復已暫存的發票參數（刷新 / 重整 cart）
	const stored = useSelect((select) => {
		const store = select('wc/store/cart')
		const cartData = store?.getCartData?.() ?? {}
		return readStoredForm(cartData.extensions)
	}, [])

	// 後端已有暫存（刷新 / 重整 cart）→ 恢復表單並打開開關。
	// 依 stored 的關鍵欄位為 deps，cart extensions 更新後同步表單狀態。
	useEffect(() => {
		if (stored) {
			setEnabled(true)
			setForm((prev) => ({ ...prev, ...stored }))
		}
	}, [stored?.invoiceType, stored?.companyId, stored?.carrier])

	const update = useCallback((patch: Partial<TInvoiceForm>) => {
		setForm((prev) => ({ ...prev, ...patch }))
	}, [])

	/** 失焦 / 變更時：驗證通過才推到後端暫存 */
	const commit = useCallback((next: TInvoiceForm) => {
		const err = validateForm(next)
		setNotice(err)
		if (!err) {
			void pushToCart(next)
		}
	}, [])

	/** 切換「開立電子發票」開關 */
	const toggleEnabled = (checked: boolean): void => {
		setEnabled(checked)
		if (!checked) {
			setNotice('')
			void clearCart()
		} else {
			commit(form)
		}
	}

	if (providers.length === 0) {
		return null
	}

	const children: unknown[] = [
		createElement(
			'label',
			{
				key: 'toggle',
				style: {
					display: 'flex',
					gap: '8px',
					alignItems: 'center',
					fontWeight: 600,
				},
			},
			createElement('input', {
				type: 'checkbox',
				checked: enabled,
				onChange: (e: { target: { checked: boolean } }) =>
					toggleEnabled(e.target.checked),
			}),
			__('開立電子發票', 'power_checkout')
		),
	]

	if (enabled) {
		// 發票服務（多家才顯示）
		if (providers.length > 1) {
			children.push(
				createElement(
					'label',
					{
						key: 'provider',
						style: { display: 'block', marginTop: '8px' },
					},
					createElement(
						'span',
						{
							style: {
								display: 'block',
								fontSize: '13px',
								marginBottom: '4px',
							},
						},
						__('發票服務', 'power_checkout')
					),
					createElement(
						'select',
						{
							value: form.provider,
							onChange: (e: { target: { value: string } }) => {
								const next = { ...form, provider: e.target.value }
								update({ provider: e.target.value })
								commit(next)
							},
							style: { width: '100%', padding: '8px', borderRadius: '6px' },
						},
						providers.map((p) =>
							createElement(
								'option',
								{ key: p.id, value: p.id },
								decodeEntities(p.title)
							)
						)
					)
				)
			)
		}

		// 發票類型
		children.push(
			createElement(
				'label',
				{ key: 'type', style: { display: 'block', marginTop: '8px' } },
				createElement(
					'span',
					{
						style: {
							display: 'block',
							fontSize: '13px',
							marginBottom: '4px',
						},
					},
					__('發票類型', 'power_checkout')
				),
				createElement(
					'select',
					{
						value: form.invoiceType,
						onChange: (e: { target: { value: string } }) => {
							const next = {
								...form,
								invoiceType: e.target.value as TInvoiceForm['invoiceType'],
							}
							update({
								invoiceType: e.target.value as TInvoiceForm['invoiceType'],
							})
							commit(next)
						},
						style: { width: '100%', padding: '8px', borderRadius: '6px' },
					},
					createElement(
						'option',
						{ value: 'individual' },
						__('個人', 'power_checkout')
					),
					createElement(
						'option',
						{ value: 'company' },
						__('公司', 'power_checkout')
					),
					createElement(
						'option',
						{ value: 'donate' },
						__('捐贈', 'power_checkout')
					)
				)
			)
		)

		// 個人：載具種類
		if (form.invoiceType === 'individual') {
			children.push(
				createElement(
					'label',
					{ key: 'individual', style: { display: 'block', marginTop: '8px' } },
					createElement(
						'span',
						{
							style: {
								display: 'block',
								fontSize: '13px',
								marginBottom: '4px',
							},
						},
						__('載具種類', 'power_checkout')
					),
					createElement(
						'select',
						{
							value: form.individual,
							onChange: (e: { target: { value: string } }) => {
								const next = {
									...form,
									individual: e.target.value as TInvoiceForm['individual'],
								}
								update({
									individual: e.target.value as TInvoiceForm['individual'],
								})
								commit(next)
							},
							style: { width: '100%', padding: '8px', borderRadius: '6px' },
						},
						createElement(
							'option',
							{ value: 'cloud' },
							__('雲端發票', 'power_checkout')
						),
						createElement(
							'option',
							{ value: 'barcode' },
							__('手機條碼', 'power_checkout')
						),
						createElement(
							'option',
							{ value: 'moica' },
							__('自然人憑證', 'power_checkout')
						)
					)
				)
			)

			if (form.individual === 'barcode') {
				children.push(
					createElement(
						Fragment,
						{ key: 'carrier' },
						field(
							__('手機條碼載具', 'power_checkout'),
							form.carrier,
							(v) => update({ carrier: v }),
							'/ABC+123'
						)
					)
				)
			}
			if (form.individual === 'moica') {
				children.push(
					createElement(
						Fragment,
						{ key: 'moica' },
						field(
							__('自然人憑證', 'power_checkout'),
							form.moica,
							(v) => update({ moica: v }),
							'AB12345678901234'
						)
					)
				)
			}
		}

		// 公司：統編 + 公司名稱
		if (form.invoiceType === 'company') {
			children.push(
				createElement(
					Fragment,
					{ key: 'company' },
					field(__('統一編號', 'power_checkout'), form.companyId, (v) =>
						update({ companyId: v })
					),
					field(__('公司名稱', 'power_checkout'), form.companyName, (v) =>
						update({ companyName: v })
					)
				)
			)
		}

		// 捐贈：捐贈碼
		if (form.invoiceType === 'donate') {
			children.push(
				createElement(
					Fragment,
					{ key: 'donate' },
					field(__('愛心碼 / 捐贈碼', 'power_checkout'), form.donateCode, (v) =>
						update({ donateCode: v })
					)
				)
			)
		}

		// 套用按鈕（onBlur 之外的明確提交點）
		children.push(
			createElement(
				'button',
				{
					key: 'apply',
					type: 'button',
					className: 'wc-block-components-button',
					onClick: () => commit(form),
					style: {
						marginTop: '12px',
						padding: '8px 16px',
						borderRadius: '8px',
						cursor: 'pointer',
					},
				},
				__('套用發票資訊', 'power_checkout')
			)
		)

		if (notice) {
			children.push(
				createElement(
					'div',
					{
						key: 'notice',
						role: 'alert',
						style: { marginTop: '8px', fontSize: '13px', color: '#cc1818' },
					},
					notice
				)
			)
		}
	}

	return createElement(
		'div',
		{
			className: 'pc-invoice-form',
			style: {
				marginTop: '16px',
				padding: '16px',
				border: '1px solid #e5e7eb',
				borderRadius: '12px',
			},
		},
		...children
	)
}

/** Slot fill render：把 InvoiceForm 包進 ExperimentalOrderMeta */
const render = (): unknown =>
	createElement(
		Fragment,
		null,
		createElement(ExperimentalOrderMeta, null, createElement(InvoiceForm, null))
	)

registerPlugin('pc-invoice-form', {
	render,
	scope: 'woocommerce-checkout',
})
