<?php
/**
 * PayNow 立吉富（Component SDK，站內收單）區塊結帳整合
 *
 * 繼承共用 BlocksIntegration，於 block 前端資料（get_payment_method_data）額外帶上 PayNow
 * Component SDK 的「靜態設定」（sdk_url / public_key / env / container_id）。
 *
 * 為何 block data 只放靜態設定、不放 secret：
 *   PayNow 的 PaymentIntent secret（result.secret）在 before_process_payment（下單時）才產生，
 *   block checkout 在「下單前」尚無訂單與 secret，故逐訂單資料（secret / order_key 等）改由
 *   order-received 頁 wp_localize_script 提供（見 PaynowGateway::before_order_received）。
 *   block data 僅負責「下單前」就能確定的靜態設定（含 public_key——前端 SDK 初始化用，非機密）。
 *
 * 靜態設定與 order-received 逐訂單資料同源於 PaynowGateway::build_sdk_config()，避免兩處不一致。
 *
 * @see PaynowGateway::build_sdk_config()
 * @see \J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\EcpgBlocksIntegration 範本對照
 * @see .claude/rules/react-blocks.rule.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;

if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

/** PayNow 立吉富區塊結帳整合 */
final class PaynowBlocksIntegration extends BlocksIntegration {

	/**
	 * 給前端取得的付款方式資料（共用欄位 + PayNow Component SDK 靜態設定）
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return \array_merge(
			parent::get_payment_method_data(),
			[
				'paynow' => PaynowGateway::build_sdk_config(),
			]
		);
	}
}
