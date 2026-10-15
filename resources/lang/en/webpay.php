<?php

return [
    'flow' => [
        'aborted' => 'You cancelled the payment in Webpay.',
        'timeout' => 'The payment expired after inactivity in the Webpay form.',
        'error' => 'An error occurred in the Webpay payment form.',
    ],
    'response_codes' => [
        '-1' => 'Transaction rejected.',
        '-2' => 'The transaction must be retried.',
        '-3' => 'Transaction error.',
        '-4' => 'Transaction rejected.',
        '-5' => 'Rejected because of a rate error.',
        '-6' => 'Exceeds the monthly limit.',
        '-7' => 'Exceeds the daily limit per transaction.',
        '-8' => 'Merchant category not authorized.',
    ],
    'payment_types' => [
        'VD' => 'Debit sale',
        'VN' => 'Normal sale',
        'VC' => 'Installment sale',
        'SI' => '3 interest-free installments',
        'S2' => '2 interest-free installments',
        'NC' => 'N interest-free installments',
        'VP' => 'Prepaid sale',
    ],
];
