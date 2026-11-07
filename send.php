<?php
/**
 * Серверный обработчик заявок «Я участник» / «Я работодатель».
 * GET  send.php?csrf=1  → {"token": "..."}
 * POST send.php         → {"ok": true, "id": "MK-2026-000001"} | {"ok": false, "error": "...", "fields": {...}}
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

require __DIR__ . '/lib.php';

function out(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['csrf'])) {
    out(200, ['token' => csrf_token()]);
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    out(405, ['ok' => false, 'error' => 'Метод не поддерживается.']);
}

/* ---------- Origin ---------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !empty($CFG['allowed_origins']) && !in_array(rtrim($origin, '/'), $CFG['allowed_origins'], true)) {
    out(403, ['ok' => false, 'error' => 'Запрос отклонён. Обновите страницу и попробуйте ещё раз.']);
}

/* ---------- CSRF ---------- */
if (!csrf_check($_POST['csrf'] ?? null)) {
    out(403, ['ok' => false, 'error' => 'Сессия устарела. Обновите страницу и отправьте форму ещё раз.']);
}

/* ---------- Антиспам ---------- */
if (trim((string)($_POST['website'] ?? '')) !== '') {
    // Honeypot заполнен — бот. Отвечаем нейтрально, ничего не сохраняем.
    out(200, ['ok' => true, 'id' => $CFG['id_prefix'] . '-' . date('Y') . '-000000']);
}
$fillMs = (int)($_POST['fill_ms'] ?? 0);
if ($fillMs > 0 && $fillMs < (int)$CFG['min_fill_ms']) {
    out(400, ['ok' => false, 'error' => 'Форма заполнена слишком быстро. Проверьте данные и отправьте ещё раз.']);
}

try {
    $pdo = db();
    $ipHash = hash('sha256', ($CFG['ip_salt'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $since = time() - (int)$CFG['rate_limit_window'];
    $pdo->prepare('DELETE FROM rate_limit WHERE at < ?')->execute([time() - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate_limit WHERE ip_hash = ? AND at >= ?');
    $st->execute([$ipHash, $since]);
    if ((int)$st->fetchColumn() >= (int)$CFG['rate_limit_count']) {
        out(429, ['ok' => false, 'error' => 'Слишком много заявок подряд. Попробуйте через несколько минут или позвоните нам.']);
    }
} catch (Throwable $ex) {
    error_log('[mk] DB error: ' . $ex->getMessage());
    out(500, ['ok' => false, 'error' => 'Сервис временно недоступен. Попробуйте позже или позвоните нам.']);
}

/* ---------- Валидация ---------- */
$type = (string)($_POST['form_type'] ?? '');
if (!isset(LABELS['type'][$type])) {
    out(400, ['ok' => false, 'error' => 'Неизвестный тип заявки. Обновите страницу.']);
}

$errors = [];
$phoneDigits = preg_replace('/\D/', '', (string)($_POST['phone'] ?? '')) ?? '';
if (strlen($phoneDigits) === 11 && $phoneDigits[0] === '8') $phoneDigits = '7' . substr($phoneDigits, 1);
if (!preg_match('/^7\d{10}$/', $phoneDigits)) $errors['phone'] = 'Введите номер в формате +7 (999) 999-99-99';
$phone = '+' . $phoneDigits;

$email = clean((string)($_POST['email'] ?? ''), 120);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Проверьте адрес электронной почты';

$comment = clean((string)($_POST['comment'] ?? ''), 1000);
$interestDict = $type === 'participant' ? LABELS['interest_participant'] : LABELS['interest_employer'];
$interest = array_values(array_unique(array_filter(
    (array)($_POST['interest'] ?? []),
    fn($v) => is_string($v) && isset($interestDict[$v])
)));

$name = $org = $contact = $category = '';
$adult = 0;
if ($type === 'participant') {
    $name = clean((string)($_POST['name'] ?? ''), 150);
    if (mb_strlen($name) < 2) $errors['name'] = 'Укажите ФИО';
    $category = (string)($_POST['category'] ?? '');
    if ($category !== '' && !isset(LABELS['category'][$category])) $category = '';
    $adult = ($_POST['adult'] ?? '') === '1' ? 1 : 0;
    if (!$adult) $errors['adult'] = 'Самостоятельная заявка доступна с 18 лет';
} else {
    $org = clean((string)($_POST['org'] ?? ''), 200);
    $contact = clean((string)($_POST['contact'] ?? ''), 150);
    if (mb_strlen($org) < 2) $errors['org'] = 'Укажите наименование организации или ИП';
    if (mb_strlen($contact) < 2) $errors['contact'] = 'Укажите имя контактного лица';
}

if (($_POST['consent'] ?? '') !== '1') $errors['consent'] = 'Необходимо согласие на обработку персональных данных';

if ($errors) {
    out(422, ['ok' => false, 'error' => 'Проверьте поля формы: ' . implode('; ', array_values($errors)) . '.', 'fields' => $errors]);
}

$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
    $utm[$k] = clean((string)($_POST[$k] ?? ''), 200);
}
$pageUrl = clean((string)($_POST['page_url'] ?? ''), 500);
if ($pageUrl !== '' && !preg_match('#^https?://#i', $pageUrl)) $pageUrl = '';

/* ---------- Сохранение ---------- */
try {
    $ts = now();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO leads (type, name, org, contact, phone, email, category, interest, comment, adult_confirmed,
            consent_given, consent_at, consent_version, consent_version_client,
            utm_source, utm_medium, utm_campaign, utm_content, utm_term, page_url, status, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?, 1,?,?,?, ?,?,?,?,?,?, \'Новая\',?,?)')
        ->execute([
            $type, $name, $org, $contact, $phone, $email, $category, implode(',', $interest), $comment, $adult,
            $ts, (string)$CFG['consent_version'], clean((string)($_POST['consent_version'] ?? ''), 50),
            $utm['utm_source'], $utm['utm_medium'], $utm['utm_campaign'], $utm['utm_content'], $utm['utm_term'], $pageUrl,
            $ts, $ts,
        ]);
    $id = (int)$pdo->lastInsertId();
    $publicId = sprintf('%s-%s-%06d', $CFG['id_prefix'], date('Y'), $id);
    $pdo->prepare('UPDATE leads SET public_id = ? WHERE id = ?')->execute([$publicId, $id]);
    log_lead($id, 'site', 'Создана', 'Заявка с сайта, согласие v' . $CFG['consent_version']);
    $pdo->prepare('INSERT INTO rate_limit (ip_hash, at) VALUES (?, ?)')->execute([$ipHash, time()]);
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[mk] Save error: ' . $ex->getMessage());
    out(500, ['ok' => false, 'error' => 'Не удалось сохранить заявку. Попробуйте ещё раз или позвоните нам.']);
}

/* ---------- Уведомление ---------- */
if (!empty($CFG['notify_emails'])) {
    $typeLabel = LABELS['type'][$type];
    $subject = '=?UTF-8?B?' . base64_encode("Новая заявка {$publicId} ({$typeLabel})") . '?=';
    $body = "Поступила новая заявка {$publicId}\nТип: {$typeLabel}\nДата: {$ts}\n";
    if (!empty($CFG['notify_include_pd'])) {
        $who = $type === 'participant' ? $name : "{$org}, {$contact}";
        $body .= "Заявитель: {$who}\nТелефон: {$phone}\n";
    }
    $body .= "\nПодробности — в реестре заявок.";
    $headers = "From: {$CFG['notify_from']}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit";
    foreach ($CFG['notify_emails'] as $to) {
        if (!@mail($to, $subject, $body, $headers)) error_log('[mk] mail() failed for ' . $to);
    }
}

/* ---------- Битрикс24 (выключено по умолчанию) ---------- */
if (!empty($CFG['bitrix24']['enabled']) && !empty($CFG['bitrix24']['webhook']) && function_exists('curl_init')) {
    $interestLabels = array_map(fn($k) => $interestDict[$k], $interest);
    $fields = [
        'TITLE' => "{$publicId}: " . LABELS['type'][$type],
        'NAME' => $type === 'participant' ? $name : $contact,
        'COMPANY_TITLE' => $org,
        'PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
        'EMAIL' => $email !== '' ? [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']] : [],
        'COMMENTS' => trim(implode("\n", array_filter([
            $category ? 'Категория: ' . LABELS['category'][$category] : '',
            $interestLabels ? 'Интерес: ' . implode(', ', $interestLabels) : '',
            $comment ? 'Комментарий: ' . $comment : '',
            'Согласие: v' . $CFG['consent_version'] . ' от ' . $ts,
        ]))),
        'SOURCE_ID' => 'WEB',
        'UTM_SOURCE' => $utm['utm_source'], 'UTM_MEDIUM' => $utm['utm_medium'], 'UTM_CAMPAIGN' => $utm['utm_campaign'],
        'UTM_CONTENT' => $utm['utm_content'], 'UTM_TERM' => $utm['utm_term'],
    ];
    $ch = curl_init(rtrim($CFG['bitrix24']['webhook'], '/') . '/crm.lead.add.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['fields' => $fields]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        error_log('[mk] Bitrix24 error: ' . curl_error($ch));
        log_lead($id, 'system', 'Ошибка передачи в Битрикс24');
    } else {
        $j = json_decode((string)$resp, true);
        log_lead($id, 'system', 'Передана в Битрикс24', isset($j['result']) ? 'ID лида: ' . $j['result'] : 'Ответ: ' . mb_substr((string)$resp, 0, 200));
    }
    curl_close($ch);
}

out(200, ['ok' => true, 'id' => $publicId]);
