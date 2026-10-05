<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: ibmplexarabic, sans-serif; color: #1a0838; font-size: 12px; direction: rtl; }
        .band { background: #2e0e5c; color: #fff; padding: 18px 28px 16px; }
        .brand { font-size: 18px; letter-spacing: 0; }
        .brand-en { font-size: 11px; color: #f0d8c4; margin-top: 2px; text-align: right; }
        .kicker { font-size: 22px; margin-top: 14px; }
        .rule { height: 5px; background: #e07020; }
        .sheet { padding: 22px 28px 18px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.meta td { width: 50%; vertical-align: top; padding: 0 0 8px; }
        .label { color: #6b6178; font-size: 10px; }
        .value { font-size: 13px; margin-top: 2px; }
        .subject { font-size: 16px; margin: 4px 0 12px; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td { border-bottom: 1px solid #e4dced; padding: 8px 0; vertical-align: top; }
        table.rows td.amount { text-align: left; white-space: nowrap; padding-right: 12px; }
        .notes { margin-top: 14px; line-height: 1.6; }
        .total { margin-top: 16px; background: #f7f1ea; border-right: 4px solid #e07020; padding: 10px 12px; }
        .total span { float: left; }
        .aside { color: #6b6178; font-size: 11px; margin-top: 6px; }
        .foot { position: fixed; left: 0; right: 0; bottom: 0; background: #2e0e5c; color: #fff; padding: 10px 28px; font-size: 10px; }
    </style>
</head>
<body>
    <div class="band">
        <div class="brand">دار الإبداع</div>
        <div class="brand-en" dir="ltr">Home of Creativity</div>
        <div class="kicker">{{ $kicker }}</div>
    </div>
    <div class="rule"></div>
    <div class="sheet">
        <table class="meta">
            <tr>
                <td>
                    <div class="label">الرقم</div>
                    <div class="value" dir="ltr">{{ $number }}</div>
                </td>
                <td>
                    <div class="label">التاريخ</div>
                    <div class="value" dir="ltr">&#x202A;{{ $date }}&#x202C;</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">الزبون</div>
                    <div class="value">{{ $clientName }}</div>
                </td>
                <td>
                    <div class="label">الشركة</div>
                    <div class="value">{{ $company }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">الهاتف</div>
                    <div class="value" dir="ltr">&#x202A;{{ $phone }}&#x202C;</div>
                </td>
                <td>
                    <div class="label">البريد</div>
                    <div class="value" dir="ltr">&#x202A;{{ $email }}&#x202C;</div>
                </td>
            </tr>
        </table>
        <div class="subject">{{ $subject }}</div>
        <table class="rows">
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="amount" dir="ltr">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
        @if(filled($notes))
            <div class="notes">{!! nl2br(e($notes)) !!}</div>
        @endif
        <div class="total">
            المجموع
            <span dir="ltr">{{ $total }}</span>
        </div>
        @if(filled($aside))
            <div class="aside">{{ $aside }}</div>
        @endif
    </div>
    <div class="foot" dir="ltr">hoc.agency · {{ \App\Support\ClientChannelGate::SUPPORT_PHONE }}</div>
</body>
</html>
