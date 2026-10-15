<?php

return [
    'status' => [
        'pending' => 'Pending',
        'authorized' => 'Authorized',
        'paid' => 'Paid',
        'failed' => 'Rejected',
        'refunded' => 'Refunded',
        'reversed' => 'Reversed',
    ],
    'success_title' => 'Payment successful',
    'pending_title' => 'Payment pending',
    'pending_text' => 'We are waiting for the payment method to confirm the payment. We will let you know once it is credited.',
    'pending_refresh' => 'Check again',
    'failed_title' => 'Payment rejected',
    'redirecting' => 'Taking you to the payment form…',
    'amount' => 'Amount',
    'authorization_code' => 'Authorization code',
    'reference' => 'Order',
    'reason' => 'Reason',
    'description' => 'Description',
    'retry' => 'Try again',
    'gateway_error' => 'We could not reach the payment provider. Please try again in a few minutes.',
    'cancelled' => 'You cancelled the payment.',
    'unknown_error' => 'The payment provider rejected the transaction.',
];
