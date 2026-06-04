<?php
/**
 * 綠界站內付 2.0（ECPG）區塊結帳整合
 *
 * 繼承共用 BlocksIntegration，於 block 前端資料（get_payment_method_data）額外帶上站內付 SDK
 * 的「靜態設定」（sdk_url / merchant_id / create_payment_url / is_test / container_id）。
 *
 * 為何 block data 只放靜態設定、不放 token：
 *   站內付 2.0 的交易 token 在 before_process_payment（下單時）才產生，block checkout 在「下單前」
 *   尚無訂單與 token，故逐訂單資料（token / order_key 等）改由 order-received 頁 wp_localize_script
 *   提供（見 EcpgGateway::before_order_received）。block data 僅負責「下單前」就能確定的靜態設定，
 *   讓階段六-C 的 block tsx 先完成 SDK 依賴載入與環境初始化。
 *
 * 靜態設定與 order-received 逐訂單資料同源於 EcpgGateway::build_sdk_config()，避免兩處不一致。
 *
 * @see EcpgGateway::build_sdk_config()
 * @see .claude/rules/react-blocks.rule.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;

if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

/** 綠界站內付 2.0 區塊結帳整合 */
final class EcpgBlocksIntegration extends BlocksIntegration {

	/**
	 * 給前端取得的付款方式資料（共用欄位 + 站內付 SDK 靜態設定）
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return \array_merge(
			parent::get_payment_method_data(),
			[
				'ecpg' => EcpgGateway::build_sdk_config(),
			]
		);
	}
}
