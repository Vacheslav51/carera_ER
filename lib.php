<?php
/**
 * Общие функции обработчика заявок и реестра.
 */
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Сервис временно недоступен. Позвоните нам или попробуйте позже.'], JSON_UNESCAPED_UNICODE);
    error_log('[mk] config.php не найден — скопируйте config.sample.php');
    exit;
}
$CFG = require __DIR__ . '/config.php';

const LEAD_STATUSES = ['Новая', 'В работе', 'Связались', 'Подтверждено участие/сотрудничество', 'Закрыта', 'Отказ'];

const LABELS = [
    'type' => ['participant' => 'Участник', 'employer' => 'Работодатель'],
    'category' => ['student' => 'Студент', 'jobseeker' => 'Ищу работу', 'career_change' => 'Меняю профессию', 'other' => 'Другое'],
    'interest_participant' => ['meeting' => 'Встреча', 'internship' => 'Стажировка', 'training' => 'Обучение', 'excursion' => 'Экскурсия', 'other' => 'Другое'],
    'interest_employer' => ['vacancies' => 'Вакансии', 'internships' => 'Стажировки', 'excursions' => 'Экскурсии', 'mentoring' => 'Наставничество', 'partnership' => 'Партнёрство'],
];

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('mk_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(?string $t): bool
{
    start_session();
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function db(): PDO
{
    global $CFG;
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname($CFG['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . $CFG['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT UNIQUE,
        type TEXT NOT NULL,
        name TEXT,               -- ФИО участника
        org TEXT,                -- организация / ИП
        contact TEXT,            -- контактное лицо работодателя
        phone TEXT NOT NULL,
        email TEXT,
        category TEXT,
        interest TEXT,
        comment TEXT,
        adult_confirmed INTEGER DEFAULT 0,
        consent_given INTEGER NOT NULL,
        consent_at TEXT NOT NULL,
        consent_version TEXT NOT NULL,
        consent_version_client TEXT,
        utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
        page_url TEXT,
        status TEXT NOT NULL DEFAULT \'Новая\',
        responsible TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS lead_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
        at TEXT NOT NULL,
        actor TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limit (ip_hash TEXT NOT NULL, at INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate ON rate_limit(ip_hash, at)');
    return $pdo;
}

function now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Novosibirsk')))->format('Y-m-d H:i:s');
}

function log_lead(int $leadId, string $actor, string $action, string $details = ''): void
{
    db()->prepare('INSERT INTO lead_log (lead_id, at, actor, action, details) VALUES (?,?,?,?,?)')
        ->execute([$leadId, now(), $actor, $action, $details]);
}

function clean(string $v, int $max): string
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim(preg_replace('/[ \t]+/u', ' ', $v) ?? '');
    return mb_substr($v, 0, $max);
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
