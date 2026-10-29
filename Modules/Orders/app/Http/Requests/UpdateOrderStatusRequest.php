<?php

namespace Modules\Orders\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    'pending',
                    'confirmed',
                    'processing',
                    'completed',
                    'cancelled',
                ]),
            ],
        ];
    }
}
