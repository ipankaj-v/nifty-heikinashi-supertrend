<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NIFTY Paper Desk</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#14251f;background:#f3f6f4;font-synthesis:none;text-rendering:optimizeLegibility}
        *{box-sizing:border-box}body{margin:0;min-width:320px}.shell{max-width:1440px;margin:auto;padding:28px 34px 52px}
        .topbar,.brand,.top-actions,.section-head,.status-line,.trade-top{display:flex;align-items:center;justify-content:space-between;gap:18px}
        .brand{justify-content:flex-start}.mark{display:grid;place-items:center;width:42px;height:42px;background:#123d32;color:#fff;border-radius:13px;font-weight:800;letter-spacing:-1px}
        .brand-name{font-weight:750;letter-spacing:-.4px}.brand-sub{display:block;color:#788780;font-size:12px;margin-top:3px}
        .paper-tag,.status-pill,.chip{border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700;letter-spacing:.02em}
        .paper-tag{color:#176348;background:#e4f4eb}.status-pill{display:inline-flex;align-items:center;gap:7px;background:#fff;color:#5d6e65;border:1px solid #e6ece8}.dot{width:7px;height:7px;border-radius:50%;background:#c98c2c}.dot.ok{background:#23966a}
        button{font:inherit;border:0;cursor:pointer}.primary{background:#176348;color:#fff;padding:11px 16px;border-radius:10px;font-weight:700;box-shadow:0 6px 14px #17634824}.primary:hover{background:#124d38}.primary:disabled{opacity:.5;cursor:not-allowed}
        .sound-toggle{background:#fff;color:#315348;border:1px solid #dce7e1;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700}.sound-toggle[aria-pressed=true]{background:#e4f4eb;color:#176348}.sound-toggle:disabled{opacity:.55;cursor:not-allowed}
        .intro{display:flex;justify-content:space-between;align-items:flex-end;margin:42px 0 23px}.eyebrow{font-size:11px;color:#74847b;text-transform:uppercase;letter-spacing:.14em;font-weight:750}.intro h1{font-size:32px;letter-spacing:-1.3px;margin:9px 0 8px}.intro p{margin:0;color:#6b7972;font-size:14px}
        .status-line{justify-content:flex-start;color:#718078;font-size:12px;margin-bottom:20px}
        .setup{border:1px solid #f1d59d;background:#fff9eb;border-radius:14px;padding:17px 19px;margin-bottom:20px;color:#604a20}.setup strong{display:block;margin-bottom:6px}.setup p{margin:0 0 10px;color:#79653b;font-size:13px;line-height:1.55}.setup code{font-size:12px;background:#f7ecd1;padding:2px 5px;border-radius:4px}.setup ul{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px;margin:10px 0 0;padding-left:18px;font:12px ui-monospace,monospace}
        .metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid #e7ece9;border-radius:15px;padding:19px 20px;box-shadow:0 4px 16px #17332805}.metric-label{color:#718078;font-size:12px;font-weight:650}.metric-value{font-size:23px;font-weight:760;letter-spacing:-.7px;margin:12px 0 6px;overflow-wrap:anywhere}.metric-note{font-size:12px;color:#88958e}.positive{color:#16744f}.negative{color:#bf514d}.muted{color:#849189}
        .columns{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(310px,.8fr);gap:16px;margin-bottom:16px}.section-head{margin-bottom:16px}.section-head h2{font-size:15px;margin:0;letter-spacing:-.2px}.section-head span{font-size:11px;color:#8a968f}
        .position-empty{border:1px dashed #dfe7e2;border-radius:11px;padding:23px;text-align:center;color:#87938c;font-size:13px}
        .position{display:flex;justify-content:space-between;align-items:center;gap:16px;background:#f6faf7;border-radius:11px;padding:15px}.position-symbol{font-weight:750;font-size:16px}.position-detail{color:#77867d;font-size:12px;margin-top:6px;line-height:1.6}.chip{padding:5px 8px;color:#196547;background:#e3f3ea}
        .table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th{text-align:left;color:#89968f;font-size:10px;text-transform:uppercase;letter-spacing:.09em;font-weight:700;padding:0 10px 11px}td{padding:12px 10px;border-top:1px solid #eef2ef;font-size:12px;color:#405149}td:first-child,th:first-child{padding-left:0}.empty-row{text-align:center;color:#8a968f;padding:24px 8px;font-size:13px}
        .bottom-note{color:#8a968f;font-size:11px;text-align:center;margin-top:22px}.toast{position:fixed;right:24px;bottom:24px;max-width:min(420px,calc(100vw - 32px));background:#18392e;color:white;border-radius:12px;padding:14px 17px;box-shadow:0 12px 35px #0e29234d;font-size:13px;opacity:0;transform:translateY(12px);transition:.2s;pointer-events:none;z-index:5}.toast.show{opacity:1;transform:translateY(0)}.toast.error{background:#833c38}
        @media(max-width:900px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}}
        @media(max-width:560px){.shell{padding:18px 15px 34px}.topbar{align-items:flex-start}.top-actions{gap:8px;align-items:flex-end;flex-direction:column}.intro{margin-top:32px;align-items:flex-start;gap:15px;flex-direction:column}.intro h1{font-size:28px}.metrics{gap:9px}.card{padding:15px}.metric-value{font-size:19px}.setup ul{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand">
            <div class="mark">N</div>
            <div><span class="brand-name">NIFTY Paper Desk</span><span class="brand-sub">Heikin Ashi · Supertrend</span></div>
        </div>
        <div class="top-actions">
            <span class="paper-tag">PAPER MODE · NO LIVE ORDERS</span>
            <span class="status-pill"><span id="status-dot" class="dot {{ $isConfigured ? 'ok' : '' }}"></span><span id="connection-status">{{ $isConfigured ? 'Angel One configured' : 'Setup required' }}</span></span>
            <button id="flip-sound-toggle" class="sound-toggle" type="button" aria-pressed="false" disabled>Enable flip sound</button>
        </div>
    </header>

    <main>
        <section class="intro">
            <div>
                <div class="eyebrow">Strategy monitor</div>
                <h1>Signal & paper-trade desk</h1>
                <p>NIFTY futures · {{ $settings['timeframe'] }} Heikin Ashi · Supertrend flip</p>
            </div>
            <span class="paper-tag">Live feed · {{ $settings['timeframe'] }} candles</span>
        </section>

        <div class="status-line"><span id="stream-message">Waiting for the WebSocket worker.</span><span id="last-scan"></span></div>

        @unless ($isConfigured)
            <section class="setup">
                <strong>Connect Angel One SmartAPI</strong>
                <p>Add your SmartAPI values to the server-side <code>.env</code> file, then restart Laravel. Do not paste credentials into this page or commit them. Start the market feed from a terminal with <code>php artisan angelone:stream</code> and keep that process running.</p>
                <ul>
                    <li>ANGELONE_API_KEY</li>
                    <li>ANGELONE_CLIENT_CODE</li>
                    <li>ANGELONE_PIN</li>
                    <li>ANGELONE_TOTP_SECRET</li>
                    <li>ANGELONE_CLIENT_LOCAL_IP</li>
                    <li>ANGELONE_CLIENT_PUBLIC_IP</li>
                    <li>ANGELONE_MAC_ADDRESS</li>
                    <li>ANGELONE_PAPER_LOTS (defaults to 1)</li>
                </ul>
            </section>
        @endunless

        <section class="metrics">
            <article class="card"><div id="future-label" class="metric-label">NIFTY futures LTP</div><div id="future-price" class="metric-value">—</div><div id="future-symbol" class="metric-note">Waiting for market data</div></article>
            <article class="card"><div class="metric-label">Supertrend direction</div><div id="trend" class="metric-value">—</div><div class="metric-note">Heikin Ashi · ATR {{ $settings['atrPeriod'] }} · multiplier {{ $settings['multiplier'] }}</div></article>
            <article class="card"><div class="metric-label">Supertrend value</div><div id="supertrend-price" class="metric-value">—</div><div id="supertrend-note" class="metric-note">Last closed candle</div></article>
            <article class="card"><div class="metric-label">Futures candle close + {{ number_format((float) $settings['referenceOffset'], 2, '.', '') }}%</div><div id="reference-price" class="metric-value">—</div><div id="reference-note" class="metric-note">Based on the last closed futures candle</div></article>
            <article class="card"><div class="metric-label">ATM CE · <span id="atm-ce-contract">Waiting for ATM</span></div><div id="atm-ce-price" class="metric-value">—</div><div id="atm-ce-note" class="metric-note">Waiting for option tick</div></article>
            <article class="card"><div class="metric-label">ATM PE · <span id="atm-pe-contract">Waiting for ATM</span></div><div id="atm-pe-price" class="metric-value">—</div><div id="atm-pe-note" class="metric-note">Waiting for option tick</div></article>
            <article class="card"><div class="metric-label">Realized paper P&amp;L</div><div id="realized-pnl" class="metric-value">₹0.00</div><div class="metric-note">Closed positions only</div></article>
        </section>

        <section class="columns">
            <article class="card">
                <div class="section-head"><h2>Open paper position</h2><span>Exit at 0.10% favorable futures move</span></div>
                <div id="open-position" class="position-empty">No open paper position.</div>
            </article>
            <article class="card">
                <div class="section-head"><h2>Strategy rules</h2><span>Current settings</span></div>
                <div class="position">
                    <div><div class="position-symbol">5-minute flip → directional 0.10% trigger → sell ATM</div><div class="position-detail">Bearish: futures falls 0.10% from flip close → sell monthly CE · Bullish: rises 0.10% from flip close → sell monthly PE<br>{{ $settings['paperLots'] }} lot(s) · paper-only</div></div>
                    <span class="chip">PAPER</span>
                </div>
                <div class="position-detail" style="padding:12px 3px 0">Exit when NIFTY futures moves a further 0.10% in the favorable direction from entry. No stop-loss is configured; new flips are ignored while a position is open.</div>
            </article>
        </section>

        <section class="card" style="margin-bottom:16px">
            <div class="section-head"><h2>Recent signal alerts</h2><span>Latest 20 · newest first</span></div>
            <div class="table-wrap"><table><thead><tr><th>Entry time</th><th>Flip</th><th>Flip close</th><th>Reference +0.10%</th><th>Entry trigger</th><th>NIFTY entry</th><th>Paper action</th><th>Option LTP</th></tr></thead><tbody id="alerts-body"><tr><td class="empty-row" colspan="8">No entry alerts yet.</td></tr></tbody></table></div>
        </section>

        <section class="card">
            <div class="section-head"><h2>Paper-trade history</h2><span>Latest 20 positions</span></div>
            <div class="table-wrap"><table><thead><tr><th>Entry time</th><th>Contract</th><th>Qty</th><th>Entry premium</th><th>Exit premium</th><th>NIFTY entry</th><th>NIFTY exit</th><th>Realized P&amp;L</th><th>Status</th></tr></thead><tbody id="trades-body"><tr><td class="empty-row" colspan="9">No paper trades yet.</td></tr></tbody></table></div>
        </section>
        <p class="bottom-note">Market-data powered by Angel One SmartAPI. This app records simulated positions only and never submits orders.</p>
    </main>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script>
    const currency = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 });
    const dateTime = new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' });
    const tickDateTime = new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'medium' });
    const referenceOffsetPercent = {{ (float) $settings['referenceOffset'] }};
    let knownAlertIds = new Set();
    let knownFlipEventIds = new Set();
    let flipSoundEnabled = false;
    let flipAudioContext = null;
    let toastTimer;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[character]);
    }

    function money(value) {
        return value === null || value === undefined ? '—' : currency.format(Number(value));
    }

    function time(value) {
        return value ? dateTime.format(new Date(value)) : '—';
    }

    function tickTime(value) {
        return value ? tickDateTime.format(new Date(value)) : '—';
    }

    function showToast(message, isError = false) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.classList.toggle('error', isError);
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 4200);
    }

    function playFlipSound() {
        if (!flipSoundEnabled || !flipAudioContext) return;

        const startAt = flipAudioContext.currentTime;
        [880, 660].forEach((frequency, index) => {
            const oscillator = flipAudioContext.createOscillator();
            const gain = flipAudioContext.createGain();
            const noteStart = startAt + index * 0.18;

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, noteStart);
            gain.gain.setValueAtTime(0.001, noteStart);
            gain.gain.exponentialRampToValueAtTime(0.16, noteStart + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, noteStart + 0.14);
            oscillator.connect(gain);
            gain.connect(flipAudioContext.destination);
            oscillator.start(noteStart);
            oscillator.stop(noteStart + 0.15);
        });
    }

    function renderDashboard(data) {
        const dot = document.getElementById('status-dot');
        const stream = data.stream_status;
        const soundButton = document.getElementById('flip-sound-toggle');
        soundButton.disabled = false;
        const snapshot = data.snapshot;
        const pendingEntry = stream?.pending_entry;
        const hasAtmSupport = Boolean(stream && Object.prototype.hasOwnProperty.call(stream, 'atm_strike'));
        const workerNeedsRestart = Boolean(data.configured && stream?.heartbeat_at && !hasAtmSupport);
        const hasSupertrendValue = snapshot?.supertrend_price !== null
            && snapshot?.supertrend_price !== undefined
            && Number.isFinite(Number(snapshot.supertrend_price));
        const heartbeatIsRecent = stream?.heartbeat_at
            && Date.now() - new Date(stream.heartbeat_at).getTime() < 45000;
        const tickIsRecent = stream?.last_tick_at
            && Date.now() - new Date(stream.last_tick_at).getTime() < 90000;
        const hasLivePrice = Boolean(tickIsRecent && stream?.live_price !== null && stream?.live_price !== undefined);
        const isConnected = Boolean(data.configured && heartbeatIsRecent && !['reconnecting', 'error', 'stopped'].includes(stream.state));
        document.getElementById('connection-status').textContent = !data.configured
            ? 'Setup required'
            : isConnected
                ? 'WebSocket connected'
                : 'Stream worker stopped';
        dot.classList.toggle('ok', isConnected);

        const minimumCandles = {{ (int) $settings['atrPeriod'] + 2 }};
        document.getElementById('stream-message').textContent = !data.configured
            ? 'Add Angel One settings to the server .env file.'
            : !heartbeatIsRecent
                ? 'Start one worker with php artisan angelone:stream.'
                : stream.state === 'reconnecting'
                    ? 'WebSocket disconnected; the worker is retrying.'
                    : stream.state === 'stopped'
                        ? 'Restart the stream worker for the new market session.'
                    : stream.state === 'error'
                        ? 'Candle processing failed; check the Laravel log.'
                        : workerNeedsRestart
                            ? 'This worker predates the Supertrend-value and ATM quote updates. Stop it with Ctrl+C and rerun php artisan angelone:stream.'
                        : !tickIsRecent
                            ? 'WebSocket connected; waiting for NIFTY futures ticks.'
                        : pendingEntry
                            ? `Waiting for ${pendingEntry.direction} entry · NIFTY ${money(pendingEntry.entry_trigger_price)} to sell monthly ${pendingEntry.option_type}.`
                        : stream.state === 'warming_up'
                            ? stream.history_retry_at
                                ? `WebSocket live · historical backfill retry at ${tickTime(stream.history_retry_at)}; warming up ${stream.candle_count || 0}/${minimumCandles} closed candles.`
                                : `WebSocket live · warming up ${stream.candle_count || 0}/${minimumCandles} closed candles.`
                            : `WebSocket live · ${stream.candle_count || 0} closed candles ready.`;

        document.getElementById('future-label').textContent = hasLivePrice ? 'NIFTY futures LTP' : 'Last NIFTY futures close';
        document.getElementById('future-price').textContent = hasLivePrice
            ? money(stream.live_price)
            : snapshot
                ? money(snapshot.futures_price)
                : '—';
        document.getElementById('future-symbol').textContent = hasLivePrice
            ? `${stream.future_symbol} · tick ${tickTime(stream.last_tick_at)}`
            : snapshot
                ? `${snapshot.futures_symbol} · closed candle`
                : 'Waiting for market data';
        const trend = document.getElementById('trend');
        trend.textContent = snapshot
            ? snapshot.direction.toUpperCase()
            : stream?.state === 'warming_up'
                ? 'WARMING UP'
                : '—';
        trend.className = 'metric-value ' + (snapshot?.direction === 'bullish' ? 'positive' : snapshot?.direction === 'bearish' ? 'negative' : '');
        document.getElementById('supertrend-price').textContent = hasSupertrendValue ? money(snapshot.supertrend_price) : '—';
        document.getElementById('supertrend-note').textContent = hasSupertrendValue
            ? `Last closed Heikin Ashi candle · opened ${tickTime(snapshot.candle_time)}`
            : workerNeedsRestart
                ? 'Restart stream worker to enable this value'
                : 'Available after the next closed candle';
        document.getElementById('reference-price').textContent = snapshot ? money(snapshot.reference_price) : '—';
        document.getElementById('reference-note').textContent = snapshot
            ? `Futures close ${money(snapshot.futures_price)} + ${referenceOffsetPercent.toFixed(2)}% · 5-minute candle opened ${tickTime(snapshot.candle_time)}`
            : 'Waiting for a closed futures candle';

        for (const optionType of ['ce', 'pe']) {
            const symbol = stream?.[`atm_${optionType}_symbol`];
            const quoteTime = stream?.[`atm_${optionType}_time`];
            const quotePrice = stream?.[`atm_${optionType}_price`];
            document.getElementById(`atm-${optionType}-contract`).textContent = symbol || (hasAtmSupport ? 'Selecting ATM' : 'Worker update needed');
            document.getElementById(`atm-${optionType}-price`).textContent = money(quotePrice);
            document.getElementById(`atm-${optionType}-note`).textContent = stream?.atm_error
                ? `ATM ${stream.atm_target_strike || ''} unavailable; check Laravel log`
                : !hasAtmSupport
                    ? 'Restart stream worker to enable ATM quotes'
                : stream?.atm_strike
                    ? `Futures ATM ${stream.atm_strike} · ${stream.atm_expiry || 'monthly expiry'} · ${quoteTime ? 'tick ' + tickTime(quoteTime) : 'waiting for option tick'}`
                    : 'Waiting for NIFTY futures tick';
        }

        const pnl = Number(data.realized_pnl || 0);
        const pnlElement = document.getElementById('realized-pnl');
        pnlElement.textContent = money(pnl);
        pnlElement.className = 'metric-value ' + (pnl > 0 ? 'positive' : pnl < 0 ? 'negative' : '');

        const open = data.open_trade;
        document.getElementById('open-position').innerHTML = open
            ? `<div class="position"><div><div class="position-symbol">${escapeHtml(open.symbol)} <span class="chip">SELL ${escapeHtml(open.direction)}</span></div><div class="position-detail">${Number(open.quantity).toLocaleString('en-IN')} qty · monthly expiry ${escapeHtml(open.expiry)} · ${escapeHtml(open.strike)} strike<br>Opened ${escapeHtml(time(open.entry_time || open.signal_time))} · NIFTY entry ${escapeHtml(money(open.entry_futures_price))} · target ${escapeHtml(money(open.futures_target_price))}</div></div><div style="text-align:right"><strong>${escapeHtml(money(open.entry_price))}</strong><div class="position-detail">entry premium</div></div></div>`
            : '<div class="position-empty">No open paper position.</div>';

        const alerts = data.alerts || [];
        document.getElementById('alerts-body').innerHTML = alerts.length
            ? alerts.map((alert) => `<tr><td>${escapeHtml(time(alert.entry_time || alert.signal_time))}</td><td class="${alert.direction === 'bearish' ? 'negative' : 'positive'}">${escapeHtml(alert.direction.toUpperCase())}</td><td>${escapeHtml(money(alert.futures_price))}</td><td>${escapeHtml(money(alert.reference_price))}</td><td>${escapeHtml(money(alert.entry_trigger_price))}</td><td>${escapeHtml(money(alert.entry_futures_price))}</td><td>SELL ${escapeHtml(alert.option_type)} · ${escapeHtml(alert.strike)} monthly</td><td>${escapeHtml(money(alert.option_price))}</td></tr>`).join('')
            : '<tr><td class="empty-row" colspan="8">No entry alerts yet.</td></tr>';

        const trades = data.trades || [];
        document.getElementById('trades-body').innerHTML = trades.length
            ? trades.map((trade) => `<tr><td>${escapeHtml(time(trade.entry_time || trade.signal_time))}</td><td>${escapeHtml(trade.symbol)}</td><td>${Number(trade.quantity).toLocaleString('en-IN')}</td><td>${escapeHtml(money(trade.entry_price))}</td><td>${escapeHtml(money(trade.exit_price))}</td><td>${escapeHtml(money(trade.entry_futures_price))}</td><td>${escapeHtml(money(trade.exit_futures_price))}</td><td class="${Number(trade.realized_pnl || 0) >= 0 ? 'positive' : 'negative'}">${escapeHtml(money(trade.realized_pnl))}</td><td>${escapeHtml(trade.status.toUpperCase())}</td></tr>`).join('')
            : '<tr><td class="empty-row" colspan="9">No paper trades yet.</td></tr>';

        document.getElementById('last-scan').textContent = data.last_scan_at
            ? 'Last closed candle ' + time(data.last_scan_at)
            : '';

        for (const flipEvent of data.flip_events || []) {
            if (!flipEvent.id || knownFlipEventIds.has(flipEvent.id)) continue;

            knownFlipEventIds.add(flipEvent.id);

            if (flipSoundEnabled) {
                playFlipSound();
                showToast(`${flipEvent.direction.toUpperCase()} Supertrend flip · ${time(flipEvent.signal_time)}`);
            }
        }

        for (const alert of alerts) {
            if (!knownAlertIds.has(alert.id)) {
                if (knownAlertIds.size > 0) {
                    showToast(`${alert.direction.toUpperCase()} entry · SELL ${alert.option_type} ${alert.strike} · ${alert.option_symbol}`);
                    if ('Notification' in window && Notification.permission === 'granted') {
                        new Notification('NIFTY paper-trade entry', { body: `${alert.direction.toUpperCase()} · SELL ${alert.option_symbol}` });
                    }
                }
                knownAlertIds.add(alert.id);
            }
        }
    }

    document.getElementById('flip-sound-toggle').addEventListener('click', async (event) => {
        const button = event.currentTarget;

        if (flipSoundEnabled) {
            flipSoundEnabled = false;
            button.setAttribute('aria-pressed', 'false');
            button.textContent = 'Enable flip sound';

            return;
        }

        if (!('AudioContext' in window)) {
            showToast('This browser does not support flip audio.', true);

            return;
        }

        try {
            flipAudioContext ??= new AudioContext();
            await flipAudioContext.resume();
            flipSoundEnabled = true;
            button.setAttribute('aria-pressed', 'true');
            button.textContent = 'Flip sound on';
            playFlipSound();
            showToast('Flip sound enabled for this tab.');
        } catch (error) {
            showToast(`Could not enable flip sound: ${error instanceof Error ? error.message : 'browser audio could not start.'}`, true);
        }
    });

    async function refreshDashboard() {
        try {
            const response = await fetch('{{ route('dashboard.data') }}', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Could not load dashboard data.');
            renderDashboard(await response.json());
        } catch (error) {
            document.getElementById('stream-message').textContent = error.message;
        }
    }

    refreshDashboard();
    setInterval(refreshDashboard, 5000);
</script>
</body>
</html>
