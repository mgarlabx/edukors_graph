<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/admin.php';
admin_session_start();
admin_sign_out();
header('Location: login.php');
exit;
