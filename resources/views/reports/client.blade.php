@php
    $document = \App\Support\ReportPages::parse($body);
    $watermark = $watermark ?? null;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 16mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a0838; }
        .sheet { page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .sheet-head { margin-bottom: 6mm; padding-bottom: 3mm; border-bottom: 2px solid #e07020; color: #6b6178; font-size: 10px; }
        .sheet-foot { margin-top: 6mm; padding-top: 3mm; border-top: 1px solid #e07020; color: #6b6178; font-size: 10px; }
        .cover-sheet { text-align: center; }
        .cover-sheet img { max-width: 100%; }
        .cover-copy { margin-top: 6mm; text-align: right; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        td, th { border: 1px solid #d9d0e6; padding: 6px; }
        table.hoc-plain td, table.hoc-plain th { border: 0; }
        img { max-width: 100%; height: auto; }
        .sheet > div { position: relative; }
        .hoc-abs { position: absolute; box-sizing: border-box; }
        .hoc-shape.is-rect { border: 2px solid #2e0e5c; }
        .hoc-shape.is-ellipse { border: 2px solid #2e0e5c; border-radius: 50%; }
        .hoc-shape.is-line { border-top: 3px solid #2e0e5c; height: 0; }
        .hoc-abs img { width: 100%; height: 100%; }
        .watermark { position: fixed; left: 0; right: 0; top: 38%; text-align: center; z-index: -1; }
    </style>
</head>
<body>
    @if(! empty($watermark) && $document['mark'])
        <div class="watermark">
            <img src="{{ $watermark }}" alt="" style="width: {{ $document['markWidth'] }}%; opacity: {{ $document['markOpacity'] / 100 }};">
        </div>
    @endif
    @if($cover || filled($document['cover']))
        <div class="sheet cover-sheet">
            @if($cover)
                <img src="{{ $cover }}" alt="" style="width: {{ $document['coverWidth'] }}%;">
            @endif
            @if(filled($document['cover']))
                <div class="cover-copy">{!! $document['cover'] !!}</div>
            @endif
        </div>
    @endif
    @foreach($document['pages'] as $index => $page)
        <div class="sheet">
            @if($page['chrome'] && filled($header))
                <div class="sheet-head">{{ $header }}</div>
            @endif
            @if($index === 0)
                <h1>{{ $title }}</h1>
            @endif
            <div>{!! $page['html'] !!}</div>
            @if($page['chrome'] && filled($footer))
                <div class="sheet-foot">{{ $footer }} — {{ $index + 1 }}</div>
            @endif
        </div>
    @endforeach
</body>
</html>
