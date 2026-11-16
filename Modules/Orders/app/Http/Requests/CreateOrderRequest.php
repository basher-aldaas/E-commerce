<?php

namespace Modules\Orders\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Services\Warehouse\WarehouseGateway;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends ApiFormRequest
{
    /**
     * The Idempotency-Key header is validated with the body: without it a retry
     * (after a timeout or a lost reply) can't be told apart from a new order.
     */
    public function validationData(): array
    {
        return parent::validationData() + ['Idempotency-Key' => $this->header('Idempotency-Key')];
    }

    public function rules(): array
    {
        return [
            'Idempotency-Key' => ['required', 'string', 'max:255'],

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

    public function messages(): array
    {
        return [
            'Idempotency-Key.required' => 'The Idempotency-Key header is required.',
            'Idempotency-Key.string' => 'The Idempotency-Key header must be a string.',
            'Idempotency-Key.max' => 'The Idempotency-Key header may not be longer than 255 characters.',
        ];
    }
}
