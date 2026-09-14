@php
    /**
     * 日次の連絡帳。送迎時にご家族へお渡しする1枚。
     *
     * 印刷用のBladeビューにしている。理由は2つある。
     *   1. ブラウザの印刷機能でそのまま紙にもPDFにもできる
     *   2. 日本語PDFライブラリのフォント埋め込みに依存しない
     * 現場は紙で渡す。紙に出せることが要件であり、PDF生成はその手段のひとつに過ぎない。
     *
     * 【水分摂取量は載せていない】
     * 記録としては保持し、脱水リスクの判定にも使っている。
     * ただし提供のたびに正確な量を把握するのは現場では難しく、
     * 不確かな数値をご家族にお伝えすると、かえって誤解を生む。
     *
     * 【下半分は切り取って返していただく】
     * ご家族からの連絡欄には、お名前と日付を再掲している。
     * 切り離された紙片だけでも、どなたのものか分かるようにするため。
     */
    $rate = fn (?int $value): string => match ($value) {
        null => '—',
        0 => 'お召し上がりなし',
        50 => '半分ほど',
        100 => '全量',
        default => intdiv($value, 10) . '割ほど',
    };

    $weekday = fn ($date): string => ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek];
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ご利用連絡帳 — {{ $record->service_date->format('Y年n月j日') }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #eceeed;
            color: #1a2724;
            font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif;
            font-size: 14px;
            line-height: 1.85;
        }

        .sheet {
            max-width: 182mm;
            margin: 0 auto;
            background: #fff;
            padding: 28px 32px 32px;
            box-shadow: 0 2px 16px rgba(0, 0, 0, .08);
        }

        .toolbar {
            max-width: 182mm;
            margin: 0 auto 14px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .toolbar .note { font-size: 12.5px; color: #5a6b65; }
        .toolbar button {
            font: inherit;
            font-weight: 700;
            border: 0;
            border-radius: 8px;
            background: #1f6f5c;
            color: #fff;
            padding: 9px 18px;
            cursor: pointer;
        }
        .toolbar button:hover { background: #175348; }

        header { border-bottom: 2px solid #1f6f5c; padding-bottom: 12px; margin-bottom: 18px; }
        .facility { font-size: 12.5px; color: #5a6b65; letter-spacing: .04em; }
        h1 { margin: 2px 0 10px; font-size: 21px; letter-spacing: .08em; }
        .meta { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px; }
        .meta .date { font-size: 15px; font-weight: 700; }
        .meta .name { font-size: 17px; font-weight: 700; }
        .meta .name small { font-size: 12px; font-weight: 400; color: #5a6b65; margin-left: 6px; }

        section { margin-bottom: 18px; }
        h2 {
            font-size: 13px;
            letter-spacing: .1em;
            color: #1f6f5c;
            margin: 0 0 8px;
            padding-left: 9px;
            border-left: 4px solid #1f6f5c;
        }

        .body-text { font-size: 14.5px; line-height: 2; white-space: pre-wrap; }
        .empty { color: #8b9995; font-size: 13px; }

        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th, td { border: 1px solid #d5ddd9; padding: 7px 10px; text-align: left; }
        th { background: #f4f7f5; width: 26%; font-weight: 500; color: #3c4b46; white-space: nowrap; }

        .writer { margin-top: 8px; text-align: right; font-size: 13px; color: #3c4b46; }

        .notice {
            border: 1.5px solid #9c7017;
            background: #fbf6ea;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .notice h2 { color: #9c7017; border-left-color: #9c7017; margin-bottom: 6px; }
        .notice ul { margin: 0; padding-left: 20px; }
        .notice li { margin-bottom: 3px; }
        .notice .lead { font-size: 13px; color: #7d5a12; margin-top: 6px; }

        .announcement {
            border: 1px solid #cfdbd5;
            background: #f6f9f7;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .announcement .body-text { font-size: 13.5px; line-height: 1.95; }

        .next-visit {
            font-size: 13.5px;
            padding: 8px 12px;
            background: #e4efea;
            border-radius: 6px;
            display: inline-block;
        }
        .next-visit b { font-size: 15px; }

        .cut {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 26px 0 20px;
            color: #93a29b;
            font-size: 11.5px;
            letter-spacing: .12em;
        }
        .cut::before, .cut::after {
            content: "";
            flex: 1;
            border-top: 1.5px dashed #b6c3bd;
        }

        .write-in .caption { font-size: 12.5px; color: #5a6b65; margin: 0 0 9px; }
        .write-in .slip-meta {
            font-size: 12.5px;
            color: #3c4b46;
            margin-bottom: 9px;
            padding-bottom: 7px;
            border-bottom: 1px solid #e2e8e5;
        }
        .write-in .box { border: 1px solid #d5ddd9; border-radius: 6px; padding: 10px 14px 14px; }
        .write-in .line { border-bottom: 1px dotted #c3cfc9; height: 27px; }

        @media print {
            body { background: #fff; padding: 0; }
            .sheet { max-width: none; box-shadow: none; padding: 0; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()">この連絡帳を印刷する</button>
    <span class="note">印刷ダイアログで「PDFに保存」を選ぶと、PDFとしても保存できます。</span>
</div>

<div class="sheet">
    <header>
        <div class="facility">{{ $facility?->name ?? '事業所' }}</div>
        <h1>ご利用連絡帳</h1>
        <div class="meta">
            <div class="date">{{ $record->service_date->format('Y年n月j日') }}（{{ $weekday($record->service_date) }}）</div>
            <div class="name">
                {{ $resident->name }} 様
                @if ($resident->careLevel)
                    <small>{{ $resident->careLevel->name }}</small>
                @endif
            </div>
        </div>
    </header>

    <section>
        <h2>本日のご様子</h2>
        @if (filled($record->family_text))
            <p class="body-text">{{ $record->family_text }}</p>
        @else
            <p class="empty">本日のご様子は、担当職員より口頭でお伝えいたします。</p>
        @endif
    </section>

    @if ($verbalContacts->isNotEmpty())
        <section class="notice">
            <h2>お迎えの際に職員よりお伝えします</h2>
            <ul>
                @foreach ($verbalContacts as $contact)
                    <li>{{ $contact->topic }}</li>
                @endforeach
            </ul>
            <p class="lead">詳しい状況は、文章だけでは伝わりにくい内容です。お迎えの際に担当職員より直接ご説明いたします。ご不明な点はその場でお尋ねください。</p>
        </section>
    @endif

    <section>
        <h2>本日の記録</h2>
        <table>
            <tbody>
            <tr>
                <th>ご到着・お帰り</th>
                <td>
                    {{ $record->arrival_time ? \Illuminate\Support\Str::substr($record->arrival_time, 0, 5) : '—' }}
                    〜
                    {{ $record->departure_time ? \Illuminate\Support\Str::substr($record->departure_time, 0, 5) : '—' }}
                </td>
            </tr>
            <tr>
                <th>体温</th>
                <td>{{ $vital?->temperature !== null ? $vital->temperature . ' ℃' : '未測定' }}</td>
            </tr>
            <tr>
                <th>血圧・脈拍</th>
                <td>
                    @if ($vital?->systolic_bp !== null)
                        {{ $vital->systolic_bp }} / {{ $vital->diastolic_bp }} mmHg
                    @else
                        未測定
                    @endif
                    @if ($vital?->pulse !== null)
                        　脈拍 {{ $vital->pulse }} 回/分
                    @endif
                </td>
            </tr>
            <tr>
                <th>入浴</th>
                <td>
                    {{-- 記録がないことと、実施しなかったことは違う。
                         未記録を「お休みされました」と書くと、ご家族に
                         誤った説明をすることになる。 --}}
                    {{ $record->bathingRecords->last()?->bathing_type->familyLabel() ?? '—' }}
                </td>
            </tr>
            <tr>
                <th>お食事（昼食）</th>
                <td>
                    主食 {{ $rate($lunch?->staple_rate) }}　／　副菜 {{ $rate($lunch?->side_rate) }}
                    @if ($lunch?->meal_form)
                        　（{{ $lunch->meal_form }}）
                    @endif
                </td>
            </tr>
            </tbody>
        </table>
        <p class="writer">記入者：{{ $record->recorder?->name ?? '—' }}</p>
    </section>

    @if (filled($facility?->notice))
        <section class="announcement">
            <h2>事業所からのお知らせ</h2>
            <p class="body-text">{{ $facility->notice }}</p>
        </section>
    @endif

    @if ($nextVisit)
        <section>
            <span class="next-visit">
                次回のご利用予定　<b>{{ $nextVisit->format('n月j日') }}（{{ $weekday($nextVisit) }}）</b>
            </span>
        </section>
    @endif

    <div class="cut">きりとり線</div>

    <section class="write-in">
        <h2>ご家族からの連絡欄</h2>
        <p class="caption">何かあれば、こちらに記入いただき、次回ご利用時に職員にお渡しください。</p>
        <div class="slip-meta">{{ $resident->name }} 様　／　{{ $record->service_date->format('Y年n月j日') }} 分</div>
        <div class="box">
            <div class="line"></div>
            <div class="line"></div>
            <div class="line"></div>
            <div class="line"></div>
        </div>
    </section>
</div>

</body>
</html>
