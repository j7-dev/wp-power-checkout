<?php

namespace J7\PowerCheckoutTests\Shared;

use J7\PowerCheckoutTests\Contracts\IResource;
use J7\PowerCheckoutTests\Utils\STDOUT;

abstract class Resource implements IResource {
    
    /**
     * @var string 資源標籤
     * 用於輸出日誌時顯示的資源名稱
     */
    protected string $label = '資源';
    
    /**
     * @var array<object> 資源項目
     */
    protected array $items = [];
    
    
    /**
     * @inheritDoc
     */
    public function get_item( int|string $index_or_type = 'random' ): ?object {
        if (\is_numeric($index_or_type)) {
            return $this->items[ $index_or_type ];
        }
        return $this->items[ array_rand($this->items) ] ?? null;
    }
    
    /**
     * @inheritDoc
     */
    public function get_items(): array {
        return $this->items;
    }
    
    /**
     * 取得資源項目的 ID
     *
     * @return array<int> 資源項目的 ID
     */
    public function get_item_ids(): array {
        $ids = [];
        foreach ($this->items as $item) {
            if (method_exists($item, 'get_id')) {
                $ids[] = $item->get_id();
            } elseif (method_exists($item, 'ID')) {
                $ids[] = $item->ID;
            }
        }
        return $ids;
    }
    
    /**
     * @inheritDoc
     */
    public function tear_down(): void {
        global $wpdb;
        // START TRANSACTION
        $wpdb->query('START TRANSACTION');
        try {
            $count = count($this->items);
            $ids   = $this->get_item_ids();
            foreach ($this->items as $item) {
                if(method_exists($item, 'delete')) {
                    $item->delete(true);
                    continue;
                }
                
                if(method_exists($item, 'tear_down')) {
                    $item->tear_down();
                }
            }
            
            $this->items = [];
            // COMMIT
            $wpdb->query('COMMIT');
            STDOUT::ok("刪除 {$count} 個{$this->label}成功: " . implode(', ', $ids));
        } catch (\Exception $e) {
            // ROLLBACK
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}