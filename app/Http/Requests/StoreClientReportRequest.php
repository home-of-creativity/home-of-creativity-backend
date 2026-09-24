<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => self::cleanHtml($this->string('body')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'header' => ['nullable', 'string', 'max:200'],
            'footer' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:200000'],
            'cover' => ['nullable', 'image', 'max:5120'],
            'attachments' => ['sometimes', 'array', 'max:12'],
            'attachments.*' => ['file', 'max:20480'],
            'remove_attachment_ids' => ['sometimes', 'array'],
            'remove_attachment_ids.*' => ['integer'],
        ];
    }

    public static function cleanHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        return trim($html);
    }
}
