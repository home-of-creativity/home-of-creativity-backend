<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EditReportWithGeminiRequest;
use App\Http\Requests\StoreClientReportRequest;
use App\Models\ReportMemory;
use App\Services\GeminiService;
use App\Support\ReportPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportGeminiController extends Controller
{
    public function memories(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->memoryList($request),
            'message' => 'ok',
        ]);
    }

    public function storeMemory(Request $request): JsonResponse
    {
        $body = trim((string) $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ])['body']);

        $request->user()->reportMemories()->create(['body' => $body]);
        $this->trimMemories($request);

        return response()->json([
            'data' => $this->memoryList($request),
            'message' => 'Saved.',
        ], 201);
    }

    public function destroyMemory(Request $request, ReportMemory $reportMemory): JsonResponse
    {
        abort_unless($reportMemory->user_id === $request->user()->id, 404);
        $reportMemory->delete();

        return response()->json([
            'data' => $this->memoryList($request),
            'message' => 'Deleted.',
        ]);
    }

    public function edit(EditReportWithGeminiRequest $request, GeminiService $gemini): JsonResponse
    {
        $document = ReportPages::parse($request->validated('body'));
        $pages = array_map(fn (array $page): string => $page['html'], $document['pages']);
        $pageIndex = $request->validated('scope') === 'page'
            ? min(count($pages) - 1, ((int) $request->validated('page')) - 1)
            : null;
        $memories = $request->user()->reportMemories()->latest()->limit(12)->pluck('body')->all();
        $result = $gemini->editReport($request->validated('instruction'), $pages, $memories, $pageIndex);

        if ($pageIndex === null) {
            foreach ($result['pages'] as $index => $html) {
                $document['pages'][$index]['html'] = StoreClientReportRequest::cleanHtml($html);
            }
        } else {
            $document['pages'][$pageIndex]['html'] = StoreClientReportRequest::cleanHtml($result['pages'][0]);
        }

        if ($request->boolean('save_memory')) {
            $request->user()->reportMemories()->create([
                'body' => mb_substr($request->validated('instruction'), 0, 500),
            ]);
            $this->trimMemories($request);
        } elseif (is_string($result['remember'])) {
            $request->user()->reportMemories()->create([
                'body' => mb_substr($result['remember'], 0, 500),
            ]);
            $this->trimMemories($request);
        }

        return response()->json([
            'data' => [
                'reply' => $result['reply'],
                'body' => $this->documentHtml($document),
                'memories' => $this->memoryList($request),
            ],
            'message' => 'ok',
        ]);
    }

    /**
     * @param  array{cover: ?string, coverWidth: int, mark: bool, markOpacity: int, markWidth: int, pages: list<array{html: string, chrome: bool}>}  $document
     */
    private function documentHtml(array $document): string
    {
        $html = '';
        if ($document['cover'] !== null) {
            $html .= '<section class="hoc-cover" data-width="'.$document['coverWidth'].'">'.$document['cover'].'</section>';
        }
        if ($document['mark']) {
            $html .= '<section class="hoc-mark" data-opacity="'.$document['markOpacity'].'" data-width="'.$document['markWidth'].'"></section>';
        }
        foreach ($document['pages'] as $page) {
            $chrome = $page['chrome'] ? '1' : '0';
            $html .= '<section class="hoc-page" data-chrome="'.$chrome.'">'.$page['html'].'</section>';
        }

        return $html;
    }

    /**
     * @return list<array{id: int, body: string}>
     */
    private function memoryList(Request $request): array
    {
        return $request->user()->reportMemories()->latest()->limit(12)->get()
            ->map(fn (ReportMemory $memory): array => [
                'id' => $memory->id,
                'body' => $memory->body,
            ])->all();
    }

    private function trimMemories(Request $request): void
    {
        $ids = $request->user()->reportMemories()->latest()->pluck('id')->slice(12);
        if ($ids->isNotEmpty()) {
            $request->user()->reportMemories()->whereIn('id', $ids)->delete();
        }
    }
}
