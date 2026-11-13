<?php
declare(strict_types=1);

/**
 * Akseptansetester for spillmotoren. Kjør: php tests/engine_test.php
 * Bruker en midlertidig SQLite-database, eller CS_TEST_DSN/CS_TEST_USER/CS_TEST_PASS hvis satt.
 */

require __DIR__ . '/../web/private/game.php';

$tmp = sys_get_temp_dir() . '/cs_test_' . bin2hex(random_bytes(4)) . '.sqlite';
$GLOBALS['cs_config_override'] = [
    'db_dsn' => getenv('CS_TEST_DSN') ?: 'sqlite:' . $tmp,
    'db_user' => getenv('CS_TEST_USER') ?: null,
    'db_pass' => getenv('CS_TEST_PASS') ?: null,
    'min_players' => 4,
    'max_players' => 8,
    'answer_seconds' => 30,
    'extend_seconds' => 30,
];

$pass = 0;
$fail = 0;
function check(string $what, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FEIL: $what\n";
    }
}
function call(string $action, array $who, array $data = []): array
{
    return handle($action, $data + $who);
}
function fails(string $action, array $who, array $data = []): bool
{
    try {
        call($action, $who, $data);
        return false;
    } catch (ApiError $e) {
        return true;
    }
}
function raw(string $code): array
{
    return load_game($code, false);
}
function patch(string $code, callable $fn): void
{
    $g = raw($code);
    $fn($g);
    save_game($g);
}

function setup(int $n, array $settings = []): array
{
    $res = handle('create', ['name' => 'P1']);
    $code = $res['code'];
    $who = [['code' => $code, 'token' => $res['token']]];
    for ($i = 2; $i <= $n; $i++) {
        $r = handle('join', ['code' => $code, 'name' => "P$i"]);
        $who[] = ['code' => $code, 'token' => $r['token']];
    }
    call('settings', $who[0], $settings + ['practice' => false, 'actorGuess' => false]);
    return [$code, $who];
}
function by_id(array $who, string $code): array
{
    $g = raw($code);
    $map = [];
    foreach ($g['players'] as $i => $p) {
        $map[$p['id']] = $who[$i];
    }
    return $map;
}
function play_scene(string $code, array $who): void
{
    $ids = by_id($who, $code);
    $g = raw($code);
    if ($g['phase'] === 'prep') {
        call('ready', $ids[$g['round']['actors']['A']]);
        call('ready', $ids[$g['round']['actors']['B']]);
    }
    while (true) {
        $g = raw($code);
        if ($g['phase'] !== 'performing') {
            break;
        }
        $role = $g['round']['scene']['lines'][$g['round']['line']]['role'];
        call('next', $ids[$g['round']['actors'][$role]], ['line' => $g['round']['line']]);
    }
}

// ---- Ringplan: to opptredener hver, én som A og én som B, også med oddetall (F02)
foreach ([4, 5, 6, 7, 8] as $n) {
    $ids = array_map(fn($i) => "p$i", range(1, $n));
    $plan = build_plan($ids, 'standard');
    $a = array_count_values(array_column($plan, 0));
    $b = array_count_values(array_column($plan, 1));
    check("plan $n: N runder", count($plan) === $n);
    check("plan $n: alle er A én gang og B én gang", count($a) === $n && count($b) === $n && max($a) === 1 && max($b) === 1);
    $back = 0;
    for ($i = 1; $i < $n; $i++) {
        $back += count(array_intersect($plan[$i], $plan[$i - 1]));
    }
    check("plan $n: få opptredener rett etter hverandre", $back <= ($n === 4 ? 2 : 0));
}
check('kort økt med partall gir én opptreden hver', count(build_plan(['a', 'b', 'c', 'd', 'e', 'f'], 'short')) === 3);
check('kort økt med oddetall faller tilbake til to', count(build_plan(['a', 'b', 'c', 'd', 'e'], 'short')) === 5);

// ---- Scenevalidering (F12)
$good = load_scenes();
check('minst åtte ordinære scener', count(array_filter($good, fn($s) => empty($s['practice']))) >= 8);
$bad = reset($good);
$bad['guesses']['A']['correct'] = 'finnes-ikke';
check('fasit utenfor alternativene avvises', validate_scene($bad) !== []);
$bad = reset($good);
$bad['lines'] = array_values(array_filter($bad['lines'], fn($l) => $l['role'] === 'A'));
check('rolle uten replikk avvises', validate_scene($bad) !== []);

// ---- Lobby og tilgang (F01)
[$code, $who] = setup(3);
check('for få deltakere kan ikke starte', fails('start', $who[0]));
check('feil romkode avvises', fails('join', [], ['code' => 'ZZZZZ', 'name' => 'X']));
check('duplisert navn avvises', fails('join', [], ['code' => $code, 'name' => 'p2']));
check('gjest kan ikke endre oppsett', fails('settings', $who[1], ['practice' => true]));
check('gjest kan ikke starte', fails('start', $who[1]));

// ---- Dokumentets eksempel: seks deltakere, 3 mot 1 (F05–F08)
[$code, $who] = setup(6);
call('start', $who[0]);
check('ingen nye deltakere midt i økten', fails('join', [], ['code' => $code, 'name' => 'Sen']));
$ids = by_id($who, $code);
$g = raw($code);
$A = $g['round']['actors']['A'];
$B = $g['round']['actors']['B'];
$spect = array_values(array_diff(array_keys($ids), [$A, $B]));
$scene = $g['round']['scene'];
$okA = $scene['guesses']['A']['correct'];
$okB = $scene['guesses']['B']['correct'];
$wrongA = $scene['guesses']['A']['options'][1]['id'];
$wrongB = $scene['guesses']['B']['options'][1]['id'];

// Hemmeligheter (F03): bare riktig skuespiller får eget kort, ingen får fasit før avsløring.
$vA = call('state', $ids[$A])['state'];
$vB = call('state', $ids[$B])['state'];
$vS = call('state', $ids[$spect[0]])['state'];
check('A ser eget kort', ($vA['round']['card']['situation'] ?? '') === $scene['roles']['A']['situation']);
check('B ser eget kort', ($vB['round']['card']['situation'] ?? '') === $scene['roles']['B']['situation']);
check('tilskuer ser ikke kort', !isset($vS['round']['card']));
$leak = fn(array $v, string $role) => str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), $scene['roles'][$role]['situation'])
    || str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), $scene['roles'][$role]['premise']);
check('A ser ikke Bs premiss', !$leak($vA, 'B'));
check('B ser ikke As premiss', !$leak($vB, 'A'));
check('tilskuer ser ingen premisser', !$leak($vS, 'A') && !$leak($vS, 'B'));
check('tilskuer kan ikke melde klar', fails('ready', $ids[$spect[0]]));

call('ready', $ids[$A]);
call('ready', $ids[$B]);
$g = raw($code);
check('scenen starter når begge er klare', $g['phase'] === 'performing');

// Turer (F04)
$first = $g['round']['scene']['lines'][0]['role'];
$active = $g['round']['actors'][$first];
$passive = $first === 'A' ? $B : $A;
check('feil skuespiller kan ikke sende Neste', fails('next', $ids[$passive], ['line' => 0]));
check('tilskuer kan ikke sende Neste', fails('next', $ids[$spect[0]], ['line' => 0]));
$vP = call('state', $ids[$passive])['state'];
check('den som lytter ser ikke replikken', !isset($vP['round']['text']));
$vS = call('state', $ids[$spect[0]])['state'];
check('tilskuer ser ingen replikker', !str_contains(json_encode($vS, JSON_UNESCAPED_UNICODE), $scene['lines'][0]['text']));
call('next', $ids[$active], ['line' => 0]);
call('next', $ids[$active], ['line' => 0]); // dobbelttrykk
check('dobbelttrykk hopper ikke over en replikk', raw($code)['round']['line'] === 1);
check('svar kan ikke sendes før scenen er ferdig', fails('submit', $ids[$spect[0]], ['guesses' => []]));
play_scene($code, $who);
$g = raw($code);
check('svarperioden åpner etter siste replikk', $g['phase'] === 'answering');

$vS = call('state', $ids[$spect[0]])['state'];
check('alternativer sendes uten fasit', !str_contains(json_encode($vS), 'correct'));
check('tilskuer får to spørsmål og kan stemme', count($vS['round']['questions']) === 2 && $vS['round']['canVote'] === true);
check('skuespiller kan ikke stemme eller svare uten skuespillergjetting', fails('submit', $ids[$A], ['vote' => 'A']));

// Celine: begge riktige, stemmer A. David: bare A. Eva: bare B, stemmer B. Filip: ingen, stemmer A.
call('submit', $ids[$spect[0]], ['guesses' => ['A' => $okA, 'B' => $okB], 'vote' => 'A', 'actionId' => 'x1']);
call('submit', $ids[$spect[0]], ['guesses' => ['A' => $wrongA, 'B' => $wrongB], 'vote' => 'B', 'actionId' => 'x1']); // dobbel innsending
check('gjentatt innsending endrer ikke svaret', raw($code)['round']['answers'][$spect[0]]['vote'] === 'A');
check('kvittering vises i stedet for nytt skjema', call('state', $ids[$spect[0]])['state']['round']['receipt']['vote'] === 'A');
call('submit', $ids[$spect[1]], ['guesses' => ['A' => $okA, 'B' => $wrongB], 'vote' => 'A']);
call('submit', $ids[$spect[2]], ['guesses' => ['A' => $wrongA, 'B' => $okB], 'vote' => 'B']);
check('fasit er skjult til alle svar er låst', raw($code)['phase'] === 'answering' && !isset(call('state', $ids[$spect[0]])['state']['round']['reveal']));
call('submit', $ids[$spect[3]], ['guesses' => ['A' => $wrongA, 'B' => $wrongB], 'vote' => 'A']);
$g = raw($code);
check('runden låses når alle har svart', $g['phase'] === 'reveal');
$t = totals($g);
check('3 mot 1: vinneren får 3 og taperen 0', $t[$A]['total'] === 3 && $t[$B]['total'] === 0);
check('tilskuere får 2, 1, 1 og 0', [$t[$spect[0]]['total'], $t[$spect[1]]['total'], $t[$spect[2]]['total'], $t[$spect[3]]['total']] === [2, 1, 1, 0]);
check('gjetting og skuespill føres hver for seg', $t[$A]['acting'] === 3 && $t[$A]['guess'] === 0 && $t[$spect[0]]['guess'] === 2);
check('svar etter låsing avvises', fails('submit', $ids[$spect[3]], ['guesses' => ['A' => $okA, 'B' => $okB], 'vote' => 'B']));
$before = count($g['events']);
patch($code, function (&$g) { $g['phase'] = 'answering'; lock_round($g); });
check('beregning kjørt to ganger gir samme resultat', count(raw($code)['events']) === $before && totals(raw($code))[$A]['total'] === 3);
$vS = call('state', $ids[$spect[3]])['state'];
check('avsløring viser begge premisser', $vS['round']['reveal']['A']['premise'] === $scene['roles']['A']['premise'] && $vS['round']['reveal']['B']['premise'] === $scene['roles']['B']['premise']);
check('resultatet viser ikke hvem som stemte hva', !str_contains(json_encode($vS['round']['votes']), $spect[0]));
check('gjest kan ikke starte neste runde', fails('nextRound', $who[1]));

// ---- 2 mot 2, avstått, null stemmer og frist (F06–F08, B02)
function round_with(int $n, array $settings, callable $answers): array
{
    [$code, $who] = setup($n, $settings);
    call('start', $who[0]);
    play_scene($code, $who);
    $ids = by_id($who, $code);
    $g = raw($code);
    $A = $g['round']['actors']['A'];
    $B = $g['round']['actors']['B'];
    $spect = array_values(array_diff(array_keys($ids), [$A, $B]));
    $answers($ids, $A, $B, $spect, $g['round']['scene'], $code);
    return [raw($code), $A, $B, $spect, $ids, $code, $who];
}

[$g, $A, $B] = round_with(6, [], function ($ids, $A, $B, $s) {
    foreach ([0 => 'A', 1 => 'A', 2 => 'B', 3 => 'B'] as $i => $v) {
        call('submit', $ids[$s[$i]], ['guesses' => [], 'vote' => $v]);
    }
});
$t = totals($g);
check('2 mot 2: begge får 1', $t[$A]['total'] === 1 && $t[$B]['total'] === 1);

[$g, $A, $B] = round_with(5, [], function ($ids, $A, $B, $s) {
    call('submit', $ids[$s[0]], ['guesses' => [], 'vote' => 'A']);
    call('submit', $ids[$s[1]], ['guesses' => [], 'vote' => 'B']);
    call('submit', $ids[$s[2]], ['guesses' => [], 'vote' => null]);
});
$t = totals($g);
check('1 mot 1 med én avstått: begge får 1', $t[$A]['total'] === 1 && $t[$B]['total'] === 1 && $g['round']['result']['votes']['abstain'] === 1);

[$g, $A, $B] = round_with(4, [], function ($ids, $A, $B, $s) {
    call('submit', $ids[$s[0]], ['guesses' => [], 'vote' => null]);
    call('submit', $ids[$s[1]], ['guesses' => [], 'vote' => null]);
});
$t = totals($g);
check('null stemmer: 0 poeng til begge', $t[$A]['total'] === 0 && $t[$B]['total'] === 0);

[$g, $A, $B, $s, $ids, $code] = round_with(4, [], function ($ids, $A, $B, $s, $scene, $code) {
    call('submit', $ids[$s[0]], ['guesses' => ['A' => $scene['guesses']['A']['correct']], 'vote' => 'B']);
    patch($code, function (&$g) { $g['round']['deadline'] = now_ms() - 1; });
    $res = call('submit', $ids[$s[1]], ['guesses' => ['A' => $scene['guesses']['A']['correct']], 'vote' => 'A']);
    check('sen innsending gir beskjed om at svaret ikke ble registrert', isset($res['notice']));
});
$t = totals($g);
check('frist ute: runden låses med mottatte svar', $g['phase'] === 'reveal' && $t[$B]['total'] === 3 && $t[$s[0]]['total'] === 1);
check('sen innsending gir 0 poeng', $t[$s[1]]['total'] === 0 && !isset($g['round']['answers'][$s[1]]));

[$g, , , , , $code] = round_with(4, [], function ($ids, $A, $B, $s, $scene, $code) {
    patch($code, function (&$g) { $g['round']['deadline'] = now_ms() - 1; });
    call('state', $ids[$s[0]]); // vanlig statusspørring oppdager at fristen er ute
});
check('fristen håndheves av serverens klokke', $g['phase'] === 'reveal');

// ---- Skuespillergjetting (B01)
[$g, $A, $B, $s, $ids] = round_with(4, ['actorGuess' => true], function ($ids, $A, $B, $s, $scene) {
    $vA = call('state', $ids[$A])['state'];
    check('skuespiller får bare spørsmål om den andre', count($vA['round']['questions']) === 1 && $vA['round']['questions'][0]['target'] === 'B' && $vA['round']['canVote'] === false);
    call('submit', $ids[$A], ['guesses' => ['A' => $scene['guesses']['A']['correct'], 'B' => $scene['guesses']['B']['correct']], 'vote' => 'A']);
    call('submit', $ids[$B], ['guesses' => ['A' => $scene['guesses']['A']['options'][2]['id']], 'vote' => 'B']);
    call('submit', $ids[$s[0]], ['guesses' => [], 'vote' => 'A']);
    call('submit', $ids[$s[1]], ['guesses' => [], 'vote' => 'A']);
});
$t = totals($g);
check('skuespiller: 3 for seier pluss 1 for riktig gjett', $t[$A]['total'] === 4 && $t[$A]['guess'] === 1);
check('skuespillers stemme på seg selv telles ikke', $g['round']['result']['votes'] === ['A' => 2, 'B' => 0, 'abstain' => 0]);
check('skuespiller som gjetter feil får 0', $t[$B]['total'] === 0);

// ---- Pause, forlengelse og annullering (F11)
[$code, $who] = setup(4);
call('start', $who[0]);
$ids = by_id($who, $code);
$host = $who[0];
$g = raw($code);
$actors = $g['round']['actors'];
$firstScene = $g['round']['scene']['id'];
call('swap', $ids[$actors['A']]);
$g = raw($code);
check('skuespiller kan bytte scene før start', $g['round']['scene']['id'] !== $firstScene && $g['round']['actors'] === $actors);
play_scene($code, $who);
$spect = array_values(array_diff(array_keys($ids), array_values($actors)));
call('pause', $host);
check('ingen innsending under pause', fails('submit', $ids[$spect[0]], ['guesses' => [], 'vote' => 'A']));
$left = raw($code)['paused']['remainingMs'];
usleep(150000);
check('fristen står stille under pause', call('state', $ids[$spect[0]])['state']['round']['remainingMs'] === $left);
call('resume', $host);
$d = raw($code)['round']['deadline'] - now_ms();
check('fristen fortsetter med gjenværende tid', abs($d - $left) < 500);
call('extend', $host);
check('verten kan forlenge fristen', raw($code)['round']['deadline'] - now_ms() > $left + 25000);
call('submit', $ids[$spect[0]], ['guesses' => [], 'vote' => 'A']);
$sceneBefore = raw($code)['round']['scene']['id'];
call('cancel', $host);
$g = raw($code);
check('annullert runde gir ingen poeng', $g['events'] === [] && $g['appearances'] === []);
check('annullert runde: samme skuespillere, ny scene', $g['phase'] === 'prep' && $g['round']['actors'] === $actors && $g['round']['scene']['id'] !== $sceneBefore && $g['round']['answers'] === []);
check('gjest kan ikke annullere', fails('cancel', $who[1]));

// ---- Hel økt med øvingsrunde: sluttstilling, delt seier, ingen gjentatte scener (F02, F09)
[$code, $who] = setup(5, ['practice' => true]);
call('start', $who[0]);
$ids = by_id($who, $code);
$g = raw($code);
check('økten starter med øvingsrunden', $g['round']['practice'] === true && !empty($g['round']['scene']['practice']));
play_scene($code, $who);
foreach (array_diff(array_keys($ids), array_values($g['round']['actors'])) as $pid) {
    call('submit', $ids[$pid], ['guesses' => ['A' => $g['round']['scene']['guesses']['A']['correct']], 'vote' => 'A']);
}
$g = raw($code);
check('øvingsrunden gir ingen poeng', $g['phase'] === 'reveal' && $g['events'] === []);
call('nextRound', $who[0]);
$scenes = [];
for ($i = 0; $i < 5; $i++) {
    $g = raw($code);
    check("runde " . ($i + 1) . ' er ordinær', $g['round']['practice'] === false && $g['roundIdx'] === $i);
    $scenes[] = $g['round']['scene']['id'];
    play_scene($code, $who);
    foreach (array_diff(array_keys($ids), array_values($g['round']['actors'])) as $pid) {
        call('submit', $ids[$pid], ['guesses' => [], 'vote' => null]);
    }
    $v = raw($code)['v'];
    call('nextRound', $who[0], ['v' => $v]);
    fails('nextRound', $who[0], ['v' => $v]); // dobbelttrykk skal ikke hoppe over en runde
}
$g = raw($code);
check('økten er ferdig etter N runder', $g['phase'] === 'finished' && $g['endedEarly'] === false);
check('ingen scene gjentas i samme økt', count(array_unique($scenes)) === 5);
check('alle har to opptredener', array_values(array_unique($g['appearances'])) === [2] && count($g['appearances']) === 5);
$v = call('state', $who[0])['state'];
check('lik sum gir delt seier', count($v['final']['winners']) === 5);
check('ferdig runde kan ikke spilles om', fails('nextRound', $who[0]) && fails('cancel', $who[0]));

// ---- Tidlig avslutning og gjenoppkobling (F10)
[$code, $who] = setup(4);
call('start', $who[0]);
play_scene($code, $who);
call('end', $who[0]);
$v = call('state', $who[1])['state'];
check('tidlig avslutning merkes og viser opptredener', $v['phase'] === 'finished' && $v['final']['endedEarly'] === true);

[$code, $who] = setup(4);
call('start', $who[0]);
$ids = by_id($who, $code);
$g = raw($code);
$A = $g['round']['actors']['A'];
$again = call('state', $ids[$A])['state']; // «ny telefon» med samme hemmelige nøkkel
check('gjenoppkobling gir samme identitet, rolle og kort', $again['me'] === $A && $again['round']['myRole'] === 'A' && isset($again['round']['card']));
check('feil nøkkel gir ingen tilgang', fails('state', ['code' => $code, 'token' => str_repeat('0', 32)]));
check('visningsnavn alene gir ingen tilgang', fails('state', ['code' => $code, 'token' => 'P1']));
check('tilkoblet vert kan ikke fratas vertskapet', fails('claimHost', $who[1]));
db()->prepare('UPDATE cs_presence SET ts = ? WHERE code = ? AND pid = ?')->execute([time() - 120, $code, $g['hostId']]);
call('claimHost', $who[1]);
check('vertskap kan overtas når verten har vært borte', raw($code)['hostId'] !== $g['hostId']);

@unlink($tmp);
echo "\n$pass bestått, $fail feilet\n";
exit($fail ? 1 : 0);
