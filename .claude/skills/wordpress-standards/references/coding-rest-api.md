# REST API 開發規範

## 路由注冊標準

```php
\add_action( 'rest_api_init', function (): void {
    \register_rest_route(
        'my-plugin/v1',
        '/products/(?P<id>\d+)',
        [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_product' ],
            'permission_callback' => function (): bool {
                return \current_user_can( 'read' );
            },
            'args'                => [
                'id' => [
                    'validate_callback' => fn( $param ) => is_numeric( $param ),
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]
    );
} );
```

## REST 回應格式

```php
// ✅ 成功回應
return new \WP_REST_Response( $data, 200 );

// ✅ 錯誤回應
return new \WP_Error(
    'product_not_found',
    \__( '商品不存在', 'my-plugin' ),
    [ 'status' => 404 ]
);
```
