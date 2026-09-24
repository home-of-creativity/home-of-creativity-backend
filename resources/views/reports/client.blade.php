<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28mm 16mm 22mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a0838; }
        header, footer { position: fixed; left: 0; right: 0; color: #6b6178; font-size: 10px; }
        header { top: -18mm; border-bottom: 2px solid #e07020; padding-bottom: 4px; }
        footer { bottom: -14mm; border-top: 1px solid #e07020; padding-top: 4px; }
        .page:after { content: counter(page); }
        .cover { page-break-after: always; text-align: center; }
        .cover img { max-width: 100%; max-height: 240mm; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        td, th { border: 1px solid #d9d0e6; padding: 6px; }
        img { max-width: 100%; }
    </style>
</head>
<body>
    <header>{{ $header }}</header>
    <footer>{{ $footer }} — <span class="page"></span></footer>
    @if($cover)
        <div class="cover">
            <img src="{{ $cover }}" alt="">
        </div>
    @endif
    <h1>{{ $title }}</h1>
    <div>{!! $body !!}</div>
</body>
</html>
