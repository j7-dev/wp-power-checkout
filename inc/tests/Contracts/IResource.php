<?php

namespace J7\PowerCheckoutTests\Contracts;

interface IResource {
    
    /**
     * 建立資源
     *
     * @param array $args 資源參數
     * @param int   $qty  資源數量
     *
     * @return self
     */
    public function create( array $args = [], int $qty = 1 ): self;
    
    /**
     * 取得一個資源
     *
     * @param string|int $index_or_type 資源索引或類型
     */
    public function get_item( string|int $index_or_type = 'random' ): ?object;
    
    /**
     * 取得資源的陣列
     *
     * @return array 資源物件
     */
    public function get_items(): array;
    
    /**
     * 清除資源
     *
     * @return void
     */
    public function tear_down(): void;
}