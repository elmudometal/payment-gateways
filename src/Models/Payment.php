<?php

namespace Arca\PaymentGateways\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    const ESTATUS_PAGADA = 'Pagada';

    const ESTATUS_PENDIENTE = 'Pendiente';

    const ESTATUS_CANCELADA = 'Cancelada';

    const ESTATUS_REVERSADA = 'Reversada';

    protected $fillable = [
        'uuid',
        'token',
        'authorization_code',
        'status',
        'amount',
        'comments',
        'voucher',
    ];

    protected $attributes = [
        'status' => self::ESTATUS_PENDIENTE,
    ];

    protected $casts = [
        'voucher' => 'json',
    ];

    //    protected $dispatchesEvents = [
    //        'updated' => OrderApproved::class,
    //    ];

    protected static function newFactory()
    {
        $package = Str::before(get_called_class(), 'Models\\');
        $modelName = Str::after(get_called_class(), 'Models\\');
        $path = $package.'Database\\Factories\\'.$modelName.'Factory';

        return $path::new();
    }
}
