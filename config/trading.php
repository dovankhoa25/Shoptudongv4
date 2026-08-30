<?php

return [
    'gold_order_pending_timeout_minutes' => (int) env('GOLD_ORDER_PENDING_TIMEOUT_MINUTES', 10),
    'gold_order_refund_grace_minutes' => (int) env('GOLD_ORDER_REFUND_GRACE_MINUTES', 5),
    'gem_order_pending_timeout_minutes' => (int) env('GEM_ORDER_PENDING_TIMEOUT_MINUTES', 10),
    'gem_order_refund_grace_minutes' => (int) env('GEM_ORDER_REFUND_GRACE_MINUTES', 5),
];
