<?php

namespace App\Http\Controllers;

use App\Models\PhotographyBooking;
use App\Support\PhotographyDeviceAlarm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class PhotographyAlarmController extends Controller
{
    public function show(Request $request, PhotographyBooking $booking, PhotographyDeviceAlarm $alarm): View|Response
    {
        abort_unless($booking->status === 'confirmed' && $booking->starts_at !== null, 404);
        $start = Carbon::parse($booking->starts_at->format('Y-m-d H:i:s'), 'Asia/Damascus');
        if ($this->isApplePhone((string) $request->userAgent())) {
            return $this->calendar($booking, $alarm);
        }

        $intent = $alarm->intent($start);
        abort_if($intent === null, 404);

        return view('photography-alarm', [
            'intent' => $intent,
            'when' => $alarm->moment($start)?->format('Y-m-d H:i'),
            'usesTimer' => str_contains($intent, 'SET_TIMER'),
        ]);
    }

    public function calendar(PhotographyBooking $booking, PhotographyDeviceAlarm $alarm): Response
    {
        abort_unless($booking->status === 'confirmed' && $booking->starts_at !== null, 404);
        $start = Carbon::parse($booking->starts_at->format('Y-m-d H:i:s'), 'Asia/Damascus');
        abort_if($alarm->moment($start) === null, 404);

        return response($alarm->calendarFile($start, $booking->id), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="photography.ics"',
        ]);
    }

    private function isApplePhone(string $agent): bool
    {
        return preg_match('/iPhone|iPad|iPod/i', $agent) === 1;
    }
}
