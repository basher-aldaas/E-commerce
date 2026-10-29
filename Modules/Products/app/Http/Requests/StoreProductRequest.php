<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Validation\Rule;
use App\Http\Requests\ApiFormRequest;

class StoreProductRequest extends ApiFormRequest
{

    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id'),
            ],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
        ];
    }
}
