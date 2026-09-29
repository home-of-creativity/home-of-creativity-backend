<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class PhotographyDeviceAlarm
{
    public function moment(Carbon $start): ?Carbon
    {
        $start = Carbon::parse($start->format('Y-m-d H:i:s'), 'Asia/Damascus');
        $alarm = $start->copy()->subDay();
        $now = now('Asia/Damascus');
        if ($alarm->lessThanOrEqualTo($now)) {
            $alarm = $start->copy();
        }
        if ($alarm->lessThanOrEqualTo($now)) {
            return null;
        }

        return $alarm;
    }

    public function intent(Carbon $start): ?string
    {
        $alarm = $this->moment($start);
        if ($alarm === null) {
            return null;
        }

        $seconds = $alarm->getTimestamp() - now('Asia/Damascus')->getTimestamp();
        if ($seconds <= 0) {
            return null;
        }

        $message = rawurlencode('موعد تصوير');
        if ($seconds <= 86400) {
            return 'intent:#Intent;action=android.intent.action.SET_ALARM'
                .';i.android.intent.extra.alarm.HOUR='.(int) $alarm->format('G')
                .';i.android.intent.extra.alarm.MINUTES='.(int) $alarm->format('i')
                .';S.android.intent.extra.alarm.MESSAGE='.$message
                .';B.android.intent.extra.alarm.SKIP_UI=true;end';
        }

        return 'intent:#Intent;action=android.intent.action.SET_TIMER'
            .';i.android.intent.extra.alarm.LENGTH='.$seconds
            .';S.android.intent.extra.alarm.MESSAGE='.$message
            .';B.android.intent.extra.alarm.SKIP_UI=true;end';
    }

    public function calendarFile(Carbon $start, int $bookingId): string
    {
        $start = Carbon::parse($start->format('Y-m-d H:i:s'), 'Asia/Damascus');
        $end = $start->copy()->addHours(3);
        $stamp = now('Asia/Damascus')->utc()->format('Ymd\THis\Z');

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//HOC//Photography//AR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Damascus',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0300',
            'TZOFFSETTO:+0300',
            'TZNAME:+03',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:photography-'.$bookingId.'@hoc.agency',
            'DTSTAMP:'.$stamp,
            'DTSTART;TZID=Asia/Damascus:'.$start->format('Ymd\THis'),
            'DTEND;TZID=Asia/Damascus:'.$end->format('Ymd\THis'),
            'SUMMARY:موعد تصوير',
            'BEGIN:VALARM',
            'TRIGGER:-P1D',
            'ACTION:DISPLAY',
            'DESCRIPTION:تذكير التصوير قبل يوم',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    public function googleCalendarUrl(Carbon $start): string
    {
        $start = Carbon::parse($start->format('Y-m-d H:i:s'), 'Asia/Damascus');
        $end = $start->copy()->addHours(3);
        $dates = $start->utc()->format('Ymd\THis\Z').'/'.$end->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            .'&text='.rawurlencode('موعد تصوير')
            .'&dates='.$dates
            .'&ctz=Asia%2FDamascus'
            .'&details='.rawurlencode('موعد تصوير Home of Creativity');
    }
}
