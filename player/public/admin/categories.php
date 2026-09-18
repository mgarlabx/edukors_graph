<?php
/**
 * The shelves the course list is arranged on.
 *
 * A category never reaches a student: it exists so that whoever runs this
 * server can put the courses in an order that means something, which the
 * titles alone never do.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
admin_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_check_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $title  = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 160);
    $order  = (int) ($_POST['sort_order'] ?? 0);

    if ($action === 'delete') {
        // The courses on it are left alone: the foreign key empties their
        // category_id, and they are simply on no shelf again.
        db_run('DELETE FROM category WHERE id = ?', [$id]);
        admin_flash('Category removed. The courses on it are on none now.', 'ok');
    } elseif ($title === '') {
        admin_flash('A category needs a name.', 'bad');
    } else {
        try {
            if ($id > 0) {
                db_run('UPDATE category SET title = ?, sort_order = ? WHERE id = ?', [$title, $order, $id]);
                admin_flash('Saved.', 'ok');
            } else {
                db_run('INSERT INTO category (title, sort_order) VALUES (?, ?)', [$title, $order]);
                admin_flash("Category $title added.", 'ok');
            }
        } catch (PDOException $e) {
            admin_flash('There is already a category with that name.', 'bad');
        }
    }
    header('Location: categories.php');
    exit;
}

// Versions are not courses: a course with four versions stored is one course
// on the shelf, which is what `DISTINCT course_uuid` counts.
$categories = db_all(
    'SELECT k.*,
            (SELECT COUNT(DISTINCT c.course_uuid) FROM course c WHERE c.category_id = k.id) AS courses
     FROM category k ORDER BY k.sort_order, k.title'
);

admin_head('Categories');
?>
<h2>Categories</h2>
<p class="lead">
  What the course list is grouped by, and in what order the groups come.
  Students never see them — a category orders these pages, nothing else.
</p>

<?php if ($categories === []): ?>
  <p class="empty">No category yet, so every course is listed on its own.</p>
<?php endif; ?>

<?php foreach ($categories as $k): ?>
<form method="post" class="card" style="margin-bottom:10px">
  <?= admin_csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
  <div class="row" style="align-items:flex-end">
    <div style="flex: 3 1 320px">
      <label for="title-<?= (int) $k['id'] ?>">Name</label>
      <input id="title-<?= (int) $k['id'] ?>" name="title" type="text"
             maxlength="160" value="<?= h($k['title']) ?>">
    </div>
    <div style="flex: 0 1 110px">
      <label for="order-<?= (int) $k['id'] ?>">Order</label>
      <input id="order-<?= (int) $k['id'] ?>" name="sort_order" type="number"
             step="1" value="<?= (int) $k['sort_order'] ?>">
    </div>
    <div style="flex: 0 0 auto">
      <button class="btn" type="submit" name="action" value="save">Save</button>
      <button class="btn danger" type="submit" name="action" value="delete"
              onclick="return confirm('Remove this category? Its courses stay, on no category.')">Remove</button>
    </div>
    <div style="flex: 1 1 auto" class="muted">
      <?= (int) $k['courses'] ?> course<?= (int) $k['courses'] === 1 ? '' : 's' ?>
    </div>
  </div>
</form>
<?php endforeach; ?>

<h3 style="margin-top:24px">Add a category</h3>
<form method="post" class="card">
  <?= admin_csrf_field() ?>
  <div class="row">
    <div style="flex: 3 1 320px">
      <label for="title">Name</label>
      <input id="title" name="title" type="text" maxlength="160" placeholder="Onboarding">
    </div>
    <div style="flex: 0 1 110px">
      <label for="sort_order">Order</label>
      <input id="sort_order" name="sort_order" type="number" step="1" value="0">
    </div>
  </div>
  <p style="margin-top:14px"><button class="btn primary" type="submit">Add</button></p>
</form>
<?php admin_foot();
