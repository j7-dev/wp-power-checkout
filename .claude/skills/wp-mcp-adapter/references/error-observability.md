# Error Handling & Observability

## 目錄
- [Error Handling 架構](#error-handling-架構)
- [McpErrorFactory — 錯誤碼與方法](#mcperrorfactory--錯誤碼與方法)
- [內建 Error Handlers](#內建-error-handlers)
- [自訂 Error Handler](#自訂-error-handler)
- [Observability Handler](#observability-handler)
- [v0.5.0 Breaking Change：DTO 回傳值](#v050-breaking-changeDTO-回傳值)

---

## Error Handling 架構

MCP Adapter 的 error handling 分兩部分：

| 元件 | 職責 |
|------|------|
| `McpErrorHandlerInterface` | **記錄** 錯誤（logging/monitoring） |
| `McpErrorFactory` | **產生** JSON-RPC 2.0 錯誤回應 DTO |

---

## McpErrorFactory — 錯誤碼與方法

**Namespace**: `WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory`

### 錯誤碼常數

```php
// Standard JSON-RPC codes
McpErrorFactory::PARSE_ERROR       // -32700
McpErrorFactory::INVALID_REQUEST   // -32600
McpErrorFactory::METHOD_NOT_FOUND  // -32601
McpErrorFactory::INVALID_PARAMS    // -32602
McpErrorFactory::INTERNAL_ERROR    // -32603

// MCP-specific codes
McpErrorFactory::SERVER_ERROR       // -32000 (含 MCP disabled)
McpErrorFactory::TIMEOUT_ERROR      // -32001
McpErrorFactory::RESOURCE_NOT_FOUND // -32002
McpErrorFactory::TOOL_NOT_FOUND     // -32003
McpErrorFactory::PROMPT_NOT_FOUND   // -32004
McpErrorFactory::PERMISSION_DENIED  // -32008
McpErrorFactory::UNAUTHORIZED       // -32010
```

### 所有工廠方法（v0.5.0 回傳 JSONRPCErrorResponse DTO）

```php
// Standard JSON-RPC
McpErrorFactory::parse_error( int $id, string $details = '' ): JSONRPCErrorResponse
McpErrorFactory::invalid_request( int $id, string $details = '' ): JSONRPCErrorResponse
McpErrorFactory::method_not_found( int $id, string $method ): JSONRPCErrorResponse
McpErrorFactory::invalid_params( int $id, string $details = '' ): JSONRPCErrorResponse
McpErrorFactory::internal_error( int $id, string $details = '' ): JSONRPCErrorResponse

// MCP-specific
McpErrorFactory::missing_parameter( int $id, string $parameter ): JSONRPCErrorResponse
McpErrorFactory::tool_not_found( int $id, string $tool ): JSONRPCErrorResponse
McpErrorFactory::ability_not_found( int $id, string $ability ): JSONRPCErrorResponse
McpErrorFactory::resource_not_found( int $id, string $resource_uri ): JSONRPCErrorResponse
McpErrorFactory::prompt_not_found( int $id, string $prompt ): JSONRPCErrorResponse
McpErrorFactory::permission_denied( int $id, string $details = '' ): JSONRPCErrorResponse
McpErrorFactory::unauthorized( int $id, string $details = '' ): JSONRPCErrorResponse
McpErrorFactory::mcp_disabled( int $id ): JSONRPCErrorResponse
McpErrorFactory::validation_error( int $id, string $details ): JSONRPCErrorResponse
```

### 在自訂 transport 中使用

```php
// v0.5.0 後需要 ->toArray()
$error = McpErrorFactory::unauthorized( $request_id );
return new \WP_REST_Response( $error->toArray(), 401 );

// HTTP status 對應
$http_status = McpErrorFactory::get_http_status_for_error( $error );
// 同時接受 DTO 和 legacy array（向後相容）

// 直接 code → HTTP status
McpErrorFactory::mcp_error_to_http_status( -32003 );  // 404
```

### JSON-RPC Validation

```php
$validation = McpErrorFactory::validate_jsonrpc_message( $request_body );
if ( is_array( $validation ) ) {
    return new \WP_REST_Response( $validation, 400 ); // validation 失敗
}
// validation 通過，繼續處理
```

---

## 內建 Error Handlers

### ErrorLogMcpErrorHandler（預設）

記錄到 PHP error log，格式：`[ERROR] Message | Context: {...} | User ID: 123`

```php
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;

// 在 create_server() 中指定：
$adapter->create_server(
    // ...
    error_handler: ErrorLogMcpErrorHandler::class,
);
```

### NullMcpErrorHandler

靜默忽略所有錯誤（測試或停用 logging 時使用）：

```php
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;

$adapter->create_server(
    // ...
    error_handler: NullMcpErrorHandler::class,
);
```

---

## 自訂 Error Handler

實作 `McpErrorHandlerInterface`：

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

interface McpErrorHandlerInterface {
    public function log(
        string $message,
        array  $context = [],  // 額外資料（tool name、user ID 等）
        string $type    = 'error'  // 'error' | 'info' | 'debug'
    ): void;
}
```

### 檔案式 Handler

```php
class FileErrorHandler implements McpErrorHandlerInterface {
    public function log( string $message, array $context = [], string $type = 'error' ): void {
        $entry = sprintf(
            '[%s] %s | Context: %s' . PHP_EOL,
            strtoupper( $type ),
            $message,
            wp_json_encode( $context )
        );
        file_put_contents( WP_CONTENT_DIR . '/mcp-errors.log', $entry, FILE_APPEND | LOCK_EX );
    }
}

$adapter->create_server( /* ... */ error_handler: FileErrorHandler::class );
```

### 外部服務 Handler（含 fallback）

```php
class SentryMcpErrorHandler implements McpErrorHandlerInterface {
    public function log( string $message, array $context = [], string $type = 'error' ): void {
        try {
            \Sentry\captureMessage( $message, \Sentry\Severity::fromError( $type ) );
        } catch ( \Throwable $e ) {
            error_log( "[MCP {$type}] {$message}" );  // fallback
        }
    }
}
```

---

## Observability Handler

### 介面

```php
interface McpObservabilityHandlerInterface {
    public function record_event(
        string  $event,               // e.g. 'mcp.server.created', 'mcp.component.registration'
        array   $tags        = [],    // key-value 標籤
        ?float  $duration_ms = null   // 選填，執行時間（毫秒）
    ): void;
}
```

### 內建實作

```php
NullMcpObservabilityHandler::class   // 靜默（預設）
ErrorLogMcpObservabilityHandler::class // 記錄到 PHP error log
ConsoleObservabilityHandler::class    // 輸出到 console
```

### 自訂 Observability Handler

```php
class DatadogObservabilityHandler implements McpObservabilityHandlerInterface {
    public function record_event( string $event, array $tags = [], ?float $duration_ms = null ): void {
        $metric_tags = array_map(
            fn( $k, $v ) => "{$k}:{$v}",
            array_keys( $tags ),
            $tags
        );

        if ( null !== $duration_ms ) {
            \DogStatsd::timing( "mcp.{$event}", $duration_ms, 1, $metric_tags );
        } else {
            \DogStatsd::increment( "mcp.{$event}", 1, $metric_tags );
        }
    }
}
```

### 系統記錄的事件

| Event | 觸發時機 | 常見 tags |
|-------|---------|----------|
| `mcp.server.created` | server 建立成功 | `server_id`, `transport_count`, `tools_count` |
| `mcp.component.registration` | component 註冊（需 filter 啟用） | `component_type`, `component_name`, `status` |

**啟用 component 追蹤：**
```php
add_filter( 'mcp_adapter_observability_record_component_registration', '__return_true' );
```

---

## FailureReason 常數（v0.5.0）

**Namespace**: `WP\MCP\Infrastructure\Observability\FailureReason`

```php
// 註冊失敗
FailureReason::ABILITY_NOT_FOUND           // 'ability_not_found'
FailureReason::DUPLICATE_URI               // 'duplicate_uri'
FailureReason::BUILDER_EXCEPTION           // 'builder_exception'
FailureReason::NO_PERMISSION_STRATEGY      // 'no_permission_strategy'
FailureReason::ABILITY_CONVERSION_FAILED   // 'ability_conversion_failed'

// Permission 失敗
FailureReason::PERMISSION_DENIED           // 'permission_denied'
FailureReason::PERMISSION_CHECK_FAILED     // 'permission_check_failed'

// 執行失敗
FailureReason::NOT_FOUND                   // 'not_found'
FailureReason::EXECUTION_FAILED            // 'execution_failed'
FailureReason::EXECUTION_EXCEPTION         // 'execution_exception'

// 驗證失敗
FailureReason::MISSING_PARAMETER           // 'missing_parameter'
FailureReason::INVALID_PARAMETER           // 'invalid_parameter'

FailureReason::all(): array     // 所有有效值
FailureReason::is_valid( string $value ): bool
```

---

## v0.5.0 Breaking Change：DTO 回傳值

**只有自訂 handler 或直接呼叫 McpErrorFactory 的程式碼需要注意。**

### McpErrorFactory 現在回傳 DTO

```php
// Before (v0.4.x)
$error = McpErrorFactory::unauthorized( $id );
return new \WP_REST_Response( $error, 401 );  // $error 是 array

// After (v0.5.0)
$error = McpErrorFactory::unauthorized( $id );
return new \WP_REST_Response( $error->toArray(), 401 );  // $error 是 DTO

// 或讀取 error code：
$error->getError()->getCode();  // 不再是 $error['error']['code']
```

### get_http_status_for_error() 同時接受 DTO 和 array（向後相容）

```php
McpErrorFactory::get_http_status_for_error( $dto_or_array );  // 兩者都接受
```
