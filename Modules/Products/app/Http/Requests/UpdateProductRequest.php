<?php

namespace Modules\Products\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/products/{id}: the product's full editable representation, with the
 * same rules as creating one. `image` and `is_active` are optional: without an
 * image the current one is kept, without is_active the product stays as it is.
 * Stock is not part of a product here; the Warehouse owns it.
 *
 * A file can't be sent with a real PUT (PHP only parses multipart bodies on
 * POST), so a new image is sent as POST with `_method=PUT`.
 */
class UpdateProductRequest extends ApiFormRequest
{
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
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }
}
