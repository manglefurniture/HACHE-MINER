<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
require_once dirname(__DIR__).'/app/diagnostics.php';
require_once dirname(__DIR__).'/app/pool-overview.php';
miner_security_headers();
try {
    miner_session();
    miner_restore_remember();
    if (isset($_SESSION['admin_id']) && ($_GET['page']??'')==='login') { header('Location: /',true,303);exit; }
    $page=(string)($_GET['page']??'dashboard');
    $error='';$notice='';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        miner_check_csrf();
        $action=(string)($_POST['action']??'');
        if ($action==='login') {
            if (miner_login((string)($_POST['username']??''),(string)($_POST['password']??''),(string)($_SERVER['REMOTE_ADDR']??''))) {
                if (isset($_POST['remember_device']) && $_POST['remember_device']==='yes') {
                    miner_issue_remember((int)$_SESSION['admin_id'],(string)($_SERVER['HTTP_USER_AGENT']??''));
                } else {
                    miner_revoke_current_device((int)$_SESSION['admin_id']);
                }
                header('Location: /',true,303);exit;
            }
            $error='Credenciales incorrectas o temporalmente bloqueadas.';
        } else {
            $uid=miner_require_admin();
            if ($action==='logout') {
                miner_revoke_current_device($uid);
                miner_audit($uid,'logout');$_SESSION=[];session_destroy();header('Location: /?page=login',true,303);exit;
            }
            try {
                if ($action==='revoke_devices') {
                    miner_revoke_all_devices($uid);
                } elseif ($action==='secret') {
                    $name=(string)($_POST['secret_name']??'');
                    if ($name!=='salad:api-key') throw new InvalidArgumentException('Secreto no permitido.');
                    miner_put_secret($name,(string)($_POST['secret_value']??''));
                    miner_audit($uid,'secret_updated',$name);
                 } elseif ($action==='salad_target_add') {
                    $org=(string)($_POST['org_slug']??'');
                    $project=(string)($_POST['project_slug']??'');
                    miner_salad_add_target($org,$project,(string)($_POST['target_label']??''));
                    miner_audit($uid,'salad_target_added',strtolower(trim($org)).'/'.strtolower(trim($project)));
                } elseif ($action==='salad_target_state') {
                    $id=filter_var($_POST['target_id']??null,FILTER_VALIDATE_INT);
                    $enabled=(string)($_POST['target_enabled']??'')==='1';
                    if(!$id) throw new InvalidArgumentException('Target invalid');
                    miner_salad_set_enabled((int)$id,$enabled);
                    miner_audit($uid,'salad_target_enabled',($enabled?'on':'off').':'.$id);
                } elseif ($action==='wallet') {
                    $org=(string)($_POST['organization']??'');$address=trim((string)($_POST['address']??''));
                    $label=trim((string)($_POST['label']??''));
                    if (!miner_known_salad_org($org) || !preg_match('/^prl1[a-z0-9]{30,150}$/D',$address) || strlen($label)>80 || $label==='') throw new InvalidArgumentException('Datos de dirección PRL inválidos.');
                    $st=miner_db()->prepare('INSERT IGNORE INTO wallets (organization,coin,label,address) VALUES (?,?,?,?)');$st->execute([$org,'PRL',$label,$address]);
                    miner_audit($uid,'wallet_added',$org);
                } elseif ($action==='rate') {
                    $org=(string)($_POST['organization']??'');$gpu=trim((string)($_POST['gpu_class']??''));
                    $priority=(string)($_POST['priority']??'');$rate=miner_finite_decimal($_POST['usd_per_hour']??null);
                    if (!miner_known_salad_org($org) || !in_array($priority,['low','medium','high'],true) || strlen($gpu)>120 || $gpu==='' || $rate===null || (float)$rate>100) throw new InvalidArgumentException('Tarifa inválida.');
                    $st=miner_db()->prepare('INSERT INTO gpu_rates (organization,gpu_class,priority,usd_per_hour) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE usd_per_hour=VALUES(usd_per_hour),effective_at=CURRENT_TIMESTAMP');
                    $st->execute([$org,$gpu,$priority,$rate]);
                    miner_audit($uid,'gpu_rate_updated',$org);
                } elseif ($action==='charge') {
                    $org=(string)($_POST['organization']??'');$amount=miner_finite_decimal($_POST['amount_usd']??null,4);
                    $start=(string)($_POST['period_start']??'');$end=(string)($_POST['period_end']??'');$reference=trim((string)($_POST['source_reference']??''));
                    if (!miner_known_salad_org($org) || $amount===null || strlen($reference)>200 || $reference==='' || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$start) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$end) || $start>=$end) throw new InvalidArgumentException('Cargo inválido. Usar fechas UTC.');
                    $st=miner_db()->prepare('INSERT INTO reconciled_charges (organization,period_start,period_end,amount_usd,source_reference) VALUES (?,?,?,?,?)');
                    $st->execute([$org,$start,$end,$amount,$reference]);
                    miner_audit($uid,'actual_charge_recorded',$org);
                } else throw new InvalidArgumentException('Acción desconocida.');
                header('Location: /?page='.rawurlencode($page).'&saved=1',true,303);exit;
            } catch (Throwable $e) {
                $error='No se pudo guardar. Verifica campos, permisos o duplicados.';
                error_log('[hache-miner] admin-write-error '.get_class($e));
            }
        }
    }
    if ($page!=='login') $uid=miner_require_admin();
    $csrf=miner_h(miner_csrf());
    $groups=$wallets=$runs=$secrets=$rates=$charges=[];$trustedDevices=[];$targets=[];$collectorDiag=null;$replicaStates=[];$poolOverview=null;
    if ($page!=='login') {
        $targets=miner_salad_targets(false);
        $groups=miner_db()->query('SELECT id,organization,project_name,group_name,state,priority,desired_replicas,last_seen_at FROM group_state ORDER BY organization,group_name LIMIT 100')->fetchAll();
        if ($page==='dashboard') $replicaStates=miner_recent_group_statuses();
        $wallets=miner_db()->query('SELECT organization,coin,label,address FROM wallets ORDER BY organization,label')->fetchAll();
        $runs=miner_db()->query('SELECT source_name,observed_at,status,detail FROM sync_runs ORDER BY id DESC LIMIT 18')->fetchAll();
        $secrets=miner_db()->query('SELECT secret_name,updated_at FROM secret_store ORDER BY secret_name')->fetchAll();
        $rates=miner_db()->query('SELECT organization,gpu_class,priority,usd_per_hour FROM gpu_rates ORDER BY organization,gpu_class')->fetchAll();
        $charges=miner_db()->query('SELECT organization,period_start,period_end,amount_usd,source_reference FROM reconciled_charges ORDER BY id DESC LIMIT 20')->fetchAll();
        if ($page==='diagnostics') $collectorDiag=miner_collector_diagnostics();
        if ($page==='dashboard' || $page==='history') $poolOverview=miner_pool_overview();
        if ($page==='settings') {
            $s=miner_db()->prepare('SELECT device_label,created_at,last_used_at,expires_at FROM trusted_devices WHERE admin_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 10');
            $s->execute([$uid]);$trustedDevices=$s->fetchAll();
        }
    }
} catch(Throwable $e) {
    error_log('[hache-miner] page-error '.get_class($e));
    http_response_code(503);exit('Sistema aún no configurado o temporalmente no disponible.');
}
function field(string $label,string $name,string $type='text',string $placeholder=''): void {
    echo '<label>'.miner_h($label).'<input required name="'.miner_h($name).'" type="'.miner_h($type).'" placeholder="'.miner_h($placeholder).'" '.($type==='number'?'step="any" min="0"':'maxlength="200"').' autocomplete="off"></label>';
}
function orgselect(): void {
    $rows=miner_db()->query('SELECT DISTINCT organization_slug FROM salad_targets ORDER BY organization_slug')->fetchAll(PDO::FETCH_COLUMN);
    echo '<select name="organization" aria-label="Organización Salad">';
    foreach ($rows as $org) echo '<option value="'.miner_h((string)$org).'">'.miner_h(strtoupper((string)$org)).'</option>';
    echo '</select>';
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HACHE-MINER · Control privado</title><link rel="stylesheet" href="/style.css"></head>
<body><header><a class="brand" href="/">HACHE<span>MINER</span></a><small>CONTROL PRIVADO · PRL</small>
<?php if($page!=='login'): ?><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="ghost" name="action" value="logout">Salir</button></form><?php endif; ?></header>
<main>
<?php if($page==='login'): ?>
<section class="login card"><div class="eyebrow">ACCESO ADMINISTRATIVO</div><h1>Iniciar sesión</h1>
<?php if($error): ?><p class="error"><?= miner_h($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
<label>Usuario<input required name="username" autocomplete="username" maxlength="64"></label>
<label>Contraseña<input required type="password" name="password" autocomplete="current-password"></label>
<label class="remember-label"><input name="remember_device" type="checkbox" value="yes"> Recordar este dispositivo durante 30 días</label>
<p class="muted">Solo actívalo en un teléfono o computadora personal. Puedes revocarlo desde Configuración.</p>
<button name="action" value="login">Ingresar</button></form></section>
<?php else: ?>
<div class="eyebrow">CENTRO DE OPERACIONES / HACHE INTERACTIVE</div>
<h1>Minería, bajo control.</h1>
<p class="muted">Registro independiente para HACHE e INTERACTIVE. Los datos no observados se muestran como desconocidos, nunca como cero.</p>
<nav><a href="/">Resumen</a><a href="/?page=diagnostics">Estado de recolección</a><a href="/?page=settings">Configuración</a><a href="/?page=history">Historial</a></nav>
<?php if(isset($_GET['saved'])): ?><p class="success">Configuración guardada.</p><?php endif; ?>
<?php if($error): ?><p class="error"><?= miner_h($error) ?></p><?php endif; ?>
<?php if($page==='diagnostics'): ?>
<section class="card"><h2>SaladCloud · Estado de lectura</h2>
<p class="muted">Información histórica de la base de datos, actualizada por el recolector cada cinco minutos. Una lectura reciente no garantiza que haya GPU asignadas.</p>
<div class="kpis">
<div><strong><?= (int)$collectorDiag['summary']['fresh'] ?></strong><small>Proyectos con lectura reciente</small></div>
<div><strong><?= (int)$collectorDiag['summary']['stale'] ?></strong><small>Proyectos atrasados</small></div>
<div><strong><?= (int)$collectorDiag['summary']['groups_seen'] ?></strong><small>Grupos observados en 15 min</small></div>
<div><strong><?= (int)$collectorDiag['summary']['ready'] ?> / <?= (int)$collectorDiag['summary']['instances'] ?></strong><small>Listas / observadas (10 min)</small></div>
</div>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Organización / proyecto</th><th>Estado</th><th>Último ciclo UTC</th><th>Grupos 15 min</th><th>Réplicas solicitadas</th><th>Instancias observadas</th><th>Listas</th></tr></thead><tbody>
<?php foreach($collectorDiag['targets'] as $t): ?>
<tr><td data-label="Organización"><strong><?= miner_h($t['label']) ?></strong><small><?= miner_h($t['organization'].' / '.$t['project']) ?></small></td>
<td data-label="Estado"><?= miner_h($t['status']==='pausado'?'Pausado':miner_collector_health_label($t['status'])) ?></td>
<td data-label="Último ciclo UTC"><?= miner_h((string)($t['observed_at']??'Sin datos')) ?><small><?= miner_h((string)($t['detail']??'')) ?></small></td>
<td data-label="Grupos 15 min"><?= (int)$t['groups_seen'] ?></td>
<td data-label="Solicitadas"><?= (int)$t['desired_replicas'] ?></td>
<td data-label="Observadas 10 min"><?= (int)$t['instances'] ?></td>
<td data-label="Listas 10 min"><?= (int)$t['ready'] ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="muted">«Observadas» son instancias únicas vistas en 10 minutos; «listas» cumplen running + ready + started. Ninguno de estos estados acredita shares aceptados ni hashrate de PRL. Réplicas solicitadas y muestras históricas no son máquinas activas.</p></section>
<section class="card"><h2>Advertencias recientes (24 h)</h2>
<?php if(!$collectorDiag['warnings']): ?><p class="muted">Sin advertencias de logs registradas durante las últimas 24 horas. No implica ausencia de fallos fuera de la cobertura.</p><?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>UTC</th><th>Organización</th><th>Grupo</th><th>Mensaje depurado</th></tr></thead><tbody>
<?php foreach($collectorDiag['warnings'] as $w): ?><tr><td data-label="UTC"><?= miner_h($w['logged_at']) ?></td><td data-label="Organización"><?= miner_h(strtoupper($w['organization'])) ?></td><td data-label="Grupo"><?= miner_h($w['group_name']) ?></td><td data-label="Advertencia"><?= miner_h($w['summary']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php elseif($page==='settings'): ?>
<div class="grid">
<section class="card"><h2>Dispositivos recordados</h2>
<p class="muted">Se mantiene el acceso durante 30 días sin almacenar tu contraseña. Cerrar sesión revoca el dispositivo actual.</p>
<?php if (!$trustedDevices): ?><p class="muted">No hay dispositivos recordados.</p><?php endif; ?>
<?php foreach($trustedDevices as $t): ?><p><?= miner_h($t['device_label']) ?> · <small>Vence <?= miner_h($t['expires_at']) ?> UTC</small></p><?php endforeach; ?>
<?php if ($trustedDevices): ?><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button name="action" value="revoke_devices">Revocar todos los dispositivos</button></form><?php endif; ?>
</section>
<section class="card"><h2>Una clave API de Salad</h2><p class="muted">Esta clave compartida permite consultar todas las organizaciones autorizadas. Se guarda cifrada y nunca se muestra de nuevo.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="secret_name" value="salad:api-key">
<?php field('Clave API compartida','secret_value','password','Solo para configurar o sustituir'); ?>
<button name="action" value="secret">Guardar clave cifrada</button></form>
<?php foreach($secrets as $s): ?><p class="muted"><?= $s['secret_name']==='salad:api-key'?'Clave compartida configurada': 'Clave heredada existente' ?> · <?= miner_h($s['updated_at']) ?> UTC</p><?php endforeach; ?></section>
<section class="card"><h2>Organizaciones y proyectos Salad</h2>
<p class="muted">Agrega otra organización y el proyecto que desees supervisar, sin crear una nueva clave API. Desactivar detiene las lecturas futuras sin borrar el historial.</p>
<?php foreach ($targets as $t): ?>
<div class="salad-target"><strong><?= miner_h($t['label']) ?></strong>
<small><?= miner_h($t['organization_slug'].' / '.$t['project_slug']) ?> · <?= $t['enabled']?'Activo':'Pausado' ?></small>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
<input type="hidden" name="target_id" value="<?= (int)$t['id'] ?>">
<input type="hidden" name="target_enabled" value="<?= $t['enabled']?'0':'1' ?>">
<button class="ghost" name="action" value="salad_target_state"><?= $t['enabled']?'Pausar':'Activar' ?></button></form></div>
<?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
<?php field('Nombre visible','target_label','text','Nueva organización'); ?>
<?php field('Identificador de organización','org_slug','text','ejemplo-tercera'); ?>
<?php field('Proyecto','project_slug','text','default'); ?>
<button name="action" value="salad_target_add">Agregar organización/proyecto</button></form></section>
<section class="card"><h2>Billetera pública PRL</h2><p class="muted">Solo direcciones para seguimiento. No almacenar semillas ni claves privadas.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php orgselect();field('Etiqueta','label');field('Dirección PRL','address');?><button name="action" value="wallet">Agregar dirección</button></form>
<?php foreach($wallets as $w): ?><p><?= miner_h($w['organization'].' · '.$w['label']) ?><small> <?= miner_h(substr($w['address'],0,12)) ?>…</small></p><?php endforeach; ?></section>
<section class="card"><h2>Tarifas de GPU</h2><p class="muted">No se asignan precios por defecto. Introducir solo tarifas confirmadas.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php orgselect();field('Clase de GPU (ID de Salad)','gpu_class'); ?>
<select name="priority"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
<?php field('USD por hora','usd_per_hour','number','0.130000'); ?><button name="action" value="rate">Guardar tarifa</button></form>
<?php foreach($rates as $r): ?><p class="muted"><?= miner_h($r['organization'].' · '.$r['gpu_class'].' · '.$r['priority'].' · $'.$r['usd_per_hour'].'/h') ?></p><?php endforeach; ?></section>
<section class="card"><h2>Cargos verificados</h2><p class="muted">Registrar solo importes facturados, no proyecciones; fechas UTC.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><?php orgselect();field('Inicio UTC (AAAA-MM-DD HH:MM:SS)','period_start');field('Fin UTC','period_end');field('Cargo USD','amount_usd','number');field('Referencia de facturación única','source_reference'); ?><button name="action" value="charge">Registrar cargo real</button></form></section></div>
<?php elseif($page==='history'): ?>
<div class="grid"><section class="card"><h2>Últimos ciclos</h2><div class="tablewrap mobile-stack"><table><thead><tr><th>Fuente</th><th>UTC</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>
<?php foreach($runs as $r): ?><tr><td data-label="Fuente"><?= miner_h($r['source_name']) ?></td><td data-label="UTC"><?= miner_h($r['observed_at']) ?></td><td data-label="Estado"><?= miner_h($r['status']) ?></td><td data-label="Detalle"><?= miner_h($r['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$runs):?><p class="muted">Sin observaciones. Se inicia historial después de habilitar el recolector.</p><?php endif; ?></section>
<section class="card"><h2>Facturación conciliada</h2><table><tr><th>Org.</th><th>Período UTC</th><th>USD</th></tr>
<?php foreach($charges as $c):?><tr><td><?= miner_h($c['organization']) ?></td><td><?= miner_h($c['period_start'].' → '.$c['period_end']) ?></td><td><?= miner_h($c['amount_usd']) ?></td></tr><?php endforeach;?></table></section></div>
<?php else: ?>
<div class="kpis"><div class="card"><div class="eyebrow">ORGANIZACIONES</div><strong><?= count(array_unique(array_column($targets,'organization_slug'))) ?></strong><small>Organizaciones configuradas</small></div><div class="card"><div class="eyebrow">GRUPOS OBSERVADOS</div><strong><?= count($groups) ?></strong><small>Registro desde activación</small></div><div class="card"><div class="eyebrow">COSTO FACTURADO</div><strong>Sin conciliar</strong><small>No sustituir por proyección</small></div><div class="card"><div class="eyebrow">SALDO PRL · KRYPTEX</div>
<strong><?= $poolOverview['all_balances_fresh'] ? miner_h($poolOverview['confirmed_prl']).' PRL confirmados' : 'Sin lectura completa' ?></strong>
<small><?= $poolOverview['all_balances_fresh'] ? miner_h($poolOverview['pending_prl']).' PRL pendientes' : 'No se sustituyen datos desconocidos por cero' ?></small></div></div>
<section class="card"><h2>Kryptex · seguimiento de billeteras públicas</h2>
<p class="muted">Saldos observados del pool; no son ingresos del periodo ni fondos vendidos. Si dos organizaciones comparten dirección, se cuenta solo una vez. El hashrate pertenece al conjunto de workers por billetera y no permite atribuir GPU individuales.</p>
<?php if($poolOverview['wallets']): ?>
<p><strong><?= (int)$poolOverview['distinct_wallets'] ?></strong> direcciones únicas ·
<?php if($poolOverview['workers']!==null): ?><strong><?= (int)$poolOverview['workers'] ?></strong> workers online · <?php endif; ?>
<?php if($poolOverview['hashrate_ths']!==null): ?><strong><?= miner_h($poolOverview['hashrate_ths']) ?> TH/s</strong> promedio de 30 minutos<?php else: ?>Hashrate no completamente observado<?php endif; ?></p>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Billetera</th><th>Organizaciones asociadas</th><th>Lectura UTC</th><th>Pendiente PRL</th><th>Confirmado PRL</th><th>Workers online</th><th>Hashrate TH/s</th><th>Estado de sincronización</th></tr></thead><tbody>
<?php foreach($poolOverview['wallets'] as $wallet): ?><tr>
<td data-label="Billetera"><?= miner_h($wallet['label'].' · …'.$wallet['suffix']) ?></td>
<td data-label="Asociada a"><?= miner_h(implode(', ',array_map('strtoupper',$wallet['organizations']))) ?></td>
<td data-label="Lectura UTC"><?= miner_h($wallet['observed_at']??'Pendiente') ?> <?= $wallet['fresh']?'':'(sin lectura reciente)' ?></td>
<td data-label="Pendiente PRL"><?= miner_h($wallet['pending']??'Sin datos') ?></td>
<td data-label="Confirmado PRL"><?= miner_h($wallet['confirmed']??'Sin datos') ?></td>
<td data-label="Workers"><?= $wallet['workers']===null?'Sin datos':(int)$wallet['workers'] ?></td>
<td data-label="Hashrate TH/s"><?= miner_h($wallet['hashrate_ths']??'Sin datos') ?></td>
<td data-label="Sincronización"><?= miner_h($wallet['sync_status']??'Sin datos') ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php if($poolOverview['too_many']): ?><p class="muted">La lista supera el límite de lectura de 100 registros. No se publican totales incompletos.</p><?php endif; ?>
<?php else: ?><p class="muted">No hay billeteras PRL registradas todavía.</p><?php endif; ?></section>
<section class="card"><h2>Grupos de SaladCloud</h2><p class="muted">Instancias únicas observadas en los últimos 10 minutos. «Listas» indica contenedores running, ready y started; no confirma minería PRL.</p><div class="tablewrap mobile-stack"><table><thead><tr><th>Organización</th><th>Grupo</th><th>Estado grupo</th><th>Prioridad</th><th>Solicitadas</th><th>Observadas</th><th>Listas</th><th>Última lectura UTC</th></tr></thead><tbody>
<?php foreach($groups as $g): $snap=$replicaStates[(int)$g['id']]??null; ?><tr>
<td data-label="Organización"><?= miner_h(strtoupper($g['organization'])) ?></td><td data-label="Grupo"><?= miner_h($g['group_name']) ?></td>
<td data-label="Estado grupo"><?= miner_h($g['state']) ?></td><td data-label="Prioridad"><?= miner_h((string)$g['priority']) ?></td>
<td data-label="Solicitadas"><?= (int)$g['desired_replicas'] ?></td>
<td data-label="Observadas"><?= $snap!==null?(int)$snap['observed']:'Sin muestras' ?></td>
<td data-label="Listas"><?= $snap!==null?(int)$snap['ready']:'Sin muestras' ?></td>
<td data-label="Última lectura UTC"><?= miner_h($g['last_seen_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$groups):?><p class="muted">Aún no hay historial. Configura las claves de Salad y activa el recolector.</p><?php endif; ?></section>
<?php endif; ?>
<?php endif; ?></main><footer>HACHE INTERACTIVE · MINER MONITORING · Las estimaciones no equivalen a cargos facturados.</footer></body></html>
