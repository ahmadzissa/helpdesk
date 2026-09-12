<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Email preferences · Relay</title>
<style>body{font:16px/1.6 system-ui,sans-serif;background:#f8f6fc;color:#272331;padding:24px}main{max-width:500px;margin:12vh auto;padding:32px;background:white;border-radius:16px;border:1px solid #e8e3ef}button{padding:12px 20px;background:#7450bb;color:white;border:0;border-radius:8px;font:inherit;cursor:pointer}p{overflow-wrap:anywhere}</style></head><body><main>
<h1>{{ $complete ? 'Email stopped' : 'Email preferences' }}</h1>
<p>{{ $complete ? 'Future outgoing email to this address is now suppressed:' : 'Stop future outgoing email to this address?' }}</p><p><strong>{{ $email }}</strong></p>
@if (!$complete)<form method="post" action="{{ $action }}">@csrf<button type="submit">Stop email to this address</button></form><p>You can still contact support by sending an email.</p>@else<p>You can still send messages to support.</p>@endif
</main></body></html>
