<div style="margin:0;background-color:#f2f5f8;color:#262b36;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f2f5f8" style="width:100%;border-collapse:collapse">
        <tr>
            <td align="center" style="padding:28px 14px">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse">
                    <tr>
                        <td align="center" style="padding:0 0 24px;text-align:center">
                            <img src="cid:areviews-logo@relay.brand" width="36" height="40" alt="Areviews" style="display:block;margin:0 auto;border:0;width:36px;height:40px">
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" style="padding:26px;background-color:#ffffff;border-radius:0 14px 14px 14px;overflow-wrap:anywhere;word-break:break-word">
                            <div dir="auto">{!! $content !!}</div>
                        </td>
                    </tr>
                    @if ($previousContent !== null)
                        <tr class="gmail_quote">
                            <td style="padding:22px 0 0 18px">
                                <div style="margin:0 0 12px;color:#646b79;font-size:11px;font-weight:700;letter-spacing:1.5px">PREVIOUS MESSAGE</div>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td bgcolor="#f6f3fc" style="padding:20px 24px;border:1px solid #e1e6ee;border-radius:14px 14px 0 14px;background-color:#f6f3fc;overflow-wrap:anywhere;word-break:break-word">
                                            <p dir="auto" style="margin:0 0 12px;font-size:12px;font-weight:700;color:#646b79">{{ $previousAttribution }}</p>
                                            <blockquote type="cite" dir="auto" style="margin:0;padding:0;border:0;font-size:14px;color:#646b79">{!! $previousContent !!}</blockquote>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif
                    @if ($preferencesUrl)
                        <tr>
                            <td align="center" style="padding:22px 0 0;font-size:11px;color:#646b79;overflow-wrap:anywhere">
                                <a href="{{ $preferencesUrl }}" style="color:#646b79;text-decoration:underline">Manage email preferences</a> for {{ $recipient }}.
                            </td>
                        </tr>
                    @endif
                </table>
                @if ($trackingUrl)
                    <img src="{{ $trackingUrl }}" width="1" height="1" alt="" style="display:block;border:0">
                @endif
            </td>
        </tr>
    </table>
</div>
