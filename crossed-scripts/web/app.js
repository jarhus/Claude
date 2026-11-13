/* Crossed Scripts – klient (POC). Serveren avgjør alt; klienten viser og sender handlinger. */
(function () {
  'use strict';

  var app = document.getElementById('app');
  var banner = document.getElementById('banner');
  var hostbar = document.getElementById('hostbar');
  var KEY = 'crossedScripts.session';

  var session = store(KEY);          // { code, token }
  var st = null;                     // siste visning fra serveren
  var sel = {};                      // lokale valg i svarskjemaet
  var selKey = '';
  var notice = '';                   // kort melding fra serveren
  var offline = false;
  var lastSig = '';
  var lastScreen = '';
  var deadlineAt = 0;                // lokal klokke for nedtelling
  var busy = false;
  var prefs = store('crossedScripts.prefs') || { scale: 1, vibe: true };

  function store(key, value) {
    try {
      if (value === undefined) return JSON.parse(localStorage.getItem(key) || 'null');
      if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { /* privat modus: spillet virker uten lagring, men ikke gjenoppkobling */ }
    return value;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function applyPrefs() {
    document.documentElement.style.setProperty('--scale', prefs.scale);
    var b = document.getElementById('vibeBtn');
    b.textContent = prefs.vibe ? 'Vibrasjon på' : 'Vibrasjon av';
    b.setAttribute('aria-pressed', prefs.vibe ? 'true' : 'false');
  }

  function buzz() {
    if (prefs.vibe && navigator.vibrate) { try { navigator.vibrate(120); } catch (e) { /* ikke støttet */ } }
  }

  // ------------------------------------------------------------------ server

  function api(action, data) {
    var body = Object.assign({ action: action }, session || {}, data || {});
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store'
    }).then(function (r) { return r.json(); });
  }

  function send(action, data) {
    if (busy) return Promise.resolve();
    busy = true;
    return api(action, data).then(function (res) {
      busy = false;
      offline = false;
      handle(res);
      return res;
    }).catch(function () {
      busy = false;
      offline = true;
      notice = 'Fikk ikke kontakt med serveren. Prøv igjen.';
      render(true);
    });
  }

  function handle(res) {
    if (!res) return;
    if (res.ok === false) {
      if (res.kind === 'gone') { leave(); notice = res.error; render(true); return; }
      if (res.kind !== 'stale') notice = res.error;
      render(true);
      return;
    }
    if (res.token) { session = store(KEY, { code: res.code, token: res.token }); }
    if (res.notice) notice = res.notice;
    if (res.state) setState(res.state);
  }

  function leave() {
    session = store(KEY, null);
    st = null;
    sel = {};
    lastSig = '';
  }

  function setState(next) {
    var prev = st;
    st = next;
    var r = st.round || {};
    if (st.phase === 'answering') deadlineAt = Date.now() + r.remainingMs;
    var key = st.phase + ':' + st.roundNo + ':' + (r.actors ? r.actors.A + r.actors.B : '');
    if (key !== selKey) { selKey = key; sel = {}; }
    var wasMyTurn = prev && prev.round && prev.phase === 'performing' && prev.round.turn === prev.round.myRole && prev.round.lineIdx === r.lineIdx;
    if (st.phase === 'performing' && r.myRole && r.turn === r.myRole && !wasMyTurn) buzz();
    render(false);
  }

  function poll() {
    if (!session || busy) return;
    api('state').then(function (res) {
      offline = false;
      if (busy) return;
      handle(res);
      if (res && res.ok) render(false);
    }).catch(function () {
      offline = true;
      render(false);
    });
  }

  // ------------------------------------------------------------------- views

  function me() { return st.players.filter(function (p) { return p.id === st.me; })[0] || {}; }
  function nameOf(id) { var p = st.players.filter(function (x) { return x.id === id; })[0]; return p ? p.name : '?'; }
  function isHost() { return st.hostId === st.me; }
  function actorTag(role) {
    return '<span class="actor ' + role + '">' + esc(nameOf(st.round.actors[role])) + '</span>';
  }
  function roundLabel() {
    if (st.round && st.round.practice) return 'Øvingsrunde – uten poeng';
    return 'Runde ' + st.roundNo + ' av ' + st.totalRounds;
  }

  function viewHome() {
    var pre = (new URLSearchParams(location.search).get('kode') || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 5);
    return '' +
      '<div class="center stack"><h1>Samme scene.<br>Ulike hemmeligheter.</h1>' +
      '<p class="muted">To av dere spiller den samme samtalen, men tror dere er i hver deres situasjon. Resten gjetter hva som foregår.</p></div>' +
      '<div class="panel"><div><label for="name">Navnet ditt</label>' +
      '<input type="text" id="name" maxlength="20" autocomplete="nickname" placeholder="F.eks. Ada"></div></div>' +
      '<div class="panel"><h2>Bli med i et spill</h2><div><label for="code">Romkode</label>' +
      '<input type="text" id="code" class="code" maxlength="5" autocapitalize="characters" autocomplete="off" spellcheck="false" value="' + esc(pre) + '"></div>' +
      '<button class="btn primary" data-act="join">Bli med</button></div>' +
      '<div class="panel"><h2>Start et nytt spill</h2><p class="muted">Du blir vert og får en romkode de andre kan bruke.</p>' +
      '<button class="btn" data-act="create">Opprett spill</button></div>';
  }

  function joinUrl() {
    return location.origin + location.pathname.replace(/index\.html$/, '') + '?kode=' + st.code;
  }

  function qrSvg(text) {
    try {
      var q = qrcode(0, 'M');
      q.addData(text);
      q.make();
      return q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    } catch (e) { return ''; }
  }

  function viewLobby() {
    var host = isHost();
    var n = st.players.length;
    var list = st.players.map(function (p) {
      return '<li><span class="name">' + esc(p.name) + (p.id === st.me ? ' (deg)' : '') + '</span>' +
        (p.id === st.hostId ? '<span class="tag">Vert</span>' : '') +
        (p.online ? '' : '<span class="tag off">Frakoblet</span>') +
        (host && p.id !== st.me ? '<button class="btn small ghost" data-act="kick" data-pid="' + p.id + '" aria-label="Fjern ' + esc(p.name) + '">Fjern</button>' : '') +
        '</li>';
    }).join('');
    var s = st.settings;
    var settings = host ? '' +
      '<div class="panel"><h2>Oppsett</h2>' +
      toggle('practice', s.practice, 'Start med en øvingsrunde', 'Én scene uten poeng, så alle lærer knappene.') +
      toggle('actorGuess', s.actorGuess, 'Skuespillerne gjetter på hverandre', '1 poeng for å gjette motspillerens situasjon.') +
      toggle('short', s.length === 'short', 'Kort økt', 'Én opptreden hver i stedet for to. Virker bare med partall.') +
      '</div>' :
      '<div class="panel"><h2>Oppsett</h2><p class="muted">' +
      (s.practice ? 'Øvingsrunde først. ' : '') +
      (s.actorGuess ? 'Skuespillerne gjetter på hverandre. ' : 'Bare tilskuerne gjetter. ') +
      (s.length === 'short' ? 'Kort økt.' : 'Alle spiller to ganger.') + '</p></div>';
    var need = st.minPlayers - n;
    return '' +
      '<div class="panel center"><span class="kicker">Romkode</span><div class="codebox">' + esc(st.code) + '</div>' +
      '<div class="qr" aria-label="QR-kode for å bli med">' + qrSvg(joinUrl()) + '</div>' +
      '<p class="muted">Skann koden, eller åpne ' + esc(location.host) + ' og skriv inn romkoden.</p></div>' +
      '<div class="panel"><h2>Deltakere (' + n + ' av ' + st.maxPlayers + ')</h2><ul class="players">' + list + '</ul></div>' +
      settings +
      '<div class="panel"><h2>Slik spiller dere</h2><ol class="rules">' +
      '<li>Appen velger to skuespillere. De får hvert sitt hemmelige kort.</li>' +
      '<li>De leser replikkene sine høyt, én om gangen, og tror på sin egen situasjon.</li>' +
      '<li>Tilskuerne gjetter hva hver av dem trodde, og stemmer på beste skuespiller.</li>' +
      '<li>1 poeng per riktig gjett. 3 poeng til skuespilleren med flest stemmer.</li></ol>' +
      '<p class="muted">Du trenger ikke kunne spille skuespill. Les replikken, og prøv å tro på situasjonen din.</p></div>' +
      (host ?
        '<button class="btn primary big" data-act="start"' + (need > 0 ? ' disabled' : '') + '>' +
        (need > 0 ? 'Venter på ' + need + ' til' : 'Start spillet') + '</button>' :
        '<p class="center muted">Venter på at ' + esc(nameOf(st.hostId)) + ' starter spillet.</p>');
  }

  function toggle(key, on, title, help) {
    return '<label class="toggle"><input type="checkbox" data-set="' + key + '"' + (on ? ' checked' : '') + '>' +
      '<span>' + title + '<small>' + help + '</small></span></label>';
  }

  function head() {
    var r = st.round;
    return '<div class="center stack"><span class="kicker">' + roundLabel() + '</span>' +
      '<div class="versus">' + actorTag('A') + ' <span class="muted">og</span> ' + actorTag('B') + '</div>' +
      (r.reused ? '<p class="muted">Gjenspilling: alle scenene i pakken er brukt.</p>' : '') + '</div>';
  }

  function viewPrep() {
    var r = st.round;
    if (!r.myRole) {
      return head() + '<div class="panel center"><h2>Skuespillerne leser kortene sine</h2>' +
        '<p class="muted">Følg med på de to når scenen starter. Etterpå skal du gjette hva hver av dem trodde samtalen handlet om.</p>' +
        '<ul class="players">' + ['A', 'B'].map(function (x) {
          return '<li><span class="name">' + esc(nameOf(r.actors[x])) + '</span><span class="tag ' + (r.ready[x] ? 'ok' : '') + '">' + (r.ready[x] ? 'Klar' : 'Leser') + '</span></li>';
        }).join('') + '</ul></div>';
    }
    var other = r.myRole === 'A' ? 'B' : 'A';
    var c = r.card;
    var ready = r.ready[r.myRole];
    return head() +
      '<div class="card"><h2>Ditt hemmelige kort</h2><dl>' +
      '<dt>Situasjonen</dt><dd>' + esc(c.situation) + '</dd>' +
      '<dt>Hvem du snakker med</dt><dd>' + esc(c.other) + '</dd>' +
      '<dt>Målet ditt</dt><dd>' + esc(c.goal) + '</dd>' +
      '<dt>Spillestil</dt><dd>' + esc(c.style) + '</dd></dl></div>' +
      '<p class="muted center">Ikke vis kortet til noen. ' + esc(nameOf(r.actors[other])) + ' har fått et annet kort.</p>' +
      (ready ?
        '<p class="center"><strong>Du er klar.</strong> Venter på ' + esc(nameOf(r.actors[other])) + '.</p>' :
        '<button class="btn primary big" data-act="ready">Klar</button>' +
        '<button class="btn ghost" data-act="swap">Bytt scene</button>');
  }

  function viewPerforming() {
    var r = st.round;
    if (!r.myRole) {
      return head() + '<div class="listen"><span class="ear" aria-hidden="true">🎭</span>Scenen er i gang.<br>Se opp og følg med!</div>';
    }
    var other = r.myRole === 'A' ? 'B' : 'A';
    if (r.turn === r.myRole) {
      return '<div class="center"><span class="kicker">Din replikk ' + (r.myDone + 1) + ' av ' + r.myTotal + '</span></div>' +
        '<div class="linebox">' + esc(r.text) + '</div>' +
        '<p class="muted center">Les høyt til ' + esc(nameOf(r.actors[other])) + ', og trykk når du er ferdig.</p>' +
        '<button class="btn primary big" data-act="next">Neste</button>';
    }
    var left = r.myTotal - r.myDone;
    return '<div class="listen"><span class="ear" aria-hidden="true">👂</span>Lytt til ' + esc(nameOf(r.actors[other])) + '</div>' +
      '<p class="center muted">' + (left > 0 ? 'Din neste replikk er nummer ' + (r.myDone + 1) + ' av ' + r.myTotal + '.' : 'Du har ingen flere replikker.') + '</p>';
  }

  function timerHtml() {
    return '<div class="timer" id="timer"></div>';
  }

  function viewAnswering() {
    var r = st.round;
    var status = '<p class="center muted">' + r.submittedCount + ' av ' + r.eligibleCount + ' har svart.</p>';
    if (!r.eligible) {
      return head() + '<div class="panel center"><h2>Publikum gjetter og stemmer</h2>' +
        '<p class="muted">Ikke røp noe ennå.</p></div>' + timerHtml() + status;
    }
    if (r.receipt) {
      return head() + '<div class="panel center"><h2>Svaret ditt er registrert</h2>' +
        '<p class="muted">Det kan ikke endres. Resultatet vises når alle har svart eller tiden er ute.</p></div>' + timerHtml() + status;
    }
    var html = head() + timerHtml();
    var complete = true;
    r.questions.forEach(function (q) {
      var k = 'g' + q.target;
      if (!sel[k]) complete = false;
      html += '<div class="panel q"><h2>Hva trodde ' + actorTag(q.target) + ' at samtalen handlet om?</h2>' +
        q.options.map(function (o) { return opt(k, o.id, o.text); }).join('') + '</div>';
    });
    if (r.canVote) {
      if (!sel.vote) complete = false;
      html += '<div class="panel q"><h2>Hvem var beste skuespiller?</h2>' +
        opt('vote', 'A', nameOf(r.actors.A)) + opt('vote', 'B', nameOf(r.actors.B)) + opt('vote', 'none', 'Avstår fra å stemme') + '</div>';
    }
    html += '<button class="btn primary big" data-act="submit"' + (complete ? '' : ' disabled') + '>Send inn</button>' +
      (complete ? '' : '<p class="center muted">Velg ett svar i hver boks.</p>') + status;
    return html;
  }

  function opt(key, value, text) {
    var on = sel[key] === value;
    return '<button type="button" class="opt" data-pick="' + key + '" data-value="' + esc(value) + '" aria-pressed="' + on + '">' +
      '<span class="mark" aria-hidden="true">' + (on ? '✓' : '') + '</span><span>' + esc(text) + '</span></button>';
  }

  function scoreTable(final) {
    var rows = st.players.slice().sort(function (a, b) { return b.score - a.score; }).map(function (p) {
      var win = final && final.winners.indexOf(p.id) >= 0;
      return '<tr class="' + (p.id === st.me ? 'me ' : '') + (win ? 'win' : '') + '"><td>' + esc(p.name) + '</td><td>' + p.guess + '</td><td>' + p.acting + '</td>' +
        (final && final.endedEarly ? '<td>' + (final.appearances[p.id] || 0) + '</td>' : '') +
        '<td class="total">' + p.score + '</td></tr>';
    }).join('');
    return '<table class="score"><thead><tr><th>Navn</th><th>Gjett</th><th>Spill</th>' +
      (final && final.endedEarly ? '<th>Spilt</th>' : '') + '<th>Sum</th></tr></thead><tbody>' + rows + '</tbody></table>';
  }

  function viewReveal() {
    var r = st.round;
    var html = '<div class="center stack"><span class="kicker">' + roundLabel() + '</span><h1>Avsløring</h1></div>';
    html += '<div class="panel">' + ['A', 'B'].map(function (t) {
      var q = r.reveal[t];
      var answer = q.options.filter(function (o) { return o.id === q.correct; })[0];
      return '<div class="reveal ' + t + '"><span>' + actorTag(t) + ' trodde dette var:</span><span class="premise">' + esc(answer.text) + '</span>' +
        '<span class="quote">På kortet sto det: «' + esc(q.situation) + '»</span></div>';
    }).join('') + '</div>';

    ['A', 'B'].forEach(function (t) {
      var q = r.reveal[t];
      if (!q.asked) return;
      html += '<div class="panel q"><h2>Hva trodde ' + actorTag(t) + '?</h2>' + q.options.map(function (o) {
        var cls = o.id === q.correct ? 'correct' : (o.id === q.mine ? 'wrong' : '');
        var note = o.id === q.correct ? (o.id === q.mine ? 'Riktig – ditt svar' : 'Riktig') : (o.id === q.mine ? 'Ditt svar' : '');
        return '<div class="opt static ' + cls + '"><span class="mark" aria-hidden="true">' + (o.id === q.correct ? '✓' : (o.id === q.mine ? '✗' : '')) + '</span><span>' + esc(o.text) + '</span>' +
          (note ? '<span class="note">' + note + '</span>' : '') + '</div>';
      }).join('') + (q.mine ? '' : '<p class="muted">Du sendte ikke inn noe svar her.</p>') + '</div>';
    });

    var v = r.votes;
    html += '<div class="panel"><h2>Beste skuespiller</h2><div class="votebar">' +
      '<div><span class="n">' + v.A + '</span>' + actorTag('A') + '</div>' +
      '<div><span class="n">' + v.B + '</span>' + actorTag('B') + '</div>' +
      '<div><span class="n">' + v.abstain + '</span><span class="muted">Avsto</span></div></div>' +
      '<p class="muted">' + voteText(v) + '</p></div>';

    var mine = r.awards.filter(function (a) { return a.pid === st.me; });
    var sum = 0;
    var lines = mine.map(function (a) {
      sum += a.points;
      var label = a.type === 'guess' ? esc(nameOf(r.actors[a.target])) + 's situasjon: riktig' :
        (a.target === 'win' ? 'Beste skuespiller' : 'Delt skuespillerpris');
      return '<li><span>' + label + '</span><span class="plus">+' + a.points + '</span></li>';
    }).join('');
    html += '<div class="panel"><h2>Dine poeng denne runden</h2><ul class="points">' + (lines || '<li><span class="muted">Ingen poeng denne gangen.</span></li>') +
      '<li class="sum"><span>Sum</span><span class="plus">+' + sum + '</span></li></ul>' +
      (r.practice ? '<p class="muted">Dette var øvingsrunden. Poengene teller ikke.</p>' : '') + '</div>';

    if (!r.practice) html += '<div class="panel"><h2>Stillingen</h2>' + scoreTable(null) + '</div>';

    html += isHost() ?
      '<button class="btn primary big" data-act="nextRound">' + (r.practice ? 'Start første runde' : (r.lastRound ? 'Se sluttresultatet' : 'Neste runde')) + '</button>' :
      '<p class="center muted">Venter på at ' + esc(nameOf(st.hostId)) + ' går videre.</p>';
    return html;
  }

  function voteText(v) {
    if (v.A === 0 && v.B === 0) return 'Ingen stemte. Da deles det ikke ut skuespillerpoeng.';
    if (v.A === v.B) return 'Likt. Begge skuespillerne får 1 poeng.';
    return esc(nameOf(st.round.actors[v.A > v.B ? 'A' : 'B'])) + ' fikk flest stemmer og får 3 poeng.';
  }

  function viewFinished() {
    var f = st.final;
    var names = f.winners.map(nameOf).map(esc);
    var title = f.endedEarly ? 'Økten ble avsluttet tidlig' : (names.length > 1 ? 'Delt seier!' : names[0] + ' vant!');
    var sub = f.endedEarly ?
      'Foreløpig stilling. Ikke alle har spilt like mange ganger, så det kåres ingen offisiell vinner.' :
      (names.length > 1 ? names.join(' og ') + ' deler seieren.' : 'Gratulerer!');
    return '<div class="center stack"><span class="kicker">Sluttresultat</span><h1>' + title + '</h1><p class="muted">' + sub + '</p></div>' +
      '<div class="panel">' + scoreTable(f) + '</div>' +
      '<button class="btn primary big" data-act="again">Spill igjen</button>';
  }

  function hostBar() {
    if (!st || !isHost()) {
      if (st && st.hostAway && st.phase !== 'finished') {
        return '<span class="lbl">Verten har vært borte en stund</span><button class="btn small" data-act="claimHost">Ta over som vert</button>';
      }
      return '';
    }
    var p = st.phase;
    if (p !== 'prep' && p !== 'performing' && p !== 'answering' && p !== 'reveal') return '';
    var b = '<span class="lbl">Vertskontroller</span>';
    if (p !== 'reveal') {
      b += st.paused ? '<button class="btn small primary" data-act="resume">Fortsett</button>' : '<button class="btn small" data-act="pause">Pause</button>';
      if (p === 'answering' && !st.paused) b += '<button class="btn small" data-act="extend">+30 sek</button>';
      b += '<button class="btn small" data-act="cancel">Annuller runden</button>';
    }
    b += '<button class="btn small ghost" data-act="end">Avslutt spillet</button>';
    return b;
  }

  function bannerText() {
    if (offline) return 'Ingen kontakt med serveren. Prøver igjen …';
    if (notice) return notice;
    if (!st) return '';
    if (st.paused) return 'Spillet er satt på pause av verten.';
    if (st.phase !== 'lobby' && st.phase !== 'finished') {
      var gone = st.players.filter(function (p) { return !p.online && p.id !== st.me; }).map(function (p) { return p.name; });
      if (gone.length) return gone.join(' og ') + ' har mistet forbindelsen.';
    }
    return '';
  }

  function render(force) {
    var html;
    if (!session || !st) {
      if (session && !st && !force) { html = '<p class="center muted">Kobler til …</p>'; }
      else if (!session) { html = viewHome(); }
      else { html = '<p class="center muted">Kobler til …</p>'; }
    } else {
      html = ({ lobby: viewLobby, prep: viewPrep, performing: viewPerforming, answering: viewAnswering, reveal: viewReveal, finished: viewFinished })[st.phase]();
    }
    var bar = hostBar();
    var ban = bannerText();
    var sig = html + '\u0000' + bar + '\u0000' + ban;
    if (sig === lastSig) return;
    var screen = st && session ? selKey + ':' + (st.round && st.round.receipt ? 'sent' : '') : 'home';
    var phaseChanged = screen !== lastScreen;
    lastScreen = screen;
    lastSig = sig;
    // Startskjermen har skrivefelt: ikke tegn den på nytt mens noen skriver.
    if (!session && app.querySelector('#name') && !force) { banner.textContent = ban; return; }
    var keepName = app.querySelector('#name') ? app.querySelector('#name').value : '';
    var keepCode = app.querySelector('#code') ? app.querySelector('#code').value : '';
    app.innerHTML = html;
    if (app.querySelector('#name')) {
      if (keepName) app.querySelector('#name').value = keepName;
      if (keepCode) app.querySelector('#code').value = keepCode;
    }
    hostbar.innerHTML = bar;
    banner.textContent = ban;
    banner.className = st && st.paused && !offline && !notice ? 'info' : '';
    if (phaseChanged) window.scrollTo(0, 0);
    tick();
  }

  function tick() {
    var el = document.getElementById('timer');
    if (!el || !st || st.phase !== 'answering') return;
    var ms = st.paused ? st.round.remainingMs : Math.max(0, deadlineAt - Date.now());
    var s = Math.ceil(ms / 1000);
    el.textContent = st.paused ? 'Pause – ' + s + ' sekunder igjen' : (s > 0 ? s + ' sekunder igjen' : 'Tiden er ute …');
    el.className = 'timer' + (s <= 10 ? ' low' : '');
  }

  // ----------------------------------------------------------------- actions

  function val(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }

  var actions = {
    create: function () { notice = ''; send('create', { name: val('name') }); },
    join: function () { notice = ''; send('join', { name: val('name'), code: val('code').toUpperCase() }); },
    start: function () { send('start'); },
    kick: function (el) { send('kick', { pid: el.getAttribute('data-pid') }); },
    ready: function () { send('ready'); },
    swap: function () { if (confirm('Bytte til en annen scene? Denne scenen tas ut av økten.')) send('swap'); },
    next: function () { send('next', { line: st.round.lineIdx }); },
    submit: function () {
      var guesses = {};
      st.round.questions.forEach(function (q) { guesses[q.target] = sel['g' + q.target] || null; });
      send('submit', { guesses: guesses, vote: sel.vote === 'none' ? null : (sel.vote || null), actionId: selKey + ':' + st.me });
    },
    extend: function () { send('extend'); },
    pause: function () { send('pause'); },
    resume: function () { send('resume'); },
    cancel: function () { if (confirm('Annullere runden? Ingen får poeng, og de samme skuespillerne får en ny scene.')) send('cancel'); },
    nextRound: function () { send('nextRound', { v: st.v }); },
    end: function () { if (confirm('Avslutte spillet nå og vise stillingen?')) send('end'); },
    claimHost: function () { send('claimHost'); },
    again: function () { leave(); notice = ''; history.replaceState(null, '', location.pathname); render(true); },
    textUp: function () { prefs.scale = Math.min(1.45, +(prefs.scale + 0.15).toFixed(2)); store('crossedScripts.prefs', prefs); applyPrefs(); },
    textDown: function () { prefs.scale = Math.max(0.85, +(prefs.scale - 0.15).toFixed(2)); store('crossedScripts.prefs', prefs); applyPrefs(); },
    vibe: function () { prefs.vibe = !prefs.vibe; store('crossedScripts.prefs', prefs); applyPrefs(); }
  };

  document.addEventListener('click', function (e) {
    var pick = e.target.closest('[data-pick]');
    if (pick) {
      sel[pick.getAttribute('data-pick')] = pick.getAttribute('data-value');
      render(false);
      return;
    }
    var el = e.target.closest('[data-act]');
    if (!el || el.disabled) return;
    if (notice && !offline) notice = '';
    var fn = actions[el.getAttribute('data-act')];
    if (fn) fn(el);
  });

  document.addEventListener('change', function (e) {
    var key = e.target.getAttribute && e.target.getAttribute('data-set');
    if (!key) return;
    var data = key === 'short' ? { length: e.target.checked ? 'short' : 'standard' } : {};
    if (key !== 'short') data[key] = e.target.checked;
    send('settings', data);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.id) return;
    if (e.target.id === 'code') actions.join();
    if (e.target.id === 'name' && !val('code')) actions.create();
  });

  // Hold skjermen våken under spill der nettleseren støtter det.
  var wake = null;
  function keepAwake() {
    if (!navigator.wakeLock || wake || !session) return;
    navigator.wakeLock.request('screen').then(function (w) {
      wake = w;
      w.addEventListener('release', function () { wake = null; });
    }).catch(function () { /* ikke kritisk */ });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { keepAwake(); poll(); }
  });

  applyPrefs();
  render(true);
  poll();
  setInterval(function () { poll(); keepAwake(); }, 1000);
  setInterval(tick, 250);
})();
