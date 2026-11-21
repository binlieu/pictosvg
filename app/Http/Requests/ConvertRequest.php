<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the conversion id + token (ConversionStore::authorize)
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'preset' => ['sometimes', 'in:engrave,outline,lineart'],
            'detail' => ['sometimes', 'integer', 'between:1,10'],
            'smoothing' => ['sometimes', 'integer', 'between:0,10'],
            'noise' => ['sometimes', 'integer', 'between:0,10'],
            'smooth_curves' => ['sometimes', 'boolean'],
            'width' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'unit' => ['sometimes', 'in:mm,in'],
            'threshold' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'contrast' => ['sometimes', 'integer', 'between:-50,50'],
            'invert' => ['sometimes', 'boolean'],
            'tolerance' => ['sometimes', 'nullable', 'numeric', 'between:0,5'],
            'speck' => ['sometimes', 'nullable', 'integer', 'between:0,1000'],
            'corners' => ['sometimes', 'nullable', 'integer', 'between:0,10'],
            'outline_only' => ['sometimes', 'boolean'],
            'transparent_background' => ['sometimes', 'boolean'],
        ];
    }
}
