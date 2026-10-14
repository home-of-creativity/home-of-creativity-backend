<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendClientReport;
use App\Http\Controllers\Controller;
use App\Models\ClientReport;
use App\Models\ClientReportSend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The report panel's «إرسال» tab: send the PDF to the client and list what was sent. */
class ClientReportSendController extends Controller
{
    public function index(ClientReport $clientReport): JsonResponse
    {
        $rows = $clientReport->sends()->with('user:id,name')->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (ClientReportSend $send): array => $this->row($send))->all(), 'message' => 'ok']);
    }

    public function store(Request $request, ClientReport $clientReport, SendClientReport $send): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
            'save_contact' => ['boolean'],
        ]);

        $result = $send->handle(
            $clientReport,
            $request->user(),
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            $validated['message'] ?? null,
            (bool) ($validated['save_contact'] ?? false),
        );
        $delivered = $result['delivered'];
        $rows = array_map(fn (ClientReportSend $row): array => $this->row($row->load('user:id,name')), $result['sends']);

        if (! in_array(true, $delivered, true)) {
            return response()->json(['data' => $rows, 'message' => 'تعذر إرسال التقرير. تأكد من البريد أو رقم واتساب.'], 422);
        }
        $failed = array_keys(array_filter($delivered, fn (bool $ok): bool => ! $ok));

        return response()->json([
            'data' => $rows,
            'message' => $failed === []
                ? 'أُرسل التقرير للعميل.'
                : 'أُرسل التقرير، وتعذر الإرسال عبر '.($failed[0] === 'email' ? 'البريد' : 'واتساب').'.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ClientReportSend $send): array
    {
        return [
            'id' => $send->id,
            'channel' => $send->channel,
            'recipient' => $send->recipient,
            'message' => $send->message,
            'delivered' => $send->delivered,
            'user' => $send->user ? ['id' => $send->user->id, 'name' => $send->user->name] : null,
            'created_at' => $send->created_at?->toIso8601String(),
        ];
    }
}
