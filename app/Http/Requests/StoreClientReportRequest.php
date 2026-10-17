<?php

namespace App\Http\Requests;

use App\Support\ReportTitle;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('title')) {
            $this->merge(['title' => ReportTitle::clean($this->input('title'))]);
        }
        if (is_string($this->input('body'))) {
            $this->merge(['body' => self::cleanHtml($this->string('body')->toString())]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tooBig = 'الملف أكبر من الحد. صغّر الصور ثم أعد المحاولة.';

        return [
            'document.max' => $tooBig,
            'pdf.max' => $tooBig,
            'attachments.*.max' => $tooBig,
        ];
    }

    /**
     * The report is a Word file edited in the dashboard. `document` is the .docx publishing uploads
     * into the client's current Drive folder. `pdf` may be stored and is not uploaded. `body` is a
     * plain-text extract.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('client_report') === null;

        return [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:200000'],
            'document' => [$creating ? 'required' : 'nullable', 'file', 'max:30720', 'extensions:docx'],
            'pdf' => ['nullable', 'file', 'max:51200', 'extensions:pdf'],
            'publish' => ['sometimes', 'boolean'],
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
