<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLegalPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title_ar' => ['required', 'string', 'max:160'],
            'title_en' => ['required', 'string', 'max:160'],
            'sections' => ['required', 'array', 'min:1', 'max:40'],
            'sections.*.id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sections.*.heading_ar' => ['required', 'string', 'max:200'],
            'sections.*.heading_en' => ['required', 'string', 'max:200'],
            'sections.*.html_ar' => ['required', 'string', 'max:50000'],
            'sections.*.html_en' => ['required', 'string', 'max:50000'],
        ];
    }
}
