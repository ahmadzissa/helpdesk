# Areviews private Android app — handover

Date: 2026-09-15

## Objective and agreed scope

Build a personal mobile companion for the existing Areviews Electron/admin experience. The user's main reason for wanting an app is spoken notifications. They do not want to publish it on Google Play.

The conversation is targeting Android and private APK installation. The phone model and Android version have not been provided.

Exactly three notification categories were requested:

| Event | Spoken notification sound |
| --- | --- |
| New ticket | “You've got a new ticket.” |
| New customer reply | “You've got a new reply.” |
| New live chat | “You've got a new chat.” |

Use short voice recordings bundled with the app as notification sounds. The user wants audible speech, not only text. Text can accompany the sound, and tapping an alert should open the relevant ticket or chat.

English phrases above are the agreed wording. Voice/accent and recording assets have not been selected. Dynamic reading of customer messages, voice calling, repeated alarms, escalation reminders, and extra alert categories are not part of the requested scope.

## Current status

- This document is the handover; no Expo app or mobile notification implementation has been created in this task.
- No dependencies were installed, Firebase/EAS projects created, push credentials configured, audio generated, APK built, or services deployed.
- Repository source and selected local configuration were inspected. Production behavior and URLs were not tested over the network.
- Expo with a WebView for existing pages and native push notifications was recommended. This is the proposed implementation approach, not an already completed integration.
- The Electron source location was not identified or inspected. Do not claim its existing notification behavior has been verified.

## Repositories and configured endpoints

| Project | Local source | Configured site |
| --- | --- | --- |
| Areviews admin and live chat | `C:\xampp\htdocs\areviewsapp` | `https://areviewsapp.com` |
| Helpdesk tickets and replies | `C:\xampp\htdocs\helpdesk` | `https://helpdesk.areviewsapp.com` |

The Areviews local `.env` sets `APP_URL=https://areviewsapp.com` and `LIVE_CHAT_TICKET_URL=https://helpdesk.areviewsapp.com/api/v1/external/tickets`. These are configuration findings, not proof of the currently deployed configuration.

The task workspace is Helpdesk. The separate Areviews repository was inspected read-only. A mobile project directory has not been chosen. Follow workspace permissions when beginning implementation in another directory.

## Existing routes and integration points

### Areviews admin and chat

`C:\xampp\htdocs\areviewsapp\bootstrap\app.php` loads `routes/admin.php` with the `web` middleware and `/admin` prefix.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/admin` | Main admin dashboard |
| GET/POST | `/support/login` | Agent login form and login submission |
| POST | `/support/logout` | Agent logout |
| GET | `/admin/live-chat` | Live-chat inbox page |
| GET | `/admin/live-chat/threads` | Chat list |
| GET | `/admin/live-chat/dashboard` | Dashboard statistics as JSON, not a standalone dashboard page |
| GET | `/admin/live-chat/{thread}/messages` | Conversation messages |
| POST | `/admin/live-chat/{thread}/claim` | Take a conversation |
| POST | `/admin/live-chat/{thread}/reply` | Send an agent reply |
| POST | `/admin/live-chat/{thread}/read` | Mark messages read |
| POST | `/admin/live-chat/heartbeat` | Agent availability heartbeat |
| POST | `/admin/live-chat/{thread}/ticket` | Create a Helpdesk follow-up ticket |

Primary source files, relative to Areviews:

- `routes/admin.php` and `routes/web.php`.
- `app/Http/Controllers/Admin/LiveChatAgentController.php`.
- `app/Services/LiveChat.php`.
- `app/Events/LiveChatUpdated.php`.
- `app/Models/LiveChatAgent.php`.
- `app/Http/Middleware/LiveChatAgentAccess.php` and `IsAdmin.php`.
- `resources/views/admin/live_chat/index.blade.php`.
- `public/js/live-chat-agent.js` and `public/js/live-chat-transport.js`.
- `config/livechat.php`.

Chat currently broadcasts a generic `chat.updated` event through Laravel Reverb on private thread and agent channels. Its payload contains a thread ID, and clients fetch authorized data. This is a live-update mechanism, not an implemented Android push channel. Do not turn every generic update into a spoken new-chat alert: reads, system messages, and other changes can also produce updates.

The live-chat agent login and main admin access are distinct. Chat routes use `LiveChatAgentAccess`; the main dashboard uses the existing `admin` middleware. Preserve those access rules. Do not assume one login automatically authenticates the mobile app to both Areviews and Helpdesk.

### Helpdesk

The Helpdesk frontend uses Vue and Inertia. Inspect installed versions before implementation; no Expo SDK has been chosen.

Relevant Helpdesk files:

- `routes/web.php`: browser login, ticket pages, and session-authenticated `/api/v1` endpoints.
- `routes/api.php`: API-key-authenticated `/api/v1/external/tickets` creation endpoint.
- `app/Services/IncomingMail.php`: email import, existing-ticket matching, and new-ticket creation.
- `app/Http/Controllers/ExternalTicketController.php`: external ticket creation with request replay handling.
- `app/Http/Controllers/TicketController.php`: ticket creation and message actions.
- `routes/console.php`: mailbox synchronization is scheduled every minute.

The Areviews chat controller already calls the external Helpdesk ticket endpoint and saves the remote ticket ID/URL. This does not establish an existing notification feed for new tickets or replies.

## Proposed implementation

1. Create a small Expo Android app with native `expo-notifications` and a WebView for the existing support pages. Avoid a full native rewrite for the first version.
2. Bundle the three spoken recordings. Create a separate Android notification channel for each event so each can have its own sound and user-controlled settings.
3. Authenticate the owner and register the installation's push token on the server. Bind registration to the authenticated identity; provide token refresh and revocation on logout.
4. Emit explicit notification events from Areviews for new chats and Helpdesk for new tickets/customer replies, only after the related database transaction commits.
5. Send visible notifications through Expo Push Service, which uses Firebase Cloud Messaging on Android. Configure Firebase Android credentials and EAS/internal APK builds. Google Play publication is not required.
6. Include an event ID, event type, and authorized ticket/chat identifier in the payload. Resolve taps to allowed application routes, including cold starts and login-required cases.
7. Keep Reverb for live chat while the app is open. Push delivery must originate on the servers and must not depend on the Electron app or a WebView staying open.

The token registry can be shared through an authenticated server-to-server integration or maintained per backend. Choose this after reviewing existing authentication and deployment conventions. Never embed an admin API key or Firebase service-account credentials in the APK or WebView JavaScript. A push token identifies an installation; it does not authenticate the user.

### Event rules to implement carefully

- New ticket: notify once when a qualifying ticket is first created. Do not also send a new-reply notification for its initial message.
- New reply: notify for a newly persisted customer reply to an existing ticket. Exclude the owner's outgoing replies, internal notes, delivery updates, and duplicate email imports.
- New chat: agree on the exact transition before implementing. The recommended trigger is a chat entering the human-agent waiting queue, rather than every AI conversation or every subsequent chat message. The user has not explicitly settled this distinction.
- Follow-up tickets created by the owner, spam/blocked tickets, and historical imports need an explicit inclusion policy; do not silently assume they should all make noise.
- Use stable event IDs and durable deduplication so retries and replayed requests do not intentionally generate additional notifications. Provider delivery itself may still duplicate or fail.

## Critical live-chat availability issue

`LiveChatAgent::scopeAvailable()` requires an active agent with an online session heartbeat newer than five minutes. `LiveChat::reconcile()` expires older sessions. `LiveChat::handoff()` requires an available agent before moving a chat from AI to the waiting queue.

Consequently, if the mobile app is the only active client and its browser heartbeat stops in the background, the owner can become unavailable and a customer may never enter the waiting queue that would generate the alert.

Resolve this as part of the mobile design. A possible approach is explicit mobile availability with a deliberate expiry/offline policy, independent of an always-running browser heartbeat. The exact policy is not agreed. Do not mark every registered device permanently online, bypass existing paid-plan/assignment rules, or rely on continuous background JavaScript execution.

## Reliability and device behavior

- Keep backend workers and schedulers running independently of the desktop app.
- Queue push delivery, retry transient failures with backoff, check Expo receipts, and deactivate invalid tokens. A successful receipt means provider handoff, not proof that the owner heard the alert.
- Normal visible push notifications can be presented when the app is backgrounded or closed. Android force-stop prevents notifications until the app is reopened.
- Notification permission, channel settings, volume, Do Not Disturb, network conditions, and device power management affect delivery and sound. Do not promise guaranteed instant delivery or bypasses of system controls.
- Email notification latency includes mailbox import time; the existing schedule checks every minute, plus queue/import time.
- Test push with a native development build and then the privately installed release APK. Expo Go is not the Android remote-push test target.
- Bundled recordings allow speech playback without an online text-to-speech request, but receiving remote events still requires network connectivity.
- Keep payloads minimal and respect the owner's lock-screen preview preferences.

## Implementation sequence and verification

1. Read the applicable repository instructions and matching `.ai/rules` before editing. Confirm installed PHP/JS package versions and mobile tooling. Choose the mobile project location.
2. Inspect the Electron wrapper if needed for UI parity. Confirm phone model, owner identity/account mapping, new-chat trigger, and mobile availability behavior.
3. Produce a minimal installable APK with the three audio channels and authenticated device registration.
4. Connect the three backend events with after-commit delivery, deduplication, queue retries, and receipt handling.
5. Add ticket/chat navigation and verify login expiration, logout, and per-record authorization across both sites.
6. Test on the owner's physical phone: foreground, background, locked screen, app swiped away, notification tap from a cold start, permission denied, muted channel, network reconnection, and force-stop/reopen.
7. Verify one sound per qualifying event; test duplicate imports/replayed requests, initial ticket messages, outgoing replies, and read/status updates. Verify new chats can reach the owner after the app has been backgrounded longer than five minutes under the agreed availability policy.
8. Deliver the private release APK and concise installation steps. Preserve the signing identity for future APK updates.

For Helpdesk PHP work, follow its AGENTS.md, run focused PHPUnit coverage for changed behavior, and run `vendor/bin/pint --dirty --format agent`. Dependency changes require approval under the repository instructions. This handover request does not authorize deployment or account creation.

## Inspection limitations and open items

- `php artisan route:list` in Areviews currently fails because the unrelated `App\Http\Controllers\Admin\QueryLogController` is missing. Relevant routes were verified from their definitions and bootstrap registration, not from a successful route-list run.
- A broad push-integration search encountered an access-denied error for `app/shopify/Graphql.php`. No mobile push integration was found in the inspected relevant sources; this was not an exhaustive audit of every file.
- Firebase/EAS account setup, Android package ID, signing credentials, voice assets, and mobile project folder remain to be established.
- Scope of main-admin access versus support-only pages has not been explicitly settled. Reuse the needed ticket/chat views first; do not assume the entire admin dashboard needs a mobile redesign.
- The user asked for a handover at this stage. Continue implementation when requested, using the established three-alert scope rather than reopening the basic requirements.

## Official references

- [Expo notification API, custom sounds, and Android channels](https://docs.expo.dev/versions/latest/sdk/notifications/)
- [Expo push setup](https://docs.expo.dev/push-notifications/push-notifications-setup/)
- [Notification behavior when backgrounded or terminated](https://docs.expo.dev/push-notifications/what-you-need-to-know/)
- [Expo push delivery limitations and receipts](https://docs.expo.dev/push-notifications/faq/)
- [Private APK distribution](https://docs.expo.dev/build/internal-distribution/)
- [Expo WebView](https://docs.expo.dev/versions/latest/sdk/webview/)
