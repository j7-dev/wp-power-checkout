/**
 * 綠界全方位物流 — 傳統結帳（classic checkout）選店按鈕（vanilla JS，無框架依賴）
 *
 * 傳統結帳「下單前」無 WC 訂單，故走 cart 級選店：
 *   1. 偵測是否選用綠界物流運送方式（shipping method id = ecpay_logistics）。
 *   2. 若選用「超商取貨子類型」→ 在運送方式區塊下方注入「選擇門市」按鈕。
 *   3. 點擊 → POST cart 級選店端點（帶 X-WP-Nonce）取得綠界 RWD 選店頁導轉 URL → 導轉。
 *   4. 顧客選店後綠界以權杖回呼寫入 WC session；回到結帳頁後本腳本以已選門市（後端注入）回填顯示。
 *
 * 後端資料來源：window.pcClassicLogistics（wp_localize_script）
 *   - store_selection_url：cart 級選店 REST 端點
 *   - nonce：wp_rest nonce（X-WP-Nonce）
 *   - selected_store：已選門市（{ store_id, store_name, store_addr, sub_type }）或 null
 *   - cvs_sub_types：需選店的超商取貨子類型清單
 *   - sub_type_field：結帳送出的 sub_type 欄位名（_pc_logistics_sub_type）
 *
 * @see inc/classes/Domains/Logistics/Ecpay/Services/WC_EcpayLogisticsShipping.php
 */
;(function () {
	'use strict'

	var data = window.pcClassicLogistics || {}
	var METHOD_ID = 'ecpay_logistics'
	var BUTTON_ID = 'pc-classic-logistics-select-store'
	var INFO_ID = 'pc-classic-logistics-store-info'
	var cvsSubTypes = Array.isArray(data.cvs_sub_types)
		? data.cvs_sub_types
		: ['FAMI', 'UNIMART', 'HILIFE']

	/**
	 * 取得目前選定的綠界物流運送方式 rate_id（形如 ecpay_logistics:FAMI / ecpay_logistics:5:FAMI）
	 * @return {string} 選定的 rate_id，未選用本物流則回空字串
	 */
	function getChosenRateId() {
		var inputs = document.querySelectorAll(
			'input[name^="shipping_method"]:checked, input[name^="shipping_method"][type="hidden"]'
		)
		for (var i = 0; i < inputs.length; i++) {
			var value = String(inputs[i].value)
			if (value.indexOf(METHOD_ID) === 0) {
				return value
			}
		}
		return ''
	}

	/**
	 * 從 rate_id 解析 sub_type 後綴（多 rate：選哪個 rate 決定 sub_type）
	 * rate_id 形如 ecpay_logistics:FAMI 或 ecpay_logistics:5:FAMI（含 instance_id）→ 取最後一段。
	 * @param {string} rateId
	 * @return {string} sub_type，無法解析回空字串
	 */
	function parseSubTypeFromRateId(rateId) {
		var parts = String(rateId).split(':')
		return parts.length > 1 ? parts[parts.length - 1] : ''
	}

	/**
	 * 是否選用了綠界「超商取貨」運送方式（須選店；HOME 宅配不須選店）
	 * @return {boolean}
	 */
	function isLogisticsChosen() {
		var rateId = getChosenRateId()
		if (!rateId) {
			return false
		}
		var subType = getSelectedSubType()
		// 僅超商取貨子類型須顯示選店按鈕（HOME 等不在 cvsSubTypes 內 → 不顯示）
		return cvsSubTypes.indexOf(subType) !== -1
	}

	/**
	 * 取得目前選定的超商取貨子類型
	 *
	 * 優先序：選定 rate_id 後綴（多 rate）→ 頁面 sub_type 欄位（退化）→ 第一個 CVS 子類型。
	 * @return {string}
	 */
	function getSelectedSubType() {
		// 1. 選定的 rate_id 後綴（多 rate：選哪個 rate 即決定 sub_type）
		var fromRate = parseSubTypeFromRateId(getChosenRateId())
		if (fromRate) {
			return fromRate
		}
		// 2. 若頁面有 sub_type 選擇欄位（後端可擴充），讀取
		var field = data.sub_type_field
			? document.querySelector('[name="' + data.sub_type_field + '"]')
			: null
		if (field && field.value) {
			return String(field.value)
		}
		// 3. 退化：第一個 CVS 子類型
		return cvsSubTypes[0]
	}

	/**
	 * 請求 cart 級選店導轉 URL 並導轉
	 * @param {HTMLButtonElement} button
	 */
	function requestAndRedirect(button) {
		if (!data.store_selection_url) {
			return
		}
		button.disabled = true
		var originalText = button.textContent
		button.textContent = data.i18n_loading || '處理中…'

		fetch(data.store_selection_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.nonce || '',
			},
			credentials: 'same-origin',
			body: JSON.stringify({ sub_type: getSelectedSubType() }),
		})
			.then(function (resp) {
				return resp.json().then(function (json) {
					return { ok: resp.ok, json: json }
				})
			})
			.then(function (result) {
				var target =
					result.json &&
					result.json.data &&
					result.json.data.redirect_target
				if (!result.ok || result.json.code !== 'success' || !target) {
					button.disabled = false
					button.textContent = originalText
					showError(
						button,
						data.i18n_error ||
							'無法開啟門市選擇頁，請稍後再試或聯繫商家。'
					)
					return
				}
				// redirect_target 為綠界 RWD 選店頁的 HTML（自動送出表單）；以 document.write 開啟
				openRedirectHtml(String(target))
			})
			.catch(function () {
				button.disabled = false
				button.textContent = originalText
				showError(
					button,
					data.i18n_error || '無法開啟門市選擇頁，請稍後再試或聯繫商家。'
				)
			})
	}

	/**
	 * 以綠界回傳的 HTML（含 auto-submit 表單）開啟選店頁
	 * @param {string} html
	 */
	function openRedirectHtml(html) {
		// 綠界 RedirectToLogisticsSelection 回傳的是「整頁 HTML（含自動送出表單）」，
		// 直接覆寫當前文件以觸發表單送出導向綠界 RWD 選店頁。
		document.open()
		document.write(html)
		document.close()
	}

	/**
	 * 顯示錯誤提示
	 * @param {HTMLElement} anchor
	 * @param {string} message
	 */
	function showError(anchor, message) {
		var existing = document.getElementById('pc-classic-logistics-error')
		if (existing) {
			existing.textContent = message
			return
		}
		var div = document.createElement('div')
		div.id = 'pc-classic-logistics-error'
		div.setAttribute('role', 'alert')
		div.style.cssText = 'margin-top:8px;font-size:13px;color:#cc1818;'
		div.textContent = message
		anchor.insertAdjacentElement('afterend', div)
	}

	/**
	 * 渲染「選擇門市」按鈕 + 已選門市資訊
	 */
	function render() {
		var container = document.querySelector(
			'.woocommerce-checkout-review-order, #order_review'
		)
		if (!container) {
			return
		}

		var shouldShow = isLogisticsChosen()
		var button = document.getElementById(BUTTON_ID)
		var info = document.getElementById(INFO_ID)

		if (!shouldShow) {
			if (button) {
				button.remove()
			}
			if (info) {
				info.remove()
			}
			return
		}

		// 注入按鈕（若尚未存在）
		if (!button) {
			button = document.createElement('button')
			button.id = BUTTON_ID
			button.type = 'button'
			button.className = 'button pc-classic-logistics-button'
			button.style.cssText = 'margin-top:8px;'
			button.addEventListener('click', function () {
				requestAndRedirect(button)
			})
			container.appendChild(button)
		}

		var store = data.selected_store || null
		button.textContent = store
			? data.i18n_reselect || '重新選擇門市'
			: data.i18n_select || '選擇門市'

		// 已選門市資訊
		if (store && store.store_id) {
			if (!info) {
				info = document.createElement('div')
				info.id = INFO_ID
				info.style.cssText =
					'margin-top:8px;font-size:13px;line-height:1.5;'
				button.insertAdjacentElement('afterend', info)
			}
			info.innerHTML =
				'<strong>' +
				escapeHtml(store.store_name || '') +
				'</strong><br><span style="color:#6b7280;">' +
				escapeHtml(store.store_addr || '') +
				'</span>'
		} else if (info) {
			info.remove()
		}
	}

	/**
	 * 基本 HTML 跳脫
	 * @param {string} str
	 * @return {string}
	 */
	function escapeHtml(str) {
		var div = document.createElement('div')
		div.textContent = String(str)
		return div.innerHTML
	}

	// 初次渲染 + 監聽 WC 結帳更新（切換運送方式會觸發 updated_checkout）
	function init() {
		render()
		if (window.jQuery) {
			window
				.jQuery(document.body)
				.on('updated_checkout', render)
				.on('change', 'input[name^="shipping_method"]', function () {
					setTimeout(render, 50)
				})
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
