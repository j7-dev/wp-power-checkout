# WordPress Hook 系統

## 提供擴展點

```php
// ✅ 正確的做法：提供 action 和 filter 擴展點
/**
 * 處理表單提交
 *
 * @param SubmissionDTO $dto 提交資料
 *
 * @return void
 */
public function process_submission( SubmissionDTO $dto ): void {
    /**
     * 提交前的 action hook
     *
     * @param SubmissionDTO $dto 提交資料
     */
    \do_action( 'my_plugin_before_submission', $dto );

    /**
     * 過濾提交資料
     *
     * @param SubmissionDTO $dto 提交資料
     */
    $dto = \apply_filters( 'my_plugin_submission_data', $dto );

    $this->save_to_database( $dto );
    $this->send_email( $dto );

    /**
     * 提交後的 action hook
     *
     * @param SubmissionDTO $dto 提交資料
     */
    \do_action( 'my_plugin_after_submission', $dto );
}
```

## Hook 名稱慣例

```
{plugin_prefix}_{動作/狀態}
{plugin_prefix}_{物件}_{動作}

範例：
my_plugin_before_order_created
my_plugin_order_status_changed
my_plugin_filter_product_price
```
