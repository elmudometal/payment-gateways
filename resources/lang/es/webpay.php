<?php

return [
    'flow' => [
        'aborted' => 'Cancelaste el pago en Webpay.',
        'timeout' => 'El pago expiró por inactividad en el formulario de Webpay.',
        'error' => 'Ocurrió un error en el formulario de pago de Webpay.',
    ],
    'response_codes' => [
        '-1' => 'Rechazo de transacción.',
        '-2' => 'La transacción debe reintentarse.',
        '-3' => 'Error en la transacción.',
        '-4' => 'Rechazo de transacción.',
        '-5' => 'Rechazo por error de tasa.',
        '-6' => 'Excede el cupo máximo mensual.',
        '-7' => 'Excede el límite diario por transacción.',
        '-8' => 'Rubro no autorizado.',
    ],
    'payment_types' => [
        'VD' => 'Venta débito',
        'VN' => 'Venta normal',
        'VC' => 'Venta en cuotas',
        'SI' => '3 cuotas sin interés',
        'S2' => '2 cuotas sin interés',
        'NC' => 'N cuotas sin interés',
        'VP' => 'Venta prepago',
    ],
];
