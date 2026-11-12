<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificate check · Dot.Tutor</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; background: #f6f4ee; color: #1c1917; font: 16px/1.6 system-ui, -apple-system, 'Segoe UI', sans-serif; }
        main { max-width: 40rem; margin: 0 auto; padding: 3rem 1rem; }
        h1 { font-size: 1.6rem; line-height: 1.2; margin: 0 0 1rem; text-wrap: balance; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1.25rem; margin: 1.5rem 0; }
        dt { color: #57534e; }
        dd { margin: 0; font-weight: 600; }
        .state { display: inline-block; padding: .2rem .7rem; border-radius: 999px; font-weight: 700; font-size: .85rem; }
        .valid { background: #dcfce7; color: #14532d; }
        .invalid { background: #fee2e2; color: #7f1d1d; }
        p.small { font-size: .85rem; color: #57534e; max-width: 60ch; }
        code { font-size: 1rem; font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<main>
    @if ($certificate === null)
        <h1>No certificate has the code <code>{{ strtoupper($code) }}</code></h1>
        <p>Check the code for typing mistakes. Codes look like <code>1A2B-3C4D-5E6F</code>.</p>
    @else
        <span class="state {{ $certificate['valid'] ? 'valid' : 'invalid' }}">{{ $certificate['valid'] ? 'Valid certificate' : 'Revoked certificate' }}</span>
        <h1>{{ $certificate['course_title'] }}</h1>
        <dl>
            <dt>Learner</dt><dd>{{ $certificate['learner'] }}</dd>
            <dt>Tutor</dt><dd>{{ $certificate['tutor_name'] ?? 'Not recorded' }}</dd>
            <dt>Issued</dt><dd>{{ $certificate['issued_at'] }}</dd>
            <dt>Course version</dt><dd>{{ $certificate['course_version'] }}</dd>
            <dt>Code</dt><dd><code>{{ $certificate['code'] }}</code></dd>
        </dl>
        <p class="small">{{ $certificate['statement'] }}</p>
    @endif
</main>
</body>
</html>
