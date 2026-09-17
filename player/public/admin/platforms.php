<?php
/**
 * The LMSs allowed to launch courses here.
 *
 * Each row is one deployment. The four values come from the LMS administrator
 * when they register this tool; everything else in the exchange is derived.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
admin_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    if (($_POST['action'] ?? '') === 'delete') {
        db_run('DELETE FROM lti_platform WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        admin_flash('Platform removed.', 'ok');
    } else {
        $fields = [
            'name'           => trim((string) ($_POST['name'] ?? '')),
            'issuer'         => trim((string) ($_POST['issuer'] ?? '')),
            'client_id'      => trim((string) ($_POST['client_id'] ?? '')),
            'deployment_id'  => trim((string) ($_POST['deployment_id'] ?? '')),
            'auth_login_url' => trim((string) ($_POST['auth_login_url'] ?? '')),
            'jwks_url'       => trim((string) ($_POST['jwks_url'] ?? '')),
        ];
        $missing = array_keys(array_filter($fields, static fn($v) => $v === ''));
        if ($missing !== []) {
            admin_flash('Every field is needed: ' . implode(', ', $missing) . '.', 'bad');
        } else {
            try {
                db_run(
                    'INSERT INTO lti_platform (name, issuer, client_id, deployment_id,
                                               auth_login_url, jwks_url, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    array_merge(array_values($fields), [db_now()])
                );
                admin_flash('Platform registered.', 'ok');
            } catch (PDOException $e) {
                admin_flash('That issuer, client and deployment is already registered.', 'bad');
            }
        }
    }
    header('Location: platforms.php');
    exit;
}

$platforms = db_all('SELECT * FROM lti_platform ORDER BY name');
$base = edukors_config()['base_url'];

admin_head('Platforms');
?>
<h2>Learning platforms</h2>
<p class="lead">
  The Moodle, Canvas or Blackboard installations allowed to launch courses here.
  Courses are sent one way: the platform says who the student is, and this server answers it nothing.
</p>

<div class="card">
  <h3>What the LMS administrator needs from you</h3>
  <p style="margin:0 0 10px">
    Register this tool <strong>by hand</strong>, filling the four fields below into their own boxes.
    It does not support dynamic registration, so there is no single URL to paste into the
    “add a tool” box that platforms offer — in Moodle, use the <em>configure a tool manually</em>
    link underneath that box.
  </p>
  <p style="margin:0 0 6px">Login URL <span class="key"><?= h($base) ?>/lti/login.php</span></p>
  <p style="margin:0 0 6px">Redirect URL <span class="key"><?= h($base) ?>/lti/launch.php</span></p>
  <p style="margin:0 0 6px">Launch URL <span class="key"><?= h($base) ?>/lti/launch.php?course=&lt;course id&gt;</span></p>
  <p style="margin:0 0 6px">Public keyset URL <span class="key"><?= h($base) ?>/lti/jwks.php</span></p>
  <p class="muted" style="font-size:12px;margin:8px 0 0">
    That key set is empty on purpose: this tool signs nothing, because it sends nothing back.
    The field exists because the form asks for it, and a platform only reads it to verify
    something the tool sent.
  </p>
</div>

<?php if ($platforms === []): ?>
  <p class="empty">No platform registered, so no launch can succeed yet.</p>
<?php else: ?>
<table>
  <tr><th>Name</th><th>Issuer</th><th>Client</th><th>Deployment</th><th>Keys read</th><th></th></tr>
  <?php foreach ($platforms as $p): ?>
  <tr>
    <td><?= h($p['name']) ?></td>
    <td class="num"><?= h($p['issuer']) ?></td>
    <td class="num"><?= h($p['client_id']) ?></td>
    <td class="num"><?= h($p['deployment_id']) ?></td>
    <td class="num"><?= $p['jwks_fetched_at'] === null ? 'never' : h(substr((string) $p['jwks_fetched_at'], 0, 16)) ?></td>
    <td>
      <form method="post" onsubmit="return confirm('Remove this platform? Its students keep their progress.')">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <button class="btn danger" type="submit">Remove</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h3 style="margin-top:24px">Register a platform</h3>
<form method="post" class="card">
  <?= admin_csrf_field() ?>
  <div class="row">
    <div><label for="name">Name</label>
         <input id="name" name="name" type="text" placeholder="Moodle of school X"></div>
    <div><label for="issuer">Issuer (iss)</label>
         <input id="issuer" name="issuer" type="text" placeholder="https://moodle.example.org"></div>
  </div>
  <div class="row">
    <div><label for="client_id">Client id</label><input id="client_id" name="client_id" type="text"></div>
    <div><label for="deployment_id">Deployment id</label><input id="deployment_id" name="deployment_id" type="text"></div>
  </div>
  <div class="row">
    <div><label for="auth_login_url">Authentication URL</label>
         <input id="auth_login_url" name="auth_login_url" type="url"
                placeholder="https://moodle.example.org/mod/lti/auth.php"></div>
    <div><label for="jwks_url">Public key set (JWKS) URL</label>
         <input id="jwks_url" name="jwks_url" type="url"
                placeholder="https://moodle.example.org/mod/lti/certs.php"></div>
  </div>
  <p style="margin-top:14px"><button class="btn primary" type="submit">Register</button></p>
</form>
<?php admin_foot();
