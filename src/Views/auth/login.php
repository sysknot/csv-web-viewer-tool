<?php use App\Support\Helpers; ?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= Helpers::h($title) ?></title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="auth-page"><main class="auth-card"><h1><?= Helpers::h($title) ?></h1><p><?= $admin ? 'Acceso restringido al backoffice.' : 'Introduce la contraseña para consultar los datos.' ?></p>
<?php if ($error): ?><p class="alert error" role="alert"><?= Helpers::h($error) ?></p><?php endif; ?>
<form method="post" action="<?= Helpers::h($action) ?>" novalidate>
<input type="hidden" name="csrf" value="<?= Helpers::h($csrf) ?>">
<?php if ($admin): ?><label>Usuario<input name="username" autocomplete="username" required autofocus></label><?php endif; ?>
<label>Contraseña<input type="password" name="password" autocomplete="current-password" required <?= !$admin ? 'autofocus' : '' ?>></label>
<button type="submit">Entrar</button>
</form>
<?php if (!$admin): ?><p class="muted"><a href="/admin/login">Administración</a></p><?php endif; ?>
</main></body></html>
