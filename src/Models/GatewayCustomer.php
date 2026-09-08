<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Techork\PaymentService\Laravel\Casts\UuidValueObjectCast;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @property string $id
 * @property GatewayId $gateway_id
 * @property string $customer_reference
 */
final class GatewayCustomer extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'gateway_id' => UuidValueObjectCast::class.':'.GatewayId::class,
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function references(): BelongsToMany
    {
        return $this->belongsToMany(
            GatewayReference::class,
            'gateway_reference_customer',
            'gateway_customer_id',
            'gateway_reference_id',
        );
    }
}
