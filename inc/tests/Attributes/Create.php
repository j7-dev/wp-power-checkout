<?php

namespace J7\PowerCheckoutTests\Attributes;

#[\Attribute( \Attribute::TARGET_CLASS )]
class Create {
    public function __construct( string ...$resources ) {}
}