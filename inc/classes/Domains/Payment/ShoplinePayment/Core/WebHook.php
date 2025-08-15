<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Settings;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Body;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Session;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\EventType;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;

/**
 * WebHooks 用來接收 Shopline 的 WebHooks 通知
 *
 * @see https://docs.shoplinepayments.com/api/event/model/session/
 */
final class WebHook extends ApiBase {
    use \J7\WpUtils\Traits\SingletonTrait;
    
    /** @var string Namespace power-checkout/{payment_gateway} */
    protected $namespace = 'power-checkout/slp';
    
    /**
     * APIs
     *
     * @var array<array{
     *  endpoint: string,
     *  method: 'get' | 'post' | 'patch' | 'delete',
     *  permission_callback?: callable,
     *  callback?: callable,
     *  schema?: array|null
     * }> $apis API 列表
     */
    protected $apis = [
        [
            'endpoint'            => 'webhook',
            'method'              => 'post',
            'permission_callback' => '__return_true',
        ],
    ];
    
    
    /**
     * 結帳交易 WebHooks 通知
     * 回 http.statusCode 200 通知 SLP 處理成功，不再通知
     * 非200：通知 SLP 失敗，等待下一次通知
     * 收到通知就始終回 200 ，不用讓 SLP 重試
     *
     * @param \WP_REST_Request $request 請求
     *
     * @return \WP_REST_Response 回應
     */
    public function post_webhook_callback( \WP_REST_Request $request ): \WP_REST_Response {
        $is_valid = $this->is_valid( $request );
        $body_params = $request->get_params();
        
        // TEST ----- ▼ 印出 WC Logger 記得移除 ----- //
        Plugin::logger(
            'body_params', 'info', [
                             'body_params' => $body_params,
                         ]
        );
        // TEST ---------- END ---------- //
        try {
            $webhook_dto = Body::create( $body_params );
            $event_type = EventType::from( $webhook_dto->type );
            
            match ( $event_type ) {
                EventType::SESSION_CREATED, EventType::SESSION_EXPIRED, EventType::SESSION_PENDING, EventType::SESSION_SUCCEEDED => $this->handle_session(
                    $webhook_dto
                ),
                
            };
            
            // 收到通知就始終回 200 ，不用讓 SLP 重試
            return new \WP_REST_Response( null, 200 );
        }
        catch ( \Exception $e ) {
            Plugin::logger(
                'WebHook 處理失敗', 'error', [
                                      'error'    => $e->getMessage(),
                                      'is_valid' => $is_valid ? 'true' : 'false',
                                      'params'   => $body_params,
                                  ]
            );
            // 收到通知就始終回 200 ，不用讓 SLP 重試
            return new \WP_REST_Response( null, 200 );
        }
    }
    
    /**
     * 驗證簽章
     *
     * @param \WP_REST_Request $request 請求
     *
     * @return true 是否驗證成功
     * @throws \Exception 如果驗證失敗
     */
    private function is_valid( \WP_REST_Request $request ): bool {
        if( 'local' === Plugin::$env ) {
            // 本地環境不驗證簽章
            return true;
        }
        
        // 容許的時間誤差
        $diff_tolerance = 5 * 60 * 1000; // 300 seconds = 5 mins
        $timestamp = $request->get_header( 'timestamp' );
        $current_time = time() * 1000;
        $diff_time = abs( $current_time - $timestamp );
        if( $diff_time > $diff_tolerance ) {
            throw new \Exception(
                "Invalid timestamp, current: {$current_time}, received: {$timestamp}, diff: {$diff_time}"
            );
        }
        
        $api_version = $request->get_header( 'apiVersion' );
        if( $api_version !== 'V1' ) {
            \J7\WpUtils\Classes\WC::logger(
                "Shopline Payment WebHooks 版本與預期 V1 不符，回傳 {$api_version}", 'warning'
            );
        }
        
        return $this->verify_hmac_sha256_signature( $request );
    }
    
    /**
     * 驗證簽章
     *
     * @param \WP_REST_Request $request 請求
     *
     * @return true 是否驗證成功
     * @throws \Exception 如果簽章驗證失敗
     */
    private function verify_hmac_sha256_signature( \WP_REST_Request $request ): bool {
        $timestamp = $request->get_header( 'timestamp' );
        $payload = "{$timestamp}.{$request->get_body()}";
        $calculated_signature = $this->generate_hmac_sha256_signature( $payload );
        $sign = $request->get_header( 'sign' );
        $is_verified = hash_equals( $sign, $calculated_signature );
        if( !$is_verified ) {
            throw new \Exception( "Invalid sign, calculated: {$calculated_signature}, actual: {$sign}" );
        }
        return true;
    }
    
    /**
     * 使用 hash_hmac 函數生成 HMAC-SHA256 簽章
     *
     * @param string $payload 要簽名的字串
     *
     * @return string 簽章
     */
    private function generate_hmac_sha256_signature( string $payload ): string {
        // 確保資料是 UTF-8 編碼
        $payload = mb_convert_encoding( $payload, 'UTF-8', 'auto' );
        $sign_key = Settings::instance()->signKey;
        return hash_hmac( 'sha256', $payload, $sign_key );
    }
    
    /**
     * 處理結帳交易
     *
     * @param Body $webhook_dto 網路請求的 body 資料
     *
     * @return void
     * @throws \Exception 如果訂單不存在
     */
    private function handle_session( Body $webhook_dto ): void {
        /** @var Session $data */
        $data = $webhook_dto->data;
        $order_id = $data->referenceId;
        $order = \wc_get_order( $order_id );
        if( !$order ) {
            throw new \Exception( "Order not found: {$order_id}" );
        }
        $event_type = EventType::from( $webhook_dto->type );
        $event_type->update_order_status( $order );
    }
}
