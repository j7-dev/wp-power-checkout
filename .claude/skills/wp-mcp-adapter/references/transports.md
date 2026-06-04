# Transport 層

## 目錄
- [HttpTransport（推薦）](#httptransport推薦)
- [STDIO Transport（WP-CLI）](#stdio-transportwp-cli)
- [Transport Permission Callbacks](#transport-permission-callbacks)
- [自訂 Transport](#自訂-transport)

---

## HttpTransport（推薦）

**Namespace**: `WP\MCP\Transport\HttpTransport`
**實作**: MCP 2025-06-18 / 2025-11-25 HTTP transport spec（POST + sessions）

### 使用方式

```php
use WP\MCP\Transport\HttpTransport;

$adapter->create_server(
    'my-server',
    'my-plugin',    // REST namespace
    'mcp',          // REST route
    // ...
    mcp_transports: [ HttpTransport::class ],
);
// REST endpoint: POST /wp-json/my-plugin/mcp
```

### 路由與方法

```
POST   /wp-json/{namespace}/{route}   → 接收 JSON-RPC 請求（主要方法）
GET    /wp-json/{namespace}/{route}   → 保留（未來 SSE 用，目前回 405）
DELETE /wp-json/{namespace}/{route}   → Session 終止
```

### 預設 Permission 行為

無 `$transport_permission_callback` 時，使用 WordPress capability 檢查：

```php
// 預設 capability 為 'read'，可透過 filter 覆寫
add_filter( 'mcp_adapter_default_transport_permission_user_capability',
    fn(): string => 'edit_posts' );
```

- 失敗的 permission 檢查會寫入 error log
- WP_Error 回傳 → 拒絕存取（fail-closed）
- Exception → 記錄並拒絕存取（fail-closed）

---

## STDIO Transport（WP-CLI）

透過 WP-CLI 以標準輸入/輸出進行 JSON-RPC 2.0 通訊，適合本機開發與 IDE 整合。

### 命令

```bash
# 列出所有已註冊 server
wp mcp-adapter list [--format=table|json|csv|yaml]

# 啟動 STDIO server
wp mcp-adapter serve [--server=<server-id>] [--user=<id|login|email>]

# 以 admin 啟動 default server
wp mcp-adapter serve --user=admin

# 以 user ID 1 啟動指定 server
wp mcp-adapter serve --server=my-plugin-server --user=1

# 未指定 user = unauthenticated（能力受限）
wp mcp-adapter serve --server=public-server
```

### 測試範例

```bash
# 列出工具
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' \
  | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# 呼叫 discover-abilities
echo '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' \
  | wp mcp-adapter serve --user=admin

# 除錯模式
wp mcp-adapter serve --user=admin --debug
```

### 錯誤訊息

| 錯誤 | 原因 |
|------|------|
| `Server with ID 'x' not found.` | `--server` 參數指定的 ID 不存在 |
| `Invalid user ID, email or login:'x'` | `--user` 參數無效 |
| `No MCP servers available.` | 尚未在 `mcp_adapter_init` 中建立任何 server |

---

## Transport Permission Callbacks

作為 `create_server()` 最後一個參數傳入，用於整個 server 層級的認證。

### 基本用法

```php
// 不傳 = 使用 is_user_logged_in()
$adapter->create_server( /* ... */ );

// Admin-only
$adapter->create_server(
    // ... 其他參數 ...
    transport_permission_callback: function (): bool {
        return current_user_can( 'manage_options' );
    }
);
```

### 回傳型別

```php
// 簡單 bool
function (): bool { return current_user_can( 'edit_posts' ); }

// WP_Error（含 HTTP status）
function (): \WP_Error|bool {
    if ( ! is_user_logged_in() ) {
        return new \WP_Error( 'not_logged_in', 'Please log in', [ 'status' => 401 ] );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return new \WP_Error( 'forbidden', 'Admin access required', [ 'status' => 403 ] );
    }
    return true;
}
```

### 常用模式

```php
// 基於 Role
fn(): bool => current_user_can( 'edit_posts' );

// API Key 認證（HttpTransport 傳入 WP_REST_Request）
function ( \WP_REST_Request $request ): \WP_Error|bool {
    $api_key    = $request->get_header( 'X-API-Key' );
    $valid_keys = get_option( 'my_plugin_api_keys', [] );

    if ( empty( $api_key ) ) {
        return new \WP_Error( 'missing_key', 'API key required', [ 'status' => 401 ] );
    }
    return in_array( $api_key, $valid_keys, true )
        ?: new \WP_Error( 'invalid_key', 'Invalid API key', [ 'status' => 403 ] );
}

// Application Passwords（WordPress 6.2+ 支援）
function (): \WP_Error|bool {
    if ( ! is_user_logged_in() ) {
        return new \WP_Error( 'unauthenticated', 'Authentication required', [ 'status' => 401 ] );
    }
    return current_user_can( 'edit_posts' );
}
```

**注意**：
- Exception → 自動記錄並拒絕（fail-closed）
- 建議設為 server 上所有 ability 所需的最寬鬆 capability，由各 ability 的 `permission_callback` 細控

---

## 自訂 Transport

### 何時需要自訂 Transport

優先考慮 Transport Permission Callback（認證）。只有以下情境才需要自訂 transport：
- 自訂路由結構或 URL 模式
- Message queue 整合（Redis、RabbitMQ、AWS SQS）
- Request 簽名 / 加密
- 特殊協定

### Transport 介面

```php
// 基礎介面
interface McpTransportInterface {
    public function __construct( McpTransportContext $context );
    public function register_routes(): void;
}

// REST 專用介面（多數情況使用這個）
interface McpRestTransportInterface extends McpTransportInterface {
    public function check_permission( \WP_REST_Request $request );
    public function handle_request( \WP_REST_Request $request ): \WP_REST_Response;
}
```

### 自訂 REST Transport 範例

```php
use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class MyCustomTransport implements McpRestTransportInterface {
    use McpTransportHelperTrait;  // 提供 get_transport_name() 等

    private McpTransportContext $context;

    public function __construct( McpTransportContext $context ) {
        $this->context = $context;
        add_action( 'rest_api_init', [ $this, 'register_routes' ], 16 );
    }

    public function register_routes(): void {
        $server = $this->context->mcp_server;

        register_rest_route(
            $server->get_server_route_namespace(),
            $server->get_server_route(),
            [
                'methods'             => [ 'POST', 'GET', 'DELETE' ],
                'callback'            => [ $this, 'handle_request' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        );
    }

    public function check_permission( \WP_REST_Request $request ) {
        $api_key    = $request->get_header( 'X-API-Key' );
        $valid_keys = get_option( 'mcp_api_keys', [] );
        return ! empty( $api_key ) && in_array( $api_key, $valid_keys, true );
    }

    public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();

        // 透過 request router 處理 MCP 方法
        $result = $this->context->request_router->route_request(
            $body['method']  ?? '',
            $body['params']  ?? [],
            $body['id']      ?? 0,
            $this->get_transport_name()  // McpTransportHelperTrait 提供
        );

        return rest_ensure_response( $result );
    }
}

// 使用自訂 transport
add_action( 'mcp_adapter_init', function ( $adapter ): void {
    $adapter->create_server(
        'secure-server',
        'my-plugin',
        'secure-mcp',
        'Secure Server',
        'API key protected server',
        'v1.0.0',
        [ MyCustomTransport::class ],  // 使用自訂 transport
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        null,
        [ 'my-plugin/secure-tool' ]
    );
} );
```

### McpTransportContext 可用屬性

```php
$this->context->mcp_server          // McpServer 實例
$this->context->request_router      // RequestRouter
$this->context->error_handler        // McpErrorHandlerInterface
$this->context->observability_handler // McpObservabilityHandlerInterface
$this->context->transport_permission_callback  // ?callable
```
