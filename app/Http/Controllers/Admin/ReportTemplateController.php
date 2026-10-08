<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportTemplateRequest;
use App\Models\ReportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportTemplateController extends Controller
{
    /** Template Word files are private: only staff with ops.report_templates read them through the API. */
    private const DISK = 'local';

    /**
     * Built-in templates are filled in the dashboard (client name and date). Saved rows are Word files.
     *
     * @var list<array{id: string, name_ar: string, name_en: string}>
     */
    public const BUILTIN = [
        ['id' => 'social', 'name_ar' => 'تقرير التواصل الاجتماعي', 'name_en' => 'Social media report'],
        ['id' => 'campaign', 'name_ar' => 'تقرير حملة', 'name_en' => 'Campaign report'],
        ['id' => 'minutes', 'name_ar' => 'محضر اجتماع', 'name_en' => 'Meeting minutes'],
        ['id' => 'blank', 'name_ar' => 'مستند فارغ', 'name_en' => 'Blank document'],
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'ok',
            'data' => $this->catalog(),
        ]);
    }

    public function store(StoreReportTemplateRequest $request): JsonResponse
    {
        $name = $request->validated('name');
        $file = $request->file('document');
        $template = ReportTemplate::query()->where('name', $name)->first() ?? new ReportTemplate(['name' => $name]);
        if (! $template->exists) {
            $template->document_path = 'report-templates/pending.docx';
            $template->size = 0;
            $template->save();
        }

        $path = "report-templates/{$template->id}.docx";
        Storage::disk(self::DISK)->putFileAs('report-templates', $file, "{$template->id}.docx");
        $template->fill([
            'name' => $name,
            'document_path' => $path,
            'size' => $file->getSize() ?: 0,
        ])->save();

        return response()->json([
            'message' => 'ok',
            'data' => $this->catalog(),
        ], 201);
    }

    public function document(ReportTemplate $reportTemplate): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($reportTemplate->document_path), 404);

        return Storage::disk(self::DISK)->download(
            $reportTemplate->document_path,
            $reportTemplate->name.'.docx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    public function destroy(ReportTemplate $reportTemplate): JsonResponse
    {
        Storage::disk(self::DISK)->delete($reportTemplate->document_path);
        $reportTemplate->delete();

        return response()->json([
            'message' => 'ok',
            'data' => $this->catalog(),
        ]);
    }

    /**
     * @return array{builtin: list<array{id: string, name_ar: string, name_en: string}>, saved: list<array{id: int, name: string, saved_at: string|null}>}
     */
    private function catalog(): array
    {
        return [
            'builtin' => self::BUILTIN,
            'saved' => ReportTemplate::query()
                ->latest('updated_at')
                ->get()
                ->map(fn (ReportTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'saved_at' => $template->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
