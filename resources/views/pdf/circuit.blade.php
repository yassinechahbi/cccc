<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Circuit #:number', ['number' => $circuit->number]) }}</title>
    <style>
        @page { margin: 9mm 11mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 8.5pt; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td { border: 0.8pt solid #333; padding: 3pt 5pt; vertical-align: top; }
        .muted { color: #555; }
        .small { font-size: 7pt; }
        .title td { font-size: 12pt; font-weight: bold; }
        .title .sub { font-size: 10pt; }
        .leg { margin-top: 4pt; page-break-inside: avoid; }
        .leg .head { font-weight: bold; border-bottom: 0.6pt solid #333; padding-bottom: 2pt; margin-bottom: 2pt; }
        .leg .country { font-weight: bold; }
        .leg .interests { font-size: 7pt; color: #333; border-top: 0.6pt solid #999; margin-top: 3pt; padding-top: 2pt; }
        .label { font-size: 7.5pt; color: #444; }
        .date { height: 8mm; }
        .signature { height: 6mm; }
        .option { white-space: nowrap; margin-right: 5pt; font-size: 7.5pt; }
        .box { display: inline-block; width: 6.5pt; height: 6.5pt; border: 0.8pt solid #333; margin-right: 2pt; }
        .qr { text-align: center; width: 24%; vertical-align: middle; }
        .qr img { width: 20mm; height: 20mm; }
        .code { font-family: 'DejaVu Sans Mono', monospace; font-size: 10pt; font-weight: bold; letter-spacing: 1pt; }
        .return td { background: #f2f2f2; }
        h3 { font-size: 10pt; margin: 0 0 3pt 0; }
        ul { margin: 2pt 0 0 0; padding-left: 12pt; }
        li { margin-bottom: 2pt; }
        .messages { height: 45mm; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    {{-- FRONT --}}
    <table class="title">
        <tr>
            <td colspan="2">{{ __('Cover Collectors Circuit Club') }} &mdash; {{ __('Circuit #:number', ['number' => $circuit->number]) }}</td>
        </tr>
        <tr>
            <td class="sub">www.covercollectors.club</td>
            <td class="sub">{{ __('Date mailed') }}: {{ $circuit->mailed_at->format('Y-m-d') }}</td>
        </tr>
    </table>

    @foreach ($circuit->legs as $leg)
        @php($member = $leg->member)
        <table class="leg {{ $leg->is_return ? 'return' : '' }}">
            <tr>
                <td style="width: 40%" rowspan="3">
                    <div class="head">
                        @if ($leg->is_return)
                            {{ $leg->position }}. {{ __('Return to the Originating Member') }} #{{ $member->member_number }}
                        @else
                            {{ $leg->position }}. {{ __('Mail to Member') }} #{{ $member->member_number }}
                        @endif
                    </div>
                    {{ $member->displayName() }}<br>
                    @if ($member->address){{ $member->address }}<br>@endif
                    @if ($member->city){{ $member->city }}<br>@endif
                    <span class="country">{{ $member->country?->name }}</span>
                    <div class="interests">
                        {{ __('Countries') }}: {{ $member->interest_countries ?: __('not specified') }}<br>
                        {{ __('Themes') }}: {{ $member->interest_themes ?: __('not specified') }}
                    </div>
                </td>
                <td style="width: 18%" class="date"><span class="label">{{ __('Date received') }}:</span></td>
                <td style="width: 18%" class="date">
                    @unless ($leg->is_return)<span class="label">{{ __('Date mailed') }}:</span>@endunless
                </td>
                <td class="qr" rowspan="3">
                    <img src="{{ $qr[$leg->id] }}" alt="">
                    <div class="code">{{ $leg->reference() }}</div>
                    <div class="small muted">{{ __('Scan, or enter this code at') }}<br>{{ $trackHost }}</div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <span class="label">{{ __('Quality') }}:</span><br>
                    @foreach (\App\Enums\CoverQuality::cases() as $quality)
                        <span class="option"><span class="box"></span>{{ $quality->label() }}</span>@if ($loop->iteration === 3)<br>@endif
                    @endforeach
                </td>
            </tr>
            <tr>
                <td colspan="2" class="signature"><span class="label">{{ __('Signature') }}:</span></td>
            </tr>
        </table>
    @endforeach

    {{-- BACKSIDE --}}
    <div class="page-break"></div>

    <table>
        <tr>
            <td>
                <strong>{{ __('It is the responsibility of all CCCC members to forward all circuits sent to them in a timely manner to the next member on the list.') }}</strong>
                <br><br>
                <h3>{{ __('When the circuit reaches you') }}</h3>
                <ul>
                    <li>{{ __('Scan the QR code in your box with your phone camera, or go to :url and type your code (for example :example).', ['url' => $trackHost, 'example' => $circuit->legs->first()->reference()]) }}</li>
                    <li>{{ __('Record the date received and the quality of the cover. When you mail the circuit on, use the same code to record the date mailed.') }}</li>
                    <li>{{ __('Also fill in the paper form: Cover Quality, Date Received, Date Mailed and Signature.') }}</li>
                    <li>{{ __('No phone or internet? Simply fill in the paper form: the Originating Member will record the dates for you.') }}</li>
                </ul>
            </td>
        </tr>
        <tr>
            <td>
                <h3>{{ __('To rate covers please use this guide') }}</h3>
                <ul>
                    @foreach (\App\Enums\CoverQuality::cases() as $quality)
                        <li><strong>{{ mb_strtoupper($quality->label()) }}:</strong> {{ $quality->guide() }}</li>
                    @endforeach
                </ul>
            </td>
        </tr>
        <tr>
            <td>
                <h3>{{ __('Headquarters') }}</h3>
                @foreach (config('cccc.headquarters') as $line){{ $line }}<br>@endforeach
            </td>
        </tr>
        @if ($circuit->om_message)
            <tr>
                <td>
                    <h3>{{ __('Message from the Originating Member') }}</h3>
                    {!! nl2br(e($circuit->om_message)) !!}
                </td>
            </tr>
        @endif
        <tr>
            <td class="messages">
                <h3>{{ __('Write your Member to Member messages here') }}:</h3>
            </td>
        </tr>
        <tr>
            <td>
                <h3>{{ __('Note from the editor') }}:</h3>
                {{ __('This circuit was created at the CCCC website, a tool available for all OMs.') }}
            </td>
        </tr>
    </table>
</body>
</html>
