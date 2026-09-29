<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>موعد التصوير</title>
    <style>
        body { font-family: sans-serif; margin: 0; background: #f6f1e8; color: #1c1915; }
        main { max-width: 28rem; margin: 0 auto; padding: 2rem 1.25rem; }
        a { display: block; margin-top: 1rem; padding: 0.9rem 1rem; border-radius: 999px; text-align: center; text-decoration: none; background: #1c1915; color: #f6f1e8; }
        p { line-height: 1.6; }
    </style>
</head>
<body>
<main>
    <h1>موعد التصوير</h1>
    @if ($usesTimer)
        <p>يبدأ التذكير الآن ويصل في {{ $when }}.</p>
    @else
        <p>يُسجَّل التذكير الآن ويرن في {{ $when }}.</p>
    @endif
    <a id="alarm" href="{{ $intent }}">تسجيل التذكير</a>
</main>
<script>
    const intent = @json($intent);
    if (/Android/i.test(navigator.userAgent)) {
        window.location.href = intent;
    }
</script>
</body>
</html>
