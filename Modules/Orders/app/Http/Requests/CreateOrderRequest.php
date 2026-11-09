<?php

namespace Modules\Orders\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],

            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id'),
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.WarehouseGateway::MAX_QUANTITY,
            ],
        ];
    }
}
