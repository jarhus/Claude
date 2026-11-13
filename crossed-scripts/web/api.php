<?php
declare(strict_types=1);

require __DIR__ . '/private/game.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Bruk POST.']);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = [];
}

try {
    $out = handle((string) ($in['action'] ?? ''), $in);
    echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE);
} catch (ApiError $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'kind' => $e->kind], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Ingen premisser eller fasit i loggen: bare feiltype og sted.
    error_log('crossed-scripts: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $missing = $e instanceof PDOException && preg_match('/no such table|doesn\'t exist/i', $e->getMessage());
    if ($missing) {
        echo json_encode(['ok' => false, 'error' => 'Fant ikke noe spill med den koden.', 'kind' => 'gone'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Teknisk feil på serveren. Prøv igjen.'], JSON_UNESCAPED_UNICODE);
    }
}
