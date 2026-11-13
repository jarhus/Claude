<?php
declare(strict_types=1);

/**
 * Crossed Scripts – spillmotor (POC).
 *
 * Serveren er autoritativ: all rundestatus, alle frister og alle poeng
 * avgjøres her. Klienten får bare det rollen dens har lov til å se.
 */

class ApiError extends Exception
{
    public function __construct(string $message, public string $kind = 'error')
    {
        parent::__construct($message);
    }
}

const CS_ALPHABET = 'ABCDEFGHJKLMNPRSTUVWXYZ';
const CS_ONLINE_SECONDS = 8;
const CS_HOST_AWAY_SECONDS = 60;
const CS_GAME_TTL_SECONDS = 86400;

// ---------------------------------------------------------------- config / db

function cfg(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = [
        'db_dsn' => 'sqlite:' . __DIR__ . '/data/game.sqlite',
        'db_user' => null,
        'db_pass' => null,
        'min_players' => 4,
        'max_players' => 8,
        'answer_seconds' => 30,
        'extend_seconds' => 30,
    ];
    $file = __DIR__ . '/config.php';
    if (is_file($file)) {
        $c = array_merge($c, require $file);
    }
    if (isset($GLOBALS['cs_config_override'])) {
        $c = array_merge($c, $GLOBALS['cs_config_override']); // brukes av testene
    }
    return $c;
}

function is_sqlite(): bool
{
    return str_starts_with(cfg()['db_dsn'], 'sqlite:');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = cfg();
    if (is_sqlite()) {
        $dir = dirname(substr($c['db_dsn'], 7));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (is_sqlite()) {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $pdo->exec('PRAGMA journal_mode=WAL');
    }
    return $pdo;
}

function ensure_schema(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS cs_games (code VARCHAR(8) NOT NULL PRIMARY KEY, state MEDIUMTEXT NOT NULL, updated_at INT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS cs_presence (code VARCHAR(8) NOT NULL, pid VARCHAR(16) NOT NULL, ts INT NOT NULL, PRIMARY KEY (code, pid))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS cs_attempts (ip VARCHAR(64) NOT NULL, ts INT NOT NULL)');
}

function tx_begin(): void
{
    db()->exec(is_sqlite() ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
    $GLOBALS['cs_tx'] = true;
}

function tx_end(bool $commit): void
{
    if (!empty($GLOBALS['cs_tx'])) {
        $GLOBALS['cs_tx'] = false;
        db()->exec($commit ? 'COMMIT' : 'ROLLBACK');
    }
}

function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function load_game(string $code, bool $lock): ?array
{
    $sql = 'SELECT state FROM cs_games WHERE code = ?' . ($lock && !is_sqlite() ? ' FOR UPDATE' : '');
    $st = db()->prepare($sql);
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ? json_decode($row['state'], true) : null;
}

function save_game(array $g, bool $insert = false): void
{
    $json = json_encode($g, JSON_UNESCAPED_UNICODE);
    if ($insert) {
        db()->prepare('INSERT INTO cs_games (code, state, updated_at) VALUES (?, ?, ?)')->execute([$g['code'], $json, time()]);
    } else {
        db()->prepare('UPDATE cs_games SET state = ?, updated_at = ? WHERE code = ?')->execute([$json, time(), $g['code']]);
    }
}

function presence(string $code): array
{
    $st = db()->prepare('SELECT pid, ts FROM cs_presence WHERE code = ?');
    $st->execute([$code]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[$row['pid']] = (int) $row['ts'];
    }
    return $out;
}

function beat(string $code, string $pid): void
{
    db()->prepare('REPLACE INTO cs_presence (code, pid, ts) VALUES (?, ?, ?)')->execute([$code, $pid, time()]);
}

// --------------------------------------------------------------------- scenes

function validate_scene(array $s): array
{
    $err = [];
    foreach (['id', 'version', 'lang', 'pack', 'title'] as $k) {
        if (!isset($s[$k]) || $s[$k] === '') {
            $err[] = "mangler felt: $k";
        }
    }
    $roles = $s['roles'] ?? [];
    if (array_keys($roles) !== ['A', 'B']) {
        $err[] = 'scenen må ha nøyaktig rollene A og B';
    }
    foreach (['A', 'B'] as $r) {
        foreach (['premise', 'situation', 'other', 'goal', 'style'] as $k) {
            if (empty($roles[$r][$k])) {
                $err[] = "rolle $r mangler $k";
            }
        }
    }
    $lines = $s['lines'] ?? [];
    $seen = ['A' => 0, 'B' => 0];
    foreach ($lines as $i => $l) {
        if (!in_array($l['role'] ?? '', ['A', 'B'], true)) {
            $err[] = 'replikk ' . ($i + 1) . ' har ugyldig rolle';
            continue;
        }
        if (trim($l['text'] ?? '') === '') {
            $err[] = 'replikk ' . ($i + 1) . ' mangler tekst';
        }
        $seen[$l['role']]++;
    }
    if (!array_is_list($lines)) {
        $err[] = 'replikkene må være en ordnet liste';
    }
    if ($seen['A'] < 1 || $seen['B'] < 1) {
        $err[] = 'begge roller må ha minst én replikk';
    }
    foreach (['A', 'B'] as $r) {
        $q = $s['guesses'][$r] ?? null;
        $opts = $q['options'] ?? [];
        $ids = array_column($opts, 'id');
        if (count($opts) !== 4 || count(array_unique($ids)) !== 4) {
            $err[] = "gjetteoppgave $r må ha fire alternativer med unike ID-er";
        }
        if (!in_array($q['correct'] ?? null, $ids, true)) {
            $err[] = "gjetteoppgave $r: fasiten finnes ikke blant alternativene";
        }
    }
    return $err;
}

function load_scenes(): array
{
    static $scenes = null;
    if ($scenes !== null) {
        return $scenes;
    }
    $scenes = [];
    foreach (glob(__DIR__ . '/scenes/*.json') as $file) {
        $s = json_decode((string) file_get_contents($file), true);
        if (!is_array($s) || validate_scene($s) || empty($s['published'])) {
            continue; // ugyldige eller upubliserte scener brukes aldri i spill
        }
        $scenes[$s['id']] = $s;
    }
    return $scenes;
}

function pick_scene(array &$g, bool $practice): array
{
    $all = load_scenes();
    $pool = array_filter($all, fn($s) => !empty($s['practice']) === $practice);
    if (!$pool) {
        $pool = array_filter($all, fn($s) => empty($s['practice']));
    }
    if (!$pool) {
        throw new ApiError('Ingen gyldige scener er installert.');
    }
    $fresh = array_filter($pool, fn($s) => !in_array($s['id'], $g['usedScenes'], true));
    $reused = false;
    if (!$fresh) {
        $last = end($g['usedScenes']);
        $fresh = array_filter($pool, fn($s) => $s['id'] !== $last) ?: $pool;
        $reused = true;
    }
    $scene = $fresh[array_rand($fresh)];
    $g['usedScenes'][] = $scene['id'];
    $scene['reused'] = $reused;
    return $scene;
}

// ------------------------------------------------------------------ game core

function new_id(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

function clean_name(mixed $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
    $name = mb_substr($name, 0, 20);
    if ($name === '') {
        throw new ApiError('Skriv inn et navn.');
    }
    return $name;
}

function find_player(array $g, string $token): ?array
{
    foreach ($g['players'] as $p) {
        if ($token !== '' && hash_equals($p['token'], $token)) {
            return $p;
        }
    }
    return null;
}

function player_name(array $g, string $pid): string
{
    foreach ($g['players'] as $p) {
        if ($p['id'] === $pid) {
            return $p['name'];
        }
    }
    return '?';
}

/**
 * Ringplan: stokk deltakerne, la 1 spille med 2, 2 med 3 ... og siste med 1.
 * Alle får to opptredener, én som A og én som B. Annenhvert par tas først
 * for å unngå at noen spiller to runder på rad.
 */
function build_plan(array $ids, string $length): array
{
    shuffle($ids);
    $n = count($ids);
    $even = [];
    $odd = [];
    for ($i = 0; $i < $n; $i++) {
        $pair = [$ids[$i], $ids[($i + 1) % $n]];
        if ($i % 2 === 0) {
            $even[] = $pair;
        } else {
            $odd[] = $pair;
        }
    }
    if ($length === 'short' && $n % 2 === 0) {
        return $even; // én opptreden hver
    }
    return array_merge($even, $odd);
}

function start_round(array &$g, bool $practice): void
{
    $pair = $practice ? $g['plan'][count($g['plan']) - 1] : $g['plan'][$g['roundIdx']];
    $scene = pick_scene($g, $practice);
    $g['round'] = [
        'practice' => $practice,
        'scene' => $scene, // låst kopi: redaksjonelle endringer påvirker ikke en påbegynt runde
        'actors' => ['A' => $pair[0], 'B' => $pair[1]],
        'ready' => ['A' => false, 'B' => false],
        'line' => 0,
        'deadline' => null,
        'answers' => [],
        'result' => null,
    ];
    $g['phase'] = 'prep';
    $g['paused'] = null;
}

function my_role(array $g, string $pid): ?string
{
    $a = $g['round']['actors'] ?? [];
    return match (true) {
        ($a['A'] ?? null) === $pid => 'A',
        ($a['B'] ?? null) === $pid => 'B',
        default => null,
    };
}

/** Hvem som skal sende inn svar i svarperioden. */
function eligible(array $g): array
{
    $out = [];
    foreach ($g['players'] as $p) {
        if (my_role($g, $p['id']) === null || $g['settings']['actorGuess']) {
            $out[] = $p['id'];
        }
    }
    return $out;
}

function lock_round(array &$g): void
{
    if ($g['phase'] !== 'answering') {
        return; // poeng beregnes nøyaktig én gang
    }
    $r = &$g['round'];
    $scene = $r['scene'];
    $awards = [];
    $votes = ['A' => 0, 'B' => 0, 'abstain' => 0];

    foreach ($g['players'] as $p) {
        $pid = $p['id'];
        $role = my_role($g, $pid);
        $ans = $r['answers'][$pid] ?? null;
        if ($role === null) {
            foreach (['A', 'B'] as $t) {
                if ($ans && ($ans['guesses'][$t] ?? null) === $scene['guesses'][$t]['correct']) {
                    $awards[] = ['pid' => $pid, 'type' => 'guess', 'target' => $t, 'points' => 1];
                }
            }
            $vote = $ans['vote'] ?? null;
            $votes[in_array($vote, ['A', 'B'], true) ? $vote : 'abstain']++;
        } elseif ($g['settings']['actorGuess']) {
            $t = $role === 'A' ? 'B' : 'A';
            if ($ans && ($ans['guesses'][$t] ?? null) === $scene['guesses'][$t]['correct']) {
                $awards[] = ['pid' => $pid, 'type' => 'guess', 'target' => $t, 'points' => 1];
            }
        }
    }

    // Flest gyldige stemmer gir 3. Likt gir 1 hver. 0 mot 0 gir 0 (eksplisitt unntak, B02).
    if ($votes['A'] > $votes['B']) {
        $awards[] = ['pid' => $r['actors']['A'], 'type' => 'acting', 'target' => 'win', 'points' => 3];
    } elseif ($votes['B'] > $votes['A']) {
        $awards[] = ['pid' => $r['actors']['B'], 'type' => 'acting', 'target' => 'win', 'points' => 3];
    } elseif ($votes['A'] > 0) {
        $awards[] = ['pid' => $r['actors']['A'], 'type' => 'acting', 'target' => 'tie', 'points' => 1];
        $awards[] = ['pid' => $r['actors']['B'], 'type' => 'acting', 'target' => 'tie', 'points' => 1];
    }

    $r['result'] = ['votes' => $votes, 'awards' => $awards];
    $r['deadline'] = null;
    $g['phase'] = 'reveal';
    $g['paused'] = null;

    if (!$r['practice']) {
        $existing = array_column($g['events'], 'key');
        foreach ($awards as $a) {
            $key = 'r' . $g['roundIdx'] . ':' . $a['pid'] . ':' . $a['type'] . ':' . $a['target'];
            if (!in_array($key, $existing, true)) {
                $g['events'][] = ['key' => $key, 'round' => $g['roundIdx'], 'pid' => $a['pid'], 'type' => $a['type'], 'points' => $a['points']];
            }
        }
        foreach ($r['actors'] as $pid) {
            $g['appearances'][$pid] = ($g['appearances'][$pid] ?? 0) + 1;
        }
    }
}

function totals(array $g): array
{
    $t = [];
    foreach ($g['players'] as $p) {
        $t[$p['id']] = ['guess' => 0, 'acting' => 0, 'total' => 0];
    }
    foreach ($g['events'] as $e) {
        if (isset($t[$e['pid']])) {
            $t[$e['pid']][$e['type']] += $e['points'];
            $t[$e['pid']]['total'] += $e['points'];
        }
    }
    return $t;
}

// ----------------------------------------------------------------------- view

function shuffled_options(array $options, string $seed): array
{
    usort($options, fn($a, $b) => crc32($seed . $a['id']) <=> crc32($seed . $b['id']));
    return array_map(fn($o) => ['id' => $o['id'], 'text' => $o['text']], $options);
}

/** Bygger det denne deltakeren har lov til å se akkurat nå. */
function view(array $g, string $me, array $presence): array
{
    $now = now_ms();
    $tot = totals($g);
    $players = [];
    foreach ($g['players'] as $p) {
        $players[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'online' => ($presence[$p['id']] ?? 0) > time() - CS_ONLINE_SECONDS,
            'score' => $tot[$p['id']]['total'],
            'guess' => $tot[$p['id']]['guess'],
            'acting' => $tot[$p['id']]['acting'],
        ];
    }
    $v = [
        'code' => $g['code'],
        'v' => $g['v'],
        'phase' => $g['phase'],
        'me' => $me,
        'hostId' => $g['hostId'],
        'hostAway' => ($presence[$g['hostId']] ?? 0) < time() - CS_HOST_AWAY_SECONDS,
        'settings' => $g['settings'],
        'players' => $players,
        'paused' => $g['paused'] !== null,
        'minPlayers' => cfg()['min_players'],
        'maxPlayers' => cfg()['max_players'],
    ];
    if ($g['phase'] === 'lobby') {
        return $v;
    }
    $v['roundNo'] = min($g['roundIdx'] + 1, count($g['plan']));
    $v['totalRounds'] = count($g['plan']);
    if ($g['phase'] === 'finished') {
        $max = max(array_column($tot, 'total') ?: [0]);
        $v['final'] = [
            'endedEarly' => $g['endedEarly'],
            'winners' => array_keys(array_filter($tot, fn($t) => $t['total'] === $max)),
            'appearances' => $g['appearances'],
        ];
        return $v;
    }

    $r = $g['round'];
    $scene = $r['scene'];
    $role = my_role($g, $me);
    $v['round'] = [
        'practice' => $r['practice'],
        'reused' => $scene['reused'] ?? false,
        'actors' => $r['actors'],
        'myRole' => $role,
    ];

    if ($g['phase'] === 'prep') {
        $v['round']['ready'] = $r['ready'];
        if ($role !== null) {
            $card = $scene['roles'][$role];
            unset($card['premise']);
            $v['round']['card'] = $card;
        }
    } elseif ($g['phase'] === 'performing') {
        $line = $scene['lines'][$r['line']];
        $v['round']['turn'] = $line['role'];
        if ($role !== null) {
            $mine = 0;
            $done = 0;
            foreach ($scene['lines'] as $i => $l) {
                if ($l['role'] === $role) {
                    $mine++;
                    if ($i < $r['line']) {
                        $done++;
                    }
                }
            }
            $v['round']['lineIdx'] = $r['line'];
            $v['round']['myTotal'] = $mine;
            $v['round']['myDone'] = $done;
            if ($line['role'] === $role) {
                $v['round']['text'] = $line['text'];
            }
        }
    } elseif ($g['phase'] === 'answering') {
        $el = eligible($g);
        $v['round']['eligibleCount'] = count($el);
        $v['round']['submittedCount'] = count(array_intersect($el, array_keys($r['answers'])));
        $v['round']['remainingMs'] = $g['paused'] !== null ? (int) $g['paused']['remainingMs'] : max(0, $r['deadline'] - $now);
        $v['round']['eligible'] = in_array($me, $el, true);
        if ($v['round']['eligible']) {
            $targets = $role === null ? ['A', 'B'] : [$role === 'A' ? 'B' : 'A'];
            $qs = [];
            foreach ($targets as $t) {
                $qs[] = ['target' => $t, 'options' => shuffled_options($scene['guesses'][$t]['options'], $me . $scene['id'] . $t)];
            }
            $v['round']['questions'] = $qs;
            $v['round']['canVote'] = $role === null;
            $mine = $r['answers'][$me] ?? null;
            $v['round']['receipt'] = $mine ? ['guesses' => $mine['guesses'], 'vote' => $mine['vote']] : null;
        }
    } elseif ($g['phase'] === 'reveal') {
        $mine = $r['answers'][$me] ?? null;
        $qs = [];
        foreach (['A', 'B'] as $t) {
            $qs[$t] = [
                'premise' => $scene['roles'][$t]['premise'],
                'situation' => $scene['roles'][$t]['situation'],
                'options' => array_map(fn($o) => ['id' => $o['id'], 'text' => $o['text']], $scene['guesses'][$t]['options']),
                'correct' => $scene['guesses'][$t]['correct'],
                'mine' => $mine['guesses'][$t] ?? null,
                'asked' => $role === null || ($g['settings']['actorGuess'] && $role !== $t),
            ];
        }
        $v['round']['reveal'] = $qs;
        $v['round']['votes'] = $r['result']['votes'];
        $v['round']['awards'] = $r['result']['awards'];
        $v['round']['lastRound'] = !$r['practice'] && $g['roundIdx'] + 1 >= count($g['plan']);
    }
    return $v;
}

// -------------------------------------------------------------------- actions

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
}

function act_create(array $in): array
{
    ensure_schema();
    $name = clean_name($in['name'] ?? '');
    $pdo = db();
    $old = time() - CS_GAME_TTL_SECONDS;
    $pdo->prepare('DELETE FROM cs_games WHERE updated_at < ?')->execute([$old]);
    $pdo->prepare('DELETE FROM cs_presence WHERE ts < ?')->execute([$old]);
    $pdo->prepare('DELETE FROM cs_attempts WHERE ts < ?')->execute([time() - 600]);

    $host = ['id' => new_id(4), 'token' => new_id(16), 'name' => $name];
    for ($try = 0; $try < 20; $try++) {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= CS_ALPHABET[random_int(0, strlen(CS_ALPHABET) - 1)];
        }
        if (load_game($code, false) === null) {
            break;
        }
    }
    $g = [
        'code' => $code,
        'v' => 1,
        'ruleVersion' => 'poc-1',
        'phase' => 'lobby',
        'hostId' => $host['id'],
        'settings' => ['actorGuess' => true, 'length' => 'standard', 'practice' => true],
        'players' => [$host],
        'plan' => [],
        'roundIdx' => 0,
        'usedScenes' => [],
        'round' => null,
        'paused' => null,
        'events' => [],
        'appearances' => [],
        'endedEarly' => false,
    ];
    save_game($g, true);
    beat($code, $host['id']);
    return ['code' => $code, 'token' => $host['token'], 'state' => view($g, $host['id'], presence($code))];
}

function act_join(array $in): array
{
    $code = strtoupper(trim((string) ($in['code'] ?? '')));
    $name = clean_name($in['name'] ?? '');
    $pdo = db();
    $ip = client_ip();
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM cs_attempts WHERE ip = ? AND ts > ?');
    $st->execute([$ip, time() - 600]);
    if ((int) $st->fetch()['n'] >= 20) {
        throw new ApiError('For mange forsøk. Vent noen minutter og prøv igjen.');
    }
    tx_begin();
    try {
        $g = preg_match('/^[A-Z]{5}$/', $code) ? load_game($code, true) : null;
        if ($g === null) {
            tx_end(false);
            $pdo->prepare('INSERT INTO cs_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            throw new ApiError('Fant ikke noe spill med den koden. Sjekk koden og prøv igjen.');
        }
        if ($g['phase'] !== 'lobby') {
            throw new ApiError('Spillet er allerede i gang. Vent til neste økt.');
        }
        if (count($g['players']) >= cfg()['max_players']) {
            throw new ApiError('Rommet er fullt.');
        }
        foreach ($g['players'] as $p) {
            if (mb_strtolower($p['name']) === mb_strtolower($name)) {
                throw new ApiError('Navnet er allerede i bruk i dette rommet. Velg et annet.');
            }
        }
        $me = ['id' => new_id(4), 'token' => new_id(16), 'name' => $name];
        $g['players'][] = $me;
        $g['v']++;
        save_game($g);
        tx_end(true);
    } catch (Throwable $e) {
        tx_end(false);
        throw $e;
    }
    beat($code, $me['id']);
    return ['code' => $code, 'token' => $me['token'], 'state' => view($g, $me['id'], presence($code))];
}

function act_state(array $in): array
{
    $code = (string) ($in['code'] ?? '');
    $g = load_game($code, false);
    $me = $g ? find_player($g, (string) ($in['token'] ?? '')) : null;
    if ($me === null) {
        throw new ApiError('Du er ikke lenger med i dette spillet.', 'gone');
    }
    beat($code, $me['id']);
    if ($g['phase'] === 'answering' && $g['paused'] === null && now_ms() >= $g['round']['deadline']) {
        return mutate($in, function (array &$g) {
            if ($g['phase'] === 'answering' && $g['paused'] === null && now_ms() >= $g['round']['deadline']) {
                lock_round($g);
            }
        });
    }
    return ['state' => view($g, $me['id'], presence($code))];
}

/** Kjører en endring under lås, og returnerer deltakerens nye visning. */
function mutate(array $in, callable $fn): array
{
    $code = (string) ($in['code'] ?? '');
    tx_begin();
    try {
        $g = load_game($code, true);
        $me = $g ? find_player($g, (string) ($in['token'] ?? '')) : null;
        if ($me === null) {
            throw new ApiError('Du er ikke lenger med i dette spillet.', 'gone');
        }
        $before = json_encode($g);
        $notice = $fn($g, $me);
        if (json_encode($g) !== $before) {
            $g['v']++;
            save_game($g);
        }
        tx_end(true);
    } catch (Throwable $e) {
        tx_end(false);
        throw $e;
    }
    $out = ['state' => view($g, $me['id'], presence($code))];
    if (is_string($notice)) {
        $out['notice'] = $notice;
    }
    return $out;
}

function need_host(array $g, array $me): void
{
    if ($g['hostId'] !== $me['id']) {
        throw new ApiError('Bare verten kan gjøre dette.');
    }
}

function need_phase(array $g, string ...$phases): void
{
    if (!in_array($g['phase'], $phases, true)) {
        throw new ApiError('Handlingen passer ikke med hvor spillet er nå.', 'stale');
    }
}

function need_running(array $g): void
{
    if ($g['paused'] !== null) {
        throw new ApiError('Spillet er satt på pause.', 'stale');
    }
}

function handle(string $action, array $in): array
{
    switch ($action) {
        case 'create':
            return act_create($in);
        case 'join':
            return act_join($in);
        case 'state':
            return act_state($in);

        case 'settings':
            return mutate($in, function (array &$g, array $me) use ($in) {
                need_host($g, $me);
                need_phase($g, 'lobby');
                foreach (['actorGuess', 'practice'] as $k) {
                    if (isset($in[$k])) {
                        $g['settings'][$k] = (bool) $in[$k];
                    }
                }
                if (in_array($in['length'] ?? null, ['standard', 'short'], true)) {
                    $g['settings']['length'] = $in['length'];
                }
            });

        case 'kick':
            return mutate($in, function (array &$g, array $me) use ($in) {
                need_host($g, $me);
                need_phase($g, 'lobby');
                $pid = (string) ($in['pid'] ?? '');
                if ($pid === $me['id']) {
                    throw new ApiError('Verten kan ikke fjerne seg selv.');
                }
                $g['players'] = array_values(array_filter($g['players'], fn($p) => $p['id'] !== $pid));
            });

        case 'start':
            return mutate($in, function (array &$g, array $me) {
                need_host($g, $me);
                need_phase($g, 'lobby');
                $n = count($g['players']);
                if ($n < cfg()['min_players']) {
                    throw new ApiError('Dere må være minst ' . cfg()['min_players'] . ' deltakere.');
                }
                $g['plan'] = build_plan(array_column($g['players'], 'id'), $g['settings']['length']);
                $g['roundIdx'] = 0;
                start_round($g, $g['settings']['practice']);
            });

        case 'ready':
            return mutate($in, function (array &$g, array $me) {
                need_phase($g, 'prep');
                need_running($g);
                $role = my_role($g, $me['id']);
                if ($role === null) {
                    throw new ApiError('Bare skuespillerne kan melde seg klare.');
                }
                $g['round']['ready'][$role] = true;
                if ($g['round']['ready']['A'] && $g['round']['ready']['B']) {
                    $g['phase'] = 'performing';
                }
            });

        case 'swap':
            return mutate($in, function (array &$g, array $me) {
                need_phase($g, 'prep');
                need_running($g);
                if (my_role($g, $me['id']) === null) {
                    throw new ApiError('Bare skuespillerne kan bytte scene.');
                }
                start_round($g, $g['round']['practice']);
            });

        case 'next':
            return mutate($in, function (array &$g, array $me) use ($in) {
                need_phase($g, 'performing');
                need_running($g);
                $r = &$g['round'];
                if ((int) ($in['line'] ?? -1) !== $r['line']) {
                    return; // gjentatt eller utdatert trykk: ingen replikk hoppes over
                }
                if (my_role($g, $me['id']) !== $r['scene']['lines'][$r['line']]['role']) {
                    throw new ApiError('Det er ikke din tur.');
                }
                $r['line']++;
                if ($r['line'] >= count($r['scene']['lines'])) {
                    $r['line'] = count($r['scene']['lines']) - 1;
                    $r['deadline'] = now_ms() + cfg()['answer_seconds'] * 1000;
                    $g['phase'] = 'answering';
                }
            });

        case 'submit':
            return mutate($in, function (array &$g, array $me) use ($in) {
                need_phase($g, 'answering');
                need_running($g);
                $r = &$g['round'];
                $pid = $me['id'];
                if (!in_array($pid, eligible($g), true)) {
                    throw new ApiError('Du skal ikke svare i denne runden.');
                }
                if (isset($r['answers'][$pid])) {
                    return; // dobbelttrykk: samme kvittering, ingen ny stemme
                }
                if (now_ms() > $r['deadline']) {
                    lock_round($g);
                    return 'Fristen var ute. Svaret ditt ble ikke registrert.';
                }
                $role = my_role($g, $pid);
                $targets = $role === null ? ['A', 'B'] : [$role === 'A' ? 'B' : 'A'];
                $guesses = [];
                foreach ($targets as $t) {
                    $choice = $in['guesses'][$t] ?? null;
                    $ids = array_column($r['scene']['guesses'][$t]['options'], 'id');
                    $guesses[$t] = in_array($choice, $ids, true) ? $choice : null;
                }
                $vote = $role === null && in_array($in['vote'] ?? null, ['A', 'B'], true) ? $in['vote'] : null;
                $r['answers'][$pid] = ['guesses' => $guesses, 'vote' => $vote, 'actionId' => substr((string) ($in['actionId'] ?? ''), 0, 40), 'at' => now_ms()];
                if (!array_diff(eligible($g), array_keys($r['answers']))) {
                    lock_round($g);
                }
            });

        case 'extend':
            return mutate($in, function (array &$g, array $me) {
                need_host($g, $me);
                need_phase($g, 'answering');
                need_running($g);
                if (now_ms() < $g['round']['deadline']) {
                    $g['round']['deadline'] += cfg()['extend_seconds'] * 1000;
                }
            });

        case 'pause':
            return mutate($in, function (array &$g, array $me) {
                need_host($g, $me);
                need_phase($g, 'prep', 'performing', 'answering');
                if ($g['paused'] === null) {
                    $left = $g['phase'] === 'answering' ? max(0, $g['round']['deadline'] - now_ms()) : 0;
                    $g['paused'] = ['remainingMs' => $left];
                }
            });

        case 'resume':
            return mutate($in, function (array &$g, array $me) {
                need_host($g, $me);
                if ($g['paused'] !== null) {
                    if ($g['phase'] === 'answering') {
                        $g['round']['deadline'] = now_ms() + (int) $g['paused']['remainingMs'];
                    }
                    $g['paused'] = null;
                }
            });

        case 'cancel':
            return mutate($in, function (array &$g, array $me) {
                need_host($g, $me);
                need_phase($g, 'prep', 'performing', 'answering');
                // Annullert runde gir ingen poeng. Samme skuespillere får en ny scene.
                start_round($g, $g['round']['practice']);
            });

        case 'nextRound':
            return mutate($in, function (array &$g, array $me) use ($in) {
                need_host($g, $me);
                need_phase($g, 'reveal');
                if ((int) ($in['v'] ?? $g['v']) !== $g['v']) {
                    return;
                }
                if ($g['round']['practice']) {
                    start_round($g, false);
                    return;
                }
                $g['roundIdx']++;
                if ($g['roundIdx'] >= count($g['plan'])) {
                    $g['phase'] = 'finished';
                    $g['round'] = null;
                } else {
                    start_round($g, false);
                }
            });

        case 'end':
            return mutate($in, function (array &$g, array $me) {
                need_host($g, $me);
                need_phase($g, 'prep', 'performing', 'answering', 'reveal');
                $complete = $g['phase'] === 'reveal' && !$g['round']['practice'] && $g['roundIdx'] + 1 >= count($g['plan']);
                $g['endedEarly'] = !$complete;
                $g['phase'] = 'finished';
                $g['round'] = null;
                $g['paused'] = null;
            });

        case 'claimHost':
            return mutate($in, function (array &$g, array $me) {
                $seen = presence($g['code'])[$g['hostId']] ?? 0;
                if ($seen >= time() - CS_HOST_AWAY_SECONDS) {
                    throw new ApiError('Verten er fortsatt tilkoblet.');
                }
                $g['hostId'] = $me['id'];
            });
    }
    throw new ApiError('Ukjent handling.');
}
