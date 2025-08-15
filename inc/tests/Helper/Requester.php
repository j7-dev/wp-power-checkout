<?php

declare( strict_types = 1 );

namespace J7\PowerCheckoutTests\Helper;

/**
 * 讀取 JSON 檔案並發送 REST API 請求的輔助類別
 */
class Requester {
    
    /** @var \WP_REST_Request 請求 */
    public \WP_REST_Request $request;
    
    public function __construct( $method = 'GET', $endpoint = '', $content_type = 'application/json' ) {
        $this->request = new \WP_REST_Request( $method, $endpoint );
        $this->request->set_header( 'Content-Type', $content_type );
    }
    
    /**
     * 設定 REST API 請求的主體
     *
     * @param string $file_path JSON 檔案路徑
     *
     * @return $this
     * @throws \JsonException
     */
    public function set_body( string $file_path = '' ): self {
        // 讀取 JSON 檔案內容
        $json_array = \json_decode( \file_get_contents( $file_path ), true, 512, JSON_THROW_ON_ERROR );
        
        // 轉回 JSON 字串（確保是乾淨的 JSON）
        $json = wp_json_encode( $json_array );
        $this->request->set_body( $json );
        return $this;
    }
    
    
    /**
     * 取得 REST API 請求的回應
     *
     * @return \WP_REST_Response
     */
    public function get_response(): \WP_REST_Response {
        // 呼叫 REST server 處理請求
        return \rest_get_server()->dispatch( $this->request );
    }
}
