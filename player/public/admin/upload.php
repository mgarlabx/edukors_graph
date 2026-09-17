<?php
/** Importing a course JSON without a shell. */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
require_once __DIR__ . '/../../src/import.php';
admin_require();

$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    $json = '';
    if (isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $json = (string) file_get_contents($_FILES['file']['tmp_name']);
    } elseif (trim((string) ($_POST['json'] ?? '')) !== '') {
        $json = (string) $_POST['json'];
    }

    if ($json === '') {
        $result = ['ok' => false, 'errors' => ['no file and no text were sent'], 'warnings' => []];
    } else {
        $result = edukors_import($json, isset($_POST['publish']));
        if ($result['ok']) {
            admin_flash(
                ($result['replaced'] ? 'Replaced ' : 'Imported ') . $result['title']
                . ' v' . $result['version'],
                'ok'
            );
            header('Location: index.php');
            exit;
        }
    }
}

admin_head('Import a course');
?>
<h2>Import a course</h2>
<p class="lead">
  The JSON the builder produced. It is checked before anything is stored: an error stops the
  import, a warning is kept with the course and shown on its page.
</p>

<?php if ($result !== null && !$result['ok']): ?>
  <div class="notice bad">
    <strong>Not imported — <?= count($result['errors']) ?> error<?= count($result['errors']) === 1 ? '' : 's' ?>.</strong>
    <ul><?php foreach ($result['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php if ($result['warnings'] !== []): ?>
  <div class="notice">
    <strong>Warnings.</strong>
    <ul><?php foreach ($result['warnings'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card">
  <?= admin_csrf_field() ?>
  <label for="file">Course file</label>
  <input id="file" name="file" type="file" accept=".json,application/json">

  <label for="json">…or paste the JSON</label>
  <textarea id="json" name="json" spellcheck="false" placeholder='{"info": {...}, "nodes": [...], "edges": [...]}'></textarea>

  <p style="margin-top:14px">
    <label style="display:inline;font-weight:400">
      <input type="checkbox" name="publish" style="width:auto" checked> publish it straight away
    </label>
  </p>
  <p><button class="btn primary" type="submit">Import</button>
     <a class="btn" href="index.php">Cancel</a></p>
</form>
<?php admin_foot();
