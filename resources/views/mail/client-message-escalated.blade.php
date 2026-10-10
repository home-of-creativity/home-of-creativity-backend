<!doctype html>
<html lang="ar" dir="rtl">
<body style="font-family: Tahoma, Arial, sans-serif; line-height: 1.6; color: #1f1a2e;">
<h2 style="margin: 0 0 12px;">رسالة عميل لم يفهمها البوت</h2>
<p style="margin: 0 0 12px;">رد على العميل من الرابط تحت، ثم أصلح سبب المشكلة.</p>

<table cellpadding="6" style="border-collapse: collapse; margin-bottom: 16px;">
    <tr><td><strong>العميل</strong></td><td>{{ $client['name'] ?? '' }}</td></tr>
    <tr><td><strong>الشركة</strong></td><td>{{ $client['company'] ?? '' }}</td></tr>
    <tr><td><strong>الهاتف</strong></td><td dir="ltr">{{ $client['phone'] ?? '' }}</td></tr>
    <tr><td><strong>البريد</strong></td><td dir="ltr">{{ $client['email'] ?? '' }}</td></tr>
    <tr><td><strong>القناة</strong></td><td>{{ $channel }}</td></tr>
    <tr><td><strong>خطوة البوت</strong></td><td dir="ltr">{{ $step }}</td></tr>
    @if ($chatUrl)
        <tr><td><strong>المحادثة</strong></td><td dir="ltr"><a href="{{ $chatUrl }}">{{ $chatUrl }}</a></td></tr>
    @endif
    @if ($dashboardUrl)
        <tr><td><strong>لوحة التحكم</strong></td><td dir="ltr"><a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a></td></tr>
    @endif
</table>

<h3 style="margin: 0 0 6px;">رسالة العميل</h3>
<blockquote style="margin: 0 0 16px; padding: 10px 14px; background: #f6f2ea; border-inline-start: 4px solid #f08a24;">{!! nl2br(e($messageText)) !!}</blockquote>

<h3 style="margin: 0 0 6px;">لماذا وصلتك</h3>
<p dir="ltr" style="margin: 0 0 16px; text-align: left;">{{ $reason }}</p>

@if ($history !== [])
    <h3 style="margin: 0 0 6px;">آخر المحادثة</h3>
    <ul style="margin: 0 0 16px; padding-inline-start: 18px;">
        @foreach ($history as $turn)
            <li><strong>{{ ($turn['role'] ?? '') === 'assistant' ? 'الفريق' : 'العميل' }}:</strong> {{ $turn['text'] ?? '' }}</li>
        @endforeach
    </ul>
@endif

<h3 style="margin: 0 0 6px;">ما يعرفه البوت عن هذا العميل</h3>
<pre dir="ltr" style="white-space: pre-wrap; font-family: Consolas, monospace; font-size: 12px; background: #f4f4f7; padding: 10px; text-align: left;">{{ $records }}</pre>
</body>
</html>
