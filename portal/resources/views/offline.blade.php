{{-- =============================================================
     Offline fallback page — served by the service worker when a
     navigation fails (device offline, portal unreachable).

     Deliberately standalone: NO layout, NO @vite, NO Livewire.
     Every asset on this page must come from the SW cache or be
     inline, or the page itself won't render offline.
     ============================================================= --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You are offline — Customer Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f7f7f8;
            color: #1a1a1a;
            padding: 1.5rem;
        }
        .card {
            max-width: 34rem;
            width: 100%;
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
        }
        .dot {
            display: inline-block;
            width: 0.75rem; height: 0.75rem;
            border-radius: 9999px;
            background: #d97706;
            margin-bottom: 0.75rem;
        }
        h1 { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; }
        p  { font-size: 0.9rem; color: #52525b; line-height: 1.6; }
        p + p { margin-top: 0.75rem; }
        .hint { background: #fffbeb; border: 1px solid #fde68a; border-radius: 0.5rem;
                padding: 0.75rem 1rem; margin-top: 1.25rem; font-size: 0.85rem; color: #92400e; }
        button {
            margin-top: 1.25rem;
            background: #4f46e5; color: #fff; border: 0; cursor: pointer;
            font-size: 0.875rem; font-weight: 500;
            padding: 0.5rem 1rem; border-radius: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="dot" aria-hidden="true"></div>
        <h1>You're offline</h1>
        <p>
            This page needs an internet connection. Anything you already saved
            — including service reports queued on this device — is safe and will
            sync to Monday.com automatically once you're back online.
        </p>
        <p class="hint">
            If you were filling in a service report, use the browser's Back button
            to return to the form. Your work is kept as a draft on this device.
        </p>
        <button onclick="location.reload()">Try again</button>
    </div>
</body>
</html>