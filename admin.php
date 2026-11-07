<?php
/**
 * Защищённый реестр заявок: список, статусы, ответственные, журнал изменений, выгрузка CSV.
 * Доступ — по логину/паролю из config.php. Рекомендуется дополнительно ограничить по IP
 * или вынести на отдельный поддомен с HTTP-авторизацией.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:");
header('X-Robots-Tag: noindex, nofollow');
start_session();

$users = $CFG['admin']['users'] ?? [];
$me = $_SESSION['admin_user'] ?? null;
$msg = '';

/* ---------- Вход / выход ---------- */
if (($_POST['action'] ?? '') === 'login') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        $u = (string)($_POST['user'] ?? '');
        $p = (string)($_POST['pass'] ?? '');
        usleep(400000);
        if (isset($users[$u]) && password_verify($p, $users[$u])) {
            session_regenerate_id(true);
            $_SESSION['admin_user'] = $u;
            header('Location: admin.php'); exit;
        }
    }
    $msg = 'Неверный логин или пароль.';
}
if (($_GET['logout'] ?? '') === '1') { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }

function page_head(string $title): void { ?>
<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= e($title) ?></title>
<style>
body{font:15px/1.5 Inter,Arial,sans-serif;color:#192942;margin:0;background:#F5F8FC}
header{background:#0A397D;color:#fff;padding:14px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
header a{color:#fff}main{padding:20px;max-width:1400px;margin:0 auto}
table{width:100%;border-collapse:collapse;background:#fff;font-size:14px}th,td{padding:8px 10px;border-bottom:1px solid #D3DEEC;text-align:left;vertical-align:top}
th{background:#E9F2FB;position:sticky;top:0}tr:hover td{background:#FAFCFF}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:#E9F2FB;color:#0A397D}
.tag.emp{background:#FCEEF0;color:#CB2935}
form.inline{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0 0 16px}
input,select,button,textarea{font:inherit;padding:8px 10px;border:1px solid #C5D3E5;border-radius:8px}
button{background:#0A397D;color:#fff;border:0;cursor:pointer}a.btn{display:inline-block;padding:8px 12px;background:#fff;border:1px solid #0A397D;border-radius:8px;text-decoration:none;color:#0A397D}
.card{background:#fff;border:1px solid #D3DEEC;border-radius:12px;padding:20px;margin-bottom:16px}
dl{display:grid;grid-template-columns:220px 1fr;gap:6px 14px;margin:0}dt{color:#4F5F78}dd{margin:0;word-break:break-word}
.msg{background:#FFF1F2;color:#A91F2A;padding:10px 14px;border-radius:8px;margin-bottom:12px}
.login{max-width:360px;margin:12vh auto}.login input{width:100%;margin-bottom:10px;box-sizing:border-box}.login button{width:100%;padding:10px}
</style></head><body>
<?php }

if (!$me) {
    page_head('Вход в реестр заявок'); ?>
    <div class="login card">
      <h1 style="color:#0A397D;margin-top:0">Реестр заявок</h1>
      <?php if (!$users): ?><p class="msg">Пользователи не настроены: заполните admin.users в config.php.</p><?php endif; ?>
      <?php if ($msg): ?><p class="msg"><?= e($msg) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input name="user" placeholder="Логин" autocomplete="username" required>
        <input name="pass" type="password" placeholder="Пароль" autocomplete="current-password" required>
        <button>Войти</button>
      </form>
    </div></body></html>
<?php exit; }

$pdo = db();
$resp = $CFG['admin']['responsibles'] ?? ['Не назначен'];

/* ---------- Изменение статуса / ответственного ---------- */
if (($_POST['action'] ?? '') === 'update' && csrf_check($_POST['csrf'] ?? null)) {
    $id = (int)($_POST['id'] ?? 0);
    $lead = $pdo->prepare('SELECT * FROM leads WHERE id = ?'); $lead->execute([$id]); $lead = $lead->fetch();
    if ($lead) {
        $status = (string)($_POST['status'] ?? '');
        $who = (string)($_POST['responsible'] ?? '');
        $note = clean((string)($_POST['note'] ?? ''), 1000);
        if (in_array($status, LEAD_STATUSES, true) && $status !== $lead['status']) {
            $pdo->prepare('UPDATE leads SET status=?, updated_at=? WHERE id=?')->execute([$status, now(), $id]);
            log_lead($id, $me, 'Статус', $lead['status'] . ' → ' . $status);
        }
        if (in_array($who, $resp, true) && $who !== (string)$lead['responsible']) {
            $pdo->prepare('UPDATE leads SET responsible=?, updated_at=? WHERE id=?')->execute([$who, now(), $id]);
            log_lead($id, $me, 'Ответственный', ($lead['responsible'] ?: '—') . ' → ' . $who);
        }
        if ($note !== '') log_lead($id, $me, 'Комментарий', $note);
    }
    header('Location: admin.php?id=' . $id); exit;
}

function interest_text(array $l): string {
    $dict = $l['type'] === 'participant' ? LABELS['interest_participant'] : LABELS['interest_employer'];
    return implode(', ', array_map(fn($k) => $dict[$k] ?? $k, array_filter(explode(',', (string)$l['interest']))));
}

/* ---------- Фильтры ---------- */
$where = []; $args = [];
$fType = (string)($_GET['type'] ?? ''); $fStatus = (string)($_GET['status'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
if (isset(LABELS['type'][$fType])) { $where[] = 'type = ?'; $args[] = $fType; }
if (in_array($fStatus, LEAD_STATUSES, true)) { $where[] = 'status = ?'; $args[] = $fStatus; }
if ($q !== '') { $where[] = '(public_id LIKE ? OR name LIKE ? OR org LIKE ? OR contact LIKE ? OR phone LIKE ?)'; array_push($args, ...array_fill(0, 5, "%$q%")); }
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* ---------- Выгрузка CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare('SELECT * FROM leads' . $sqlWhere . ' ORDER BY id DESC'); $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    $cols = ['public_id','type','name','org','contact','phone','email','category','interest','comment','adult_confirmed','consent_at','consent_version','utm_source','utm_medium','utm_campaign','utm_content','utm_term','page_url','status','responsible','created_at','updated_at'];
    fputcsv($f, $cols, ';');
    while ($r = $st->fetch()) {
        // Защита от CSV-инъекций в Excel
        fputcsv($f, array_map(fn($c) => preg_match('/^[=+\-@]/', (string)$r[$c]) ? "'" . $r[$c] : $r[$c], $cols), ';');
    }
    error_log('[mk] CSV export by ' . $me . ' at ' . now());
    exit;
}

page_head('Реестр заявок'); ?>
<header><strong>Реестр заявок</strong><span style="opacity:.8">«Моя карьера» · Новосибирская область</span><span style="margin-left:auto"><?= e($me) ?> · <a href="?logout=1">Выйти</a></span></header>
<main>
<?php
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $st = $pdo->prepare('SELECT * FROM leads WHERE id = ?'); $st->execute([$id]); $l = $st->fetch();
    if (!$l) { echo '<p class="msg">Заявка не найдена.</p>'; }
    else {
        $log = $pdo->prepare('SELECT * FROM lead_log WHERE lead_id = ? ORDER BY id DESC'); $log->execute([$id]); ?>
        <p><a class="btn" href="admin.php">← К списку</a></p>
        <div class="card">
          <h2 style="margin-top:0"><?= e($l['public_id']) ?> <span class="tag <?= $l['type']==='employer'?'emp':'' ?>"><?= e(LABELS['type'][$l['type']]) ?></span></h2>
          <dl>
            <?php if ($l['type']==='participant'): ?><dt>ФИО</dt><dd><?= e($l['name']) ?></dd><dt>Категория</dt><dd><?= e(LABELS['category'][$l['category']] ?? '—') ?></dd><dt>Подтвердил(а) 18+</dt><dd><?= $l['adult_confirmed'] ? 'да' : 'нет' ?></dd>
            <?php else: ?><dt>Организация / ИП</dt><dd><?= e($l['org']) ?></dd><dt>Контактное лицо</dt><dd><?= e($l['contact']) ?></dd><?php endif; ?>
            <dt>Телефон</dt><dd><a href="tel:<?= e($l['phone']) ?>"><?= e($l['phone']) ?></a></dd>
            <dt>E-mail</dt><dd><?= e($l['email'] ?: '—') ?></dd>
            <dt>Интерес</dt><dd><?= e(interest_text($l) ?: '—') ?></dd>
            <dt>Комментарий</dt><dd><?= nl2br(e($l['comment'] ?: '—')) ?></dd>
            <dt>Дата/время</dt><dd><?= e($l['created_at']) ?> (Новосибирск)</dd>
            <dt>Согласие</dt><dd>дано <?= e($l['consent_at']) ?>, версия текста <?= e($l['consent_version']) ?><?= $l['consent_version_client'] && $l['consent_version_client'] !== $l['consent_version'] ? ' (на странице: ' . e($l['consent_version_client']) . ')' : '' ?></dd>
            <dt>UTM</dt><dd><?= e(implode(' / ', array_filter([$l['utm_source'],$l['utm_medium'],$l['utm_campaign'],$l['utm_content'],$l['utm_term']])) ?: '—') ?></dd>
            <dt>URL страницы</dt><dd><?= e($l['page_url'] ?: '—') ?></dd>
          </dl>
        </div>
        <div class="card">
          <form method="post" class="inline">
            <input type="hidden" name="action" value="update"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
            <label>Статус<br><select name="status"><?php foreach (LEAD_STATUSES as $s): ?><option <?= $s===$l['status']?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
            <label>Ответственный<br><select name="responsible"><?php foreach ($resp as $r): ?><option <?= $r===$l['responsible']?'selected':'' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            <label style="flex:1;min-width:220px">Комментарий в журнал<br><input name="note" style="width:100%;box-sizing:border-box"></label>
            <button>Сохранить</button>
          </form>
          <h3>Журнал изменений</h3>
          <table><tr><th>Когда</th><th>Кто</th><th>Действие</th><th>Подробности</th></tr>
          <?php foreach ($log as $g): ?><tr><td><?= e($g['at']) ?></td><td><?= e($g['actor']) ?></td><td><?= e($g['action']) ?></td><td><?= e($g['details']) ?></td></tr><?php endforeach; ?>
          </table>
        </div>
<?php }
} else {
    $st = $pdo->prepare('SELECT * FROM leads' . $sqlWhere . ' ORDER BY id DESC LIMIT 500'); $st->execute($args); ?>
    <form class="inline" method="get">
      <label>Тип<br><select name="type"><option value="">Все</option><?php foreach (LABELS['type'] as $k=>$v): ?><option value="<?= $k ?>" <?= $k===$fType?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label>Статус<br><select name="status"><option value="">Все</option><?php foreach (LEAD_STATUSES as $s): ?><option <?= $s===$fStatus?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
      <label>Поиск<br><input name="q" value="<?= e($q) ?>" placeholder="Номер, имя, телефон"></label>
      <button>Показать</button>
      <a class="btn" href="?<?= e(http_build_query(['type'=>$fType,'status'=>$fStatus,'q'=>$q,'export'=>'csv'])) ?>">Выгрузить CSV</a>
    </form>
    <table>
      <tr><th>Номер</th><th>Тип</th><th>Заявитель</th><th>Телефон</th><th>Интерес</th><th>Статус</th><th>Ответственный</th><th>Создана</th><th>UTM source</th></tr>
      <?php foreach ($st as $l): ?>
      <tr>
        <td><a href="?id=<?= (int)$l['id'] ?>"><?= e($l['public_id']) ?></a></td>
        <td><span class="tag <?= $l['type']==='employer'?'emp':'' ?>"><?= e(LABELS['type'][$l['type']]) ?></span></td>
        <td><?= e($l['type']==='participant' ? $l['name'] : $l['org'] . ' — ' . $l['contact']) ?></td>
        <td><?= e($l['phone']) ?></td>
        <td><?= e(interest_text($l)) ?></td>
        <td><?= e($l['status']) ?></td>
        <td><?= e($l['responsible'] ?: '—') ?></td>
        <td><?= e($l['created_at']) ?></td>
        <td><?= e($l['utm_source']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
<?php } ?>
</main></body></html>
