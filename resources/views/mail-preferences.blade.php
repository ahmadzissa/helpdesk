<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Manage email preferences · Areviews</title>
    <link rel="icon" href="{{ asset('areviews-logo.png') }}">
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f5f5fa;color:#282636;font:16px/1.65 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:40px 20px}
        .shell{max-width:580px;margin:0 auto}.brand{display:flex;align-items:center;justify-content:center;gap:12px;margin:12px 0 30px;font-size:22px;font-weight:650;letter-spacing:-.6px}.brand img{width:40px;height:44px;object-fit:contain}
        main{padding:36px;background:#fff;border:1px solid #e5e3ed;border-radius:20px;box-shadow:0 12px 40px #29213906}h1{font-size:28px;line-height:1.25;letter-spacing:-.8px;margin:10px 0 14px}h2{font-size:16px;margin:0 0 8px}p{margin:0 0 18px}.muted{color:#676474}.eyebrow{font-size:12px;letter-spacing:1.7px;text-transform:uppercase;font-weight:650;color:#6d54ac}
        .address{padding:14px 16px;border:1px solid #e5e3ed;border-radius:10px;background:#faf9fc;margin:24px 0;overflow-wrap:anywhere}.address span{display:block;font-size:12px;color:#676474;margin-bottom:3px}.status{display:inline-block;padding:4px 10px;border-radius:6px;background:#eaf4ef;color:#276045;font-size:13px;font-weight:600;margin-bottom:14px}.status.stopped,.status.restricted{background:#f4f0e7;color:#785b25}
        form{margin:24px 0 0}button{font:inherit;font-weight:600;border:0;border-radius:9px;background:#7051b5;color:#fff;padding:13px 20px;width:100%;cursor:pointer}button:hover{background:#5f409e}button:focus-visible{outline:3px solid #af94ed;outline-offset:4px}.notice{padding:13px 16px;background:#edf7f0;border:1px solid #c9e3d2;border-radius:9px;color:#285e3c;margin:20px 0}.error{background:#fff0ef;border-color:#efcbc7;color:#8b2e24}.details{border-top:1px solid #e9e6ef;margin-top:28px;padding-top:22px;font-size:14px}.details p:last-child{margin-bottom:0}footer{font-size:13px;color:#73707f;text-align:center;padding:24px 12px}
        @media(max-width:420px){body{padding:24px 14px}main{padding:25px 21px;border-radius:14px}h1{font-size:25px}.brand{margin-top:0}}
    </style>
</head>
<body>
<div class="shell">
    <header class="brand"><img src="{{ asset('areviews-logo.png') }}" alt="Areviews logo" width="40" height="44"><span>Areviews</span></header>
    <main>
        <div class="eyebrow">You're in control</div>
        <h1>Manage email preferences</h1>
        @if ($state === 'invalid')
            <div class="notice error" role="alert">This preference link is invalid or has expired.</div>
            <p class="muted">Open “Manage email preferences” in your latest Areviews support email. Copy the complete link if it does not open correctly.</p>
            <p>No email preferences have been changed.</p>
        @else
            <p class="muted">Choose whether you receive automatic and scheduled email notifications from Areviews Support. Direct replies from our support agents remain enabled.</p>
            <div class="address"><span>Email address</span><strong>{{ $email }}</strong></div>
            @if ($updated ?? false)
                <div class="notice" role="status">{{ $state === 'stopped' ? 'Automatic and scheduled emails stopped. Your preference has been saved.' : 'Your preference has been saved.' }}</div>
            @endif
            @if ($error ?? null)
                <div class="notice error" role="alert">{{ $error }}</div>
            @endif
            <span class="status {{ $state }}">{{ match ($state) { 'stopped' => 'Email notifications disabled', 'restricted' => 'Delivery restricted', default => 'Email notifications enabled' } }}</span>
            @if ($state === 'restricted')
                <h2>Contact support to review delivery</h2>
                <p class="muted">Email to this address has a delivery restriction that cannot be removed here. Reply to a previous support email to ask our team for a review.</p>
            @elseif ($state === 'stopped')
                <h2>You have opted out</h2>
                <p class="muted">Automatic and scheduled emails to this address are stopped across all your tickets. Our support agents can still reply to you directly. You can turn notifications back on whenever you need.</p>
                <form method="post" action="{{ $action }}">
                    @csrf
                    <button type="submit" name="preference" value="resume">Resume email notifications</button>
                </form>
            @else
                <h2>Manage automatic email notifications</h2>
                <p class="muted">No action is needed to keep notifications enabled. Stopping notifications applies to automatic and scheduled emails across all your tickets. Direct agent replies will still be sent.</p>
                <form method="post" action="{{ $action }}">
                    @csrf
                    <button type="submit" name="preference" value="stop">Stop email notifications</button>
                </form>
            @endif
            <div class="details muted">
                <h2>What happens next?</h2>
                <p>You can still send messages to support and receive direct replies from our agents. An automatic or scheduled email already being sent may still arrive.</p>
                <p>Resuming allows future automatic and scheduled emails; it does not resend earlier emails. Return to this link to review or change your preference.</p>
            </div>
        @endif
    </main>
    <footer>Areviews Support · Preferences for this email address only</footer>
</div>
</body>
</html>
