<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
require_once dirname(__DIR__).'/app/diagnostics.php';
require_once dirname(__DIR__).'/app/pool-overview.php';
require_once dirname(__DIR__).'/app/monitor.php';
require_once dirname(__DIR__).'/app/finances.php';
require_once dirname(__DIR__).'/app/accounting.php';
require_once dirname(__DIR__).'/app/salad-credits.php';
require_once dirname(__DIR__).'/app/reallocate.php';
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
                } elseif ($action==='manual_reallocate') {
                    if ($page!=='reallocate') throw new DomainException('Wrong confirmation endpoint');
                    $challenge=$_SESSION['reallocation_challenge']??null;
                    $postedToken=(string)($_POST['challenge']??'');
                    $postedId=filter_var($_GET['group_id']??null,FILTER_VALIDATE_INT);
                    $postedInstance=(string)($_GET['instance_id']??'');
                    if (!is_array($challenge) || !is_string($challenge['token']??null)
                        || $postedToken==='' || !hash_equals($challenge['token'],$postedToken)
                        || (int)($challenge['admin_id']??0)!==$uid
                        || (int)($challenge['group_id']??0)!==$postedId
                        || ($challenge['instance_id']??'')!==$postedInstance
                        || (int)($challenge['expires']??0)<time()) {
                        throw new DomainException('Expired or invalid confirmation');
                    }
                    if (!miner_reallocation_confirmed((string)($_POST['confirmation']??''))) {
                        throw new DomainException('Second confirmation not accepted');
                    }
                    if (!miner_reallocation_password($uid,(string)($_POST['password']??''),(string)($_SERVER['REMOTE_ADDR']??''))) {
                        throw new DomainException('Reauthentication failed or throttled');
                    }
                    // Consume challenge before making any non-idempotent request.
                    unset($_SESSION['reallocation_challenge']);
                    miner_reallocation_execute($uid,(int)$postedId,$postedInstance);
                    $_SESSION['manual_reallocate_success']='Salad aceptó tu solicitud manual para otra máquina. La nueva asignación todavía no está confirmada.';
                    header('Location: /?page=monitor',true,303);exit;
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
                } elseif ($action==='credit_snapshot') {
                    if($page!=='finance')throw new DomainException('Credit snapshot requires admin finance');
                    $orgs=array_values(array_unique(array_column(miner_salad_targets(false),'organization_slug')));
                    $row=miner_credit_validate([
                        'organization'=>$_POST['snapshot_org']??'',
                        'snapshot_date'=>$_POST['snapshot_date']??'',
                        'issued_usd'=>$_POST['issued_usd']??'',
                        'consumed_usd'=>$_POST['consumed_usd']??'',
                        'available_usd'=>$_POST['available_usd']??'',
                        'expired_usd'=>$_POST['expired_usd']??'',
                        'evidence_reference'=>$_POST['snapshot_reference']??''
                    ],$orgs);
                    $created=miner_credit_snapshot_insert($row);
                    miner_audit($uid,'salad_credit_snapshot',$row['organization'].':'.$row['snapshot_date']);
                    $_SESSION['credit_saved']=$created?'Saldo acumulado de Salad registrado':'Captura ya registrada, sin duplicar';
                    header('Location: /?page=finance',true,303);exit;
                } elseif ($action==='accounting_event') {
                    if ($page!=='finance' || !miner_accounting_ready()) {
                        throw new DomainException('Accounting feature not initialized');
                    }
                    $knownOrgs=array_values(array_unique(array_column(miner_salad_targets(false),'organization_slug')));
                    $validated=miner_accounting_validate($_POST,$knownOrgs);
                    miner_accounting_insert($uid,$validated);
                    $_SESSION['accounting_saved']='Movimiento contable registrado. Referencia única; no se cuentan automáticamente saldos como cobros.';
                    header('Location: /?page=finance',true,303);exit;
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
                $error=$action==='manual_reallocate'
                    ? 'No se pudo solicitar la reasignación. Verifica la confirmación, la contraseña, el estado actual y el límite de 15 minutos. No vuelvas a pulsar hasta comprobar el estado en Salad.'
                    : 'No se pudo guardar. Verifica campos, permisos o duplicados.';
                error_log('[hache-miner] admin-write-error '.get_class($e));
            }
        }
    }
    if ($page!=='login') $uid=miner_require_admin();
    $csrf=miner_h(miner_csrf());
    $groups=$wallets=$runs=$secrets=$rates=$charges=[];$trustedDevices=[];$targets=[];$collectorDiag=null;$replicaStates=[];$poolOverview=null;$monitorData=null;$financeData=[];$ledgerData=null;$ledgerPool=null;$ledgerSaved=null;$creditData=null;$creditSaved=null;$reallocationTarget=null;$reallocationChallenge='';$manualCooldown=[];$manualSuccess=null;
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
        if ($page==='monitor') {
            $monitorData=miner_monitor_inventory();
            $manualCooldown=miner_reallocation_cooldown_index();
            if (isset($_SESSION['manual_reallocate_success']) && is_string($_SESSION['manual_reallocate_success'])) {
                $manualSuccess=$_SESSION['manual_reallocate_success'];
                unset($_SESSION['manual_reallocate_success']);
            }
        }
        if ($page==='reallocate') {
            try {
                $getGroup=filter_var($_GET['group_id']??null,FILTER_VALIDATE_INT);
                $getInstance=(string)($_GET['instance_id']??'');
                $reallocationTarget=miner_reallocation_target((int)$getGroup,$getInstance);
                if(miner_reallocation_cooldown_seconds($reallocationTarget,$getInstance)>0) {
                    throw new DomainException('Manual cooldown still active');
                }
                if ($_SERVER['REQUEST_METHOD']==='GET') {
                    $reallocationChallenge=bin2hex(random_bytes(24));
                    $_SESSION['reallocation_challenge']=[
                        'token'=>$reallocationChallenge,'admin_id'=>$uid,
                        'group_id'=>(int)$getGroup,'instance_id'=>$getInstance,
                        'expires'=>time()+180
                    ];
                } else {
                    $reallocationChallenge=(string)($_SESSION['reallocation_challenge']['token']??'');
                }
            } catch (Throwable $e) {
                $error='Esta instancia ya no reúne las condiciones para solicitar una reasignación. Vuelve al monitor.';
                $reallocationTarget=null;
            }
        }
        if ($page==='finance') {
            $financeData=miner_finance_overview();
            $creditData=miner_credit_snapshot_overview();
            if(isset($_SESSION['credit_saved']) && is_string($_SESSION['credit_saved'])){
                $creditSaved=$_SESSION['credit_saved'];unset($_SESSION['credit_saved']);
            }
            $ledgerData=miner_accounting_overview();
            $ledgerPool=miner_pool_overview();
            if (isset($_SESSION['accounting_saved']) && is_string($_SESSION['accounting_saved'])) {
                $ledgerSaved=$_SESSION['accounting_saved'];
                unset($_SESSION['accounting_saved']);
            }
        }
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
<title>HACHE-MINER · Control privado</title><link rel="stylesheet" href="/style.css?v=<?= miner_h(substr(hash_file('sha256',__DIR__.'/style.css'),0,12)) ?>"></head>
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
<nav><a href="/">Resumen</a><a href="/?page=monitor">Monitor GPU</a><a href="/?page=finance">Finanzas</a><a href="/?page=diagnostics">Estado de recolección</a><a href="/?page=settings">Configuración</a><a href="/?page=history">Historial</a></nav>
<?php if(isset($_GET['saved'])): ?><p class="success">Configuración guardada.</p><?php endif; ?>
<?php if($page==='monitor' && $manualSuccess!==null): ?><p class="success"><?= miner_h($manualSuccess) ?></p><?php endif; ?>
<?php if($error): ?><p class="error"><?= miner_h($error) ?></p><?php endif; ?>
<?php if($page==='reallocate'): ?>
<section class="card"><h2>Segunda validación · Reasignar instancia</h2>
<?php if($reallocationTarget): ?>
<p>Organización: <strong><?= miner_h(strtoupper((string)$reallocationTarget['organization'])) ?></strong> ·
Proyecto: <strong><?= miner_h((string)$reallocationTarget['project_name']) ?></strong></p>
<p>Grupo: <strong><?= miner_h((string)$reallocationTarget['group_name']) ?></strong></p>
<p>Instancia exacta: <code><?= miner_h((string)$reallocationTarget['instance_id']) ?></code></p>
<p class="muted">Este es un control <strong>manual independiente</strong> de las reglas automáticas: puedes utilizarlo incluso si la GPU trabaja a 140 TH/s o más. Solicita a Salad retirar este nodo y buscar otro. La minería puede interrumpirse y una máquina mejor no está garantizada. No modifica la GPU, prioridad ni el número de réplicas.</p>
<form method="post" action="/?page=reallocate&amp;group_id=<?= (int)$reallocationTarget['id'] ?>&amp;instance_id=<?= rawurlencode((string)$reallocationTarget['instance_id']) ?>">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<input type="hidden" name="challenge" value="<?= miner_h($reallocationChallenge) ?>">
<label>Para responder a la confirmación, escribe REASIGNAR
<input name="confirmation" autocomplete="off" required maxlength="9" pattern="REASIGNAR" placeholder="REASIGNAR"></label>
<label>Vuelve a introducir tu contraseña de administrador
<input type="password" name="password" autocomplete="current-password" required maxlength="1024"></label>
<p class="muted">Confirmación válida durante tres minutos. Solo se permite una solicitud por instancia en 15 minutos. La API se vuelve a consultar antes de ejecutarla.</p>
<button name="action" value="manual_reallocate">Sí, solicitar reasignación</button>
<a class="ghost" href="/?page=monitor">Cancelar</a>
</form>
<?php else: ?><p class="muted">Regresa al monitor y selecciona una instancia operativa.</p><a href="/?page=monitor">Volver al monitor</a><?php endif; ?></section>
<?php elseif($page==='monitor'): ?>
<section class="card"><h2>Monitor en vivo · rendimiento por instancia</h2>
<?php $liveHash=$monitorData['live_hashrate']; ?>
<div class="monitor-live-summary" aria-label="Resumen de hashrate medido">
<div class="monitor-live-main">
<span class="monitor-live-label">TH/s observados · última medición efectiva por GPU</span>
<div class="monitor-live-value">
<?php if($liveHash['ths']!==null): ?>
<strong><?= miner_h(number_format($liveHash['ths'],2,'.',',')) ?></strong><span>TH/s</span>
<?php else: ?><strong class="monitor-live-empty">Sin lecturas recientes</strong><?php endif; ?>
</div>
<p class="monitor-live-coverage"><?= (int)$liveHash['measured'] ?> de <?= (int)$liveHash['ready'] ?> instancias listas con medición válida (últimos 10 min)
<?php if($liveHash['missing']>0): ?> · <strong><?= (int)$liveHash['missing'] ?> sin datos: total parcial</strong><?php endif; ?></p>
<?php if($liveHash['last_at']!==null): ?>
<p class="muted monitor-live-timestamp">Lecturas UTC desde <?= miner_h($liveHash['oldest_at']) ?> hasta <?= miner_h($liveHash['last_at']) ?>. No es una medición simultánea ni una proyección.</p>
<?php endif; ?>
</div>
<?php if($liveHash['organizations']): ?>
<div class="monitor-live-orgs">
<?php foreach($liveHash['organizations'] as $org=>$breakdown): ?>
<div><span><?= miner_h(strtoupper($org)) ?></span>
<strong><?= $breakdown['ths']===null?'Sin lectura':miner_h(number_format($breakdown['ths'],2,'.',',')).' TH/s' ?></strong>
<small><?= (int)$breakdown['measured'] ?>/<?= (int)$breakdown['ready'] ?> GPU medidas</small></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php $liveCost=$monitorData['live_hourly_cost']; ?>
<details class="monitor-cost-detail">
<summary><span>Costo horario estimado · GPUs activas</span>
 <strong><?= $liveCost['usd_per_hour']===null?'Sin tarifas confirmadas':' y recientes, sin contar dos veces la misma instancia. No incluye máquinas sin medición ni sirve para calcular beneficios; si hay algoritmos distintos, sus tasas no son económicamente comparables.</p>
<p class="muted">Actualización automática de Salad cada cinco minutos. Aquí solo aparecen grupos reportados durante las últimas ocho horas. Los eliminados o antiguos conservan su historial privado, pero no saturan el monitor.</p>
<div class="kpis">
<div><strong><?= (int)$monitorData['stats']['current_groups'] ?></strong><small>Grupos recientes</small></div>
<div><strong><?= (int)$monitorData['stats']['desired'] ?></strong><small>Réplicas solicitadas</small></div>
<div><strong><?= (int)$monitorData['stats']['observed'] ?></strong><small>Instancias observadas</small></div>
<div><strong><?= (int)$monitorData['stats']['ready'] ?></strong><small>Contenedores listos</small></div></div>
<p class="muted">En espera: <?= (int)$monitorData['stats']['waiting'] ?>. El color de rendimiento es una <strong>referencia de TH/s</strong> para minería PRL con RTX 4070 Ti SUPER Low, no una afirmación de rentabilidad neta en USD. El automático de Hache Natación permanece activo e independiente.</p>
<?php if (!$monitorData['groups']): ?><p class="muted">No hay grupos reportados durante las últimas ocho horas. Verifica la recolección o Configuración.</p><?php endif; ?>
<?php foreach($monitorData['targets'] as $t):
 $org=(string)$t['organization_slug'];$project=(string)$t['project_slug'];$count=0;
 foreach($monitorData['groups'] as $g) if($g['organization']===$org&&$g['project_name']===$project)$count++;
 if($count===0)continue;
?>
<h3 class="monitor-org-title"><?= miner_h(strtoupper($org)) ?> · <?= miner_h($project) ?> <small><?= $t['enabled']?'Lecturas habilitadas':'Pausado' ?> · <?= $count ?> grupos reportados en 8 h</small></h3>
<?php foreach($monitorData['groups'] as $g):
 if($g['organization']!==$org||$g['project_name']!==$project)continue;
?>
<article class="monitor-entry monitor-entry--<?= miner_h($g['signal']) ?>">
<div class="monitor-topline">
 <div><h4><?= miner_h($g['group_name']) ?></h4><span class="monitor-state"><?= miner_h(miner_monitor_status_label($g['classification'])) ?></span></div>
 <div class="monitor-group-total"><strong><?= $g['total_ths']!==null ? miner_h(number_format($g['total_ths'],2,'.','')).' TH/s':'Sin total verificable' ?></strong>
 <small><?= (int)$g['ready'] ?> listas / <?= (int)$g['desired_replicas'] ?> solicitadas</small></div>
</div>
<?php if(!$g['recent']): ?><p class="muted">Grupo reportado en las últimas ocho horas, pero su última consulta no es reciente. Las mediciones no se presentan como actuales.</p><?php endif; ?>
<?php if($g['group_log_observation']!==null): ?>
<div class="monitor-group-evidence" role="note">
 <strong>Último registro de GPU del grupo (no es el total): <?= miner_h(number_format($g['group_log_observation']['hashrate_ths'],2,'.',',')) ?> TH/s</strong>
 <span><?= miner_h($g['group_log_observation']['gpu']) ?> · UTC <?= miner_h($g['group_log_observation']['logged_at']) ?> · <?= (int)$g['group_log_observation']['samples'] ?> registros de GPU recientes</span>
 <small>Salad devolvió registros de GPU sin identidad de réplica verificable. No se reparten entre las instancias ni se suman al total general.</small>
</div>
<?php endif; ?>
<?php if(!$g['instances']): ?><p class="muted">Sin instancias observadas en los últimos 15 minutos. Puede seguir en asignación o detenido.</p><?php endif; ?>
<div class="monitor-node-grid">
<?php foreach($g['instances'] as $node):
 $signal=$node['signal'];$m=$node['metric'];
 $fingerprint=miner_reallocation_fingerprint($g,(string)$node['id']);
 $cooldown=$manualCooldown[$fingerprint]??0;
?>
<div class="monitor-node monitor-node--<?= miner_h($signal['level']) ?>">
<div class="monitor-node-heading">
 <span>Instancia <code title="<?= miner_h($node['id']) ?>">…<?= miner_h(substr((string)$node['id'],-10)) ?></code></span>
 <span class="monitor-signal"><?= miner_h($signal['label']) ?></span>
</div>
<div class="monitor-node-rate">
 <strong><?= $m!==null?miner_h(number_format($m['hashrate_ths'],2,'.','')):'—' ?></strong>
 <span>TH/s</span>
</div>
<?php if($signal['floor']!==null): ?>
<p class="monitor-margin">Umbral inferior orientativo: <?= miner_h(number_format($signal['floor'],0)) ?> TH/s · Margen: <strong><?= $signal['margin']>0?'+':'' ?><?= miner_h(number_format($signal['margin'],2,'.','')) ?> TH/s</strong></p>
<?php elseif($m===null): ?><p class="monitor-margin">Sin medición individual reciente. Contenedor listo no significa shares aceptados.</p>
<?php else: ?><p class="monitor-margin">Sin umbral de rentabilidad validado para esta GPU o moneda.</p><?php endif; ?>
<div class="monitor-trend">
<span class="monitor-chart-label">Últimas mediciones · <?= count($node['history']) ?> muestras</span>
<?php if($node['chart']!==null): ?>
<svg viewBox="0 0 360 90" preserveAspectRatio="none" role="img" aria-label="Tendencia histórica de hashrate de esta instancia">
<line x1="6" y1="82" x2="354" y2="82" stroke="currentColor" stroke-opacity=".2"/>
<polyline points="<?= miner_h($node['chart']) ?>" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
</svg>
<?php else: ?><span class="muted">Esperando al menos dos muestras verificadas</span><?php endif; ?>
<?php if($node['avg_recent']!==null): ?><small>Promedio de últimas muestras: <?= miner_h(number_format($node['avg_recent'],2,'.','')) ?> TH/s</small><?php endif; ?>
</div>
<div class="monitor-node-facts">
<span>GPU: <?= miner_h($m['gpu_model']??$g['gpu_class']??'No identificada') ?></span>
<span>Potencia: <?= $m!==null && $m['watts']!==null?miner_h(number_format($m['watts'],0)).' W':'Sin datos' ?></span>
<span>Estado: <?= miner_h($node['state']) ?></span>
<span>Última medida UTC: <?= miner_h($m['at']??'Sin datos') ?></span>
</div>
<div class="monitor-node-action">
<?php if($cooldown>0): ?><span class="muted">Reasignación solicitada · esperar <?= (int)ceil($cooldown/60) ?> min</span>
<?php elseif($g['recent'] && $g['state']==='running' && $node['ready'] && $node['state']==='running'): ?>
<a class="reallocate-link" href="/?page=reallocate&amp;group_id=<?= (int)$g['id'] ?>&amp;instance_id=<?= rawurlencode((string)$node['id']) ?>">Buscar otro nodo</a>
<?php else: ?><span class="muted">Reasignación no disponible</span><?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<details class="monitor-group">
<summary>Ver detalles técnicos del grupo <small><?= (int)$g['warnings'] ?> advertencias en 15 min</small></summary>
<p class="muted">Estado del grupo: <?= miner_h($g['state']) ?> · prioridad: <?= miner_h($g['priority']??'desconocida') ?> · última lectura UTC: <?= miner_h($g['last_seen_at']) ?></p>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Instancia</th><th>Estado</th><th>Lista</th><th>Última observación UTC</th></tr></thead><tbody>
<?php foreach($g['instances'] as $node): ?><tr>
<td data-label="Instancia"><code><?= miner_h($node['id']) ?></code></td>
<td data-label="Estado"><?= miner_h($node['state']) ?></td>
<td data-label="Lista"><?= $node['ready']?'Sí':'No' ?></td>
<td data-label="Observado"><?= miner_h($node['observed_at']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></details>
</article>
<?php endforeach;endforeach; ?>
</section>
<?php elseif($page==='finance'): ?>
<section class="card">
<h2>Contabilidad de minería · USD y PRL</h2>
<p class="muted">El flujo de caja registrado es diferente al costo de GPU consumido. Las recargas de Salad son anticipos de crédito; sus cargos por uso se registran aparte y <strong>no se restan otra vez</strong> al flujo de caja. Los saldos de Kryptex y transferencias PRL no son dólares cobrados.</p>
<?php if($ledgerSaved!==null): ?><p class="success"><?= miner_h($ledgerSaved) ?></p><?php endif; ?>
<?php if(!$ledgerData['ready']): ?>
<p class="error">El libro contable todavía no está habilitado: falta aplicar la migración privada 004 desde el servidor como administrador root. El monitor y los datos existentes siguen funcionando.</p>
<?php else: ?>
<div class="kpis">
<div><strong><?= $ledgerData['registered_cash_in']===null?'Sin registros':'$'.miner_h(number_format((float)$ledgerData['registered_cash_in'],2)) ?></strong><small>Entradas registradas USD equivalente</small></div>
<div><strong><?= $ledgerData['registered_cash_out']===null?'Sin registros':'$'.miner_h(number_format((float)$ledgerData['registered_cash_out'],2)) ?></strong><small>Salidas registradas USD equivalente</small></div>
<div><strong><?= $ledgerData['registered_cash_net']===null?'Desconocido':'$'.miner_h(number_format((float)$ledgerData['registered_cash_net'],2)) ?></strong><small>Flujo neto registrado (no utilidad)</small></div>
<div><strong><?= $ledgerData['prl_sold']===null?'Sin registros':miner_h(number_format((float)$ledgerData['prl_sold'],6)) ?></strong><small>PRL vendidos con comprobante</small></div>
</div>
<?php endif; ?>
<section class="accounting-panel">
<h3>Salad · facturación oficial acumulada</h3>
<p class="muted">Datos de la pestaña Credits. El consumo es crédito usado hasta la fecha de cada captura, no un cargo adicional ni una factura nueva. Los créditos emitidos pueden incluir promociones y no representan necesariamente dinero pagado.</p>
<?php if($creditSaved!==null): ?><p class="success"><?= miner_h($creditSaved) ?></p><?php endif; ?>
<?php if(!$creditData['ready']): ?><p class="muted">Falta activar el historial de capturas de facturación en MariaDB (migración 005). No se alteran los movimientos ni el monitoreo.</p>
<?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Organización</th><th>Captura</th><th>Emitidos USD</th><th>Consumidos USD</th><th>Disponibles USD</th><th>Vencidos USD</th></tr></thead><tbody>
<?php foreach($creditData['items'] as $item): ?>
<tr>
<td data-label="Organización"><?= miner_h(strtoupper($item['organization'])) ?></td>
<td data-label="Fecha de corte"><?= miner_h($item['snapshot_date']??'Sin registrar') ?></td>
<td data-label="Emitidos"><?= miner_h($item['issued_usd']??'Desconocido') ?></td>
<td data-label="Consumidos"><?= miner_h($item['consumed_usd']??'Desconocido') ?></td>
<td data-label="Disponibles"><?= miner_h($item['available_usd']??'Desconocido') ?></td>
<td data-label="Vencidos"><?= miner_h($item['expired_usd']??'Desconocido') ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if($creditData['totals']!==null): ?>
<div class="kpis">
<div><strong>$<?= miner_h($creditData['totals']['issued_usd']) ?></strong><small>Créditos totales emitidos</small></div>
<div><strong>$<?= miner_h($creditData['totals']['consumed_usd']) ?></strong><small>Consumo confirmado acumulado</small></div>
<div><strong>$<?= miner_h($creditData['totals']['available_usd']) ?></strong><small>Crédito disponible al corte</small></div>
</div>
<?php else: ?><p class="muted">Totales globales incompletos: falta alguna organización.</p><?php endif; ?>
<details><summary>Registrar nueva captura de créditos de Salad</summary>
<p class="muted">Introduce las cifras del mismo día de corte y una referencia única a la captura o estado de cuenta. Se conservarán las fotografías anteriores; nunca sustituyen los pagos realizados.</p>
<form method="post" action="/?page=finance">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="accounting-input-grid">
<label>Organización<select name="snapshot_org" required>
<?php foreach(array_unique(array_column($targets,'organization_slug')) as $org): ?><option value="<?= miner_h($org) ?>"><?= miner_h(strtoupper($org)) ?></option><?php endforeach; ?></select></label>
<label>Fecha de corte<input name="snapshot_date" required value="<?= gmdate('Y-m-d') ?>" maxlength="10"></label>
<label>Créditos emitidos USD<input name="issued_usd" required inputmode="decimal" placeholder="70.00"></label>
<label>Créditos consumidos USD<input name="consumed_usd" required inputmode="decimal" placeholder="62.23"></label>
<label>Créditos disponibles USD<input name="available_usd" required inputmode="decimal" placeholder="7.77"></label>
<label>Créditos vencidos USD<input name="expired_usd" required inputmode="decimal" value="0.00"></label>
<label>Referencia única de comprobante<input name="snapshot_reference" required maxlength="160" placeholder="salad-credits-YYYY-MM-DD-hache"></label>
</div><button name="action" value="credit_snapshot">Guardar fotografía acumulada</button>
</form></details>
<?php endif; ?>
</section>
<div class="accounting-grid">
<div class="accounting-panel">
<h3>Kryptex · existencias observadas</h3>
<p><strong><?= $ledgerPool['all_balances_fresh']?miner_h($ledgerPool['confirmed_prl']).' PRL':'Lectura incompleta' ?></strong><small> Confirmados en el pool (no vendidos)</small></p>
<p><strong><?= $ledgerPool['all_balances_fresh']?miner_h($ledgerPool['pending_prl']).' PRL':'Sin datos completos' ?></strong><small> Pendientes de confirmar</small></p>
<p class="muted">Una billetera compartida se cuenta una sola vez. Estas fotografías de saldo no son ingresos por periodo.</p>
</div>
<div class="accounting-panel">
<h3>Salad · consumo facturado</h3>
<p class="muted">Los cargos de uso de GPU se concilian con referencias de factura en Configuración. Nunca se deducen también las recargas del costo de producción.</p>
<?php foreach($financeData as $fin): ?>
<p><strong><?= miner_h(strtoupper($fin['org'])) ?>:</strong> <?= miner_h($fin['charges_usd']??'Sin conciliar') ?> <?= $fin['charges_usd']!==null?'USD facturados':'· facturas pendientes' ?>
<small> · estimación parcial 24 h: <?= miner_h($fin['estimated_24h_usd']??'sin cobertura suficiente') ?> USD</small></p>
<?php endforeach; ?>
</div>
</div>
<?php if($ledgerData['ready']): ?>
<section class="accounting-panel">
<h3>Registrar movimiento con comprobante</h3>
<p class="muted">No introduzcas saldo pendiente como venta. Para una venta PRL introduce PRL vendidos, USD/USDT bruto recibido y comisión: el sistema calcula la entrada neta. Para una recarga de Salad registra solo el dinero que salió realmente, no el consumo de GPU. Fechas UTC.</p>
<form method="post" action="/?page=finance">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="accounting-input-grid">
<label>Tipo de movimiento
<select name="movement_type" required>
<?php foreach(miner_accounting_types() as $type=>$name): ?>
<option value="<?= miner_h($type) ?>"><?= miner_h($name) ?></option>
<?php endforeach; ?>
</select></label>
<label>Organización (obligatoria para pagos Salad y otros cobros/gastos)
<select name="movement_org">
<option value="">Compartido / sin atribución</option>
<?php foreach(array_unique(array_column($targets,'organization_slug')) as $org): ?>
<option value="<?= miner_h($org) ?>"><?= miner_h(strtoupper($org)) ?></option>
<?php endforeach; ?>
</select></label>
<label>Fecha y hora UTC (AAAA-MM-DD HH:MM:SS)
<input required name="movement_date" value="<?= gmdate('Y-m-d H:i:s') ?>" maxlength="19"></label>
<label>USD bruto o USD equivalente (solo movimientos monetarios)
<input name="movement_usd" inputmode="decimal" placeholder="Ej. 10.00000000" maxlength="22"></label>
<label>PRL transferidos, pagados o vendidos (solo movimientos PRL)
<input name="movement_prl" inputmode="decimal" placeholder="Ej. 50.25000000" maxlength="25"></label>
<label>Comisión de venta en USD (solo venta PRL)
<input name="movement_fee" inputmode="decimal" placeholder="Ej. 0.10000000" maxlength="22"></label>
<label>Referencia única del comprobante
<input required name="movement_reference" maxlength="180" placeholder="ID de recibo, TXID, operación exchange"></label>
<label>Nota opcional
<input name="movement_memo" maxlength="300" placeholder="Ej. pago de crédito Salad con USDC"></label>
</div>
<button name="action" value="accounting_event">Registrar movimiento comprobado</button>
</form>
</section>
<section class="accounting-panel">
<h3>Últimos movimientos registrados</h3>
<?php if(!$ledgerData['entries']): ?><p class="muted">Aún no hay movimientos. No se mostrará flujo de caja cero como si todos los registros estuvieran completos.</p><?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>UTC</th><th>Movimiento</th><th>Organización</th><th>USD bruto</th><th>Comisión USD</th><th>PRL</th><th>Referencia</th></tr></thead><tbody>
<?php foreach($ledgerData['entries'] as $e): ?>
<tr><td data-label="UTC"><?= miner_h($e['occurred_at']) ?></td>
<td data-label="Movimiento"><?= miner_h(miner_accounting_types()[$e['event_type']]??$e['event_type']) ?></td>
<td data-label="Organización"><?= miner_h($e['organization']??'Compartido') ?></td>
<td data-label="USD bruto"><?= miner_h($e['usd_amount']??'—') ?></td>
<td data-label="Comisión USD"><?= miner_h($e['usd_fee']??'—') ?></td>
<td data-label="PRL"><?= miner_h($e['prl_amount']??'—') ?></td>
<td data-label="Referencia"><?= miner_h($e['source_reference']) ?><small><?= miner_h($e['memo']) ?></small></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</section>
<?php endif; ?>
<p class="muted">**Cobertura:** solo incluye operaciones con comprobantes añadidas a este sistema. La diferencia entradas menos salidas NO equivale a utilidad neta ni a saldo disponible en Salad, SafeTrade o bancos; existen fondos PRL pendientes, inversiones prepagadas y costos todavía sin conciliar.</p>
</section>
<?php elseif($page==='diagnostics'): ?>
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
.miner_h(number_format($liveCost['usd_per_hour'],4,'.',',')).' USD/h' ?></strong>
 <small><?= (int)$liveCost['priced'] ?> de <?= (int)$liveCost['total'] ?> GPU con tarifa
 <?php if($liveCost['unpriced']>0): ?> · <?= (int)$liveCost['unpriced'] ?> sin precio: total parcial<?php endif; ?></small></summary>
<p class="muted">Tarifas configuradas por organización, clase de GPU y prioridad × instancias listas con última observación en 10 minutos. Es una <strong>estimación horaria, no consumo facturado</strong>. No se cobra artificialmente por réplicas solicitadas; las GPU en asignación o sin tarifa verificable no están incluidas.</p>
<?php if($liveCost['details']): ?>
<div class="tablewrap mobile-stack"><table>
<thead><tr><th>Organización</th><th>GPU Salad</th><th>Prioridad</th><th>Listas</th><th>USD/h unidad</th><th>USD/h subtotal</th></tr></thead><tbody>
<?php foreach($liveCost['details'] as $d): ?>
<tr><td data-label="Organización"><?= miner_h(strtoupper($d['organization'])) ?></td>
<td data-label="Clase"><?= miner_h($d['gpu_class']) ?></td>
<td data-label="Prioridad"><?= miner_h($d['priority']) ?></td>
<td data-label="GPU listas"><?= (int)$d['count'] ?></td>
<td data-label="USD/h unidad">$<?= miner_h(number_format($d['unit_usd_per_hour'],4,'.',',')) ?></td>
<td data-label="USD/h subtotal">$<?= miner_h(number_format($d['subtotal_usd_per_hour'],4,'.',',')) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php foreach($liveCost['organizations'] as $org=>$breakdown): ?>
<p class="monitor-cost-org"><?= miner_h(strtoupper($org)) ?>:
<?= $breakdown['usd_per_hour']===null?'Sin tarifas':' y recientes, sin contar dos veces la misma instancia. No incluye máquinas sin medición ni sirve para calcular beneficios; si hay algoritmos distintos, sus tasas no son económicamente comparables.</p>
<p class="muted">Actualización automática de Salad cada cinco minutos. Aquí solo aparecen grupos reportados durante las últimas ocho horas. Los eliminados o antiguos conservan su historial privado, pero no saturan el monitor.</p>
<div class="kpis">
<div><strong><?= (int)$monitorData['stats']['current_groups'] ?></strong><small>Grupos recientes</small></div>
<div><strong><?= (int)$monitorData['stats']['desired'] ?></strong><small>Réplicas solicitadas</small></div>
<div><strong><?= (int)$monitorData['stats']['observed'] ?></strong><small>Instancias observadas</small></div>
<div><strong><?= (int)$monitorData['stats']['ready'] ?></strong><small>Contenedores listos</small></div></div>
<p class="muted">En espera: <?= (int)$monitorData['stats']['waiting'] ?>. El color de rendimiento es una <strong>referencia de TH/s</strong> para minería PRL con RTX 4070 Ti SUPER Low, no una afirmación de rentabilidad neta en USD. El automático de Hache Natación permanece activo e independiente.</p>
<?php if (!$monitorData['groups']): ?><p class="muted">No hay grupos reportados durante las últimas ocho horas. Verifica la recolección o Configuración.</p><?php endif; ?>
<?php foreach($monitorData['targets'] as $t):
 $org=(string)$t['organization_slug'];$project=(string)$t['project_slug'];$count=0;
 foreach($monitorData['groups'] as $g) if($g['organization']===$org&&$g['project_name']===$project)$count++;
 if($count===0)continue;
?>
<h3 class="monitor-org-title"><?= miner_h(strtoupper($org)) ?> · <?= miner_h($project) ?> <small><?= $t['enabled']?'Lecturas habilitadas':'Pausado' ?> · <?= $count ?> grupos reportados en 8 h</small></h3>
<?php foreach($monitorData['groups'] as $g):
 if($g['organization']!==$org||$g['project_name']!==$project)continue;
?>
<article class="monitor-entry monitor-entry--<?= miner_h($g['signal']) ?>">
<div class="monitor-topline">
 <div><h4><?= miner_h($g['group_name']) ?></h4><span class="monitor-state"><?= miner_h(miner_monitor_status_label($g['classification'])) ?></span></div>
 <div class="monitor-group-total"><strong><?= $g['total_ths']!==null ? miner_h(number_format($g['total_ths'],2,'.','')).' TH/s':'Sin total verificable' ?></strong>
 <small><?= (int)$g['ready'] ?> listas / <?= (int)$g['desired_replicas'] ?> solicitadas</small></div>
</div>
<?php if(!$g['recent']): ?><p class="muted">Grupo reportado en las últimas ocho horas, pero su última consulta no es reciente. Las mediciones no se presentan como actuales.</p><?php endif; ?>
<?php if($g['group_log_observation']!==null && $g['total_ths']===null): ?>
<div class="monitor-group-evidence" role="note">
 <strong>Último registro de GPU del grupo (no es el total): <?= miner_h(number_format($g['group_log_observation']['hashrate_ths'],2,'.',',')) ?> TH/s</strong>
 <span><?= miner_h($g['group_log_observation']['gpu']) ?> · UTC <?= miner_h($g['group_log_observation']['logged_at']) ?> · <?= (int)$g['group_log_observation']['samples'] ?> registros de GPU recientes</span>
 <small>Salad devolvió lecturas del grupo, pero no hay datos suficientes para atribuirlas a estas réplicas. No se reparten entre las instancias ni se suman al total general.</small>
</div>
<?php endif; ?>
<?php if(!$g['instances']): ?><p class="muted">Sin instancias observadas en los últimos 15 minutos. Puede seguir en asignación o detenido.</p><?php endif; ?>
<div class="monitor-node-grid">
<?php foreach($g['instances'] as $node):
 $signal=$node['signal'];$m=$node['metric'];
 $fingerprint=miner_reallocation_fingerprint($g,(string)$node['id']);
 $cooldown=$manualCooldown[$fingerprint]??0;
?>
<div class="monitor-node monitor-node--<?= miner_h($signal['level']) ?>">
<div class="monitor-node-heading">
 <span>Instancia <code title="<?= miner_h($node['id']) ?>">…<?= miner_h(substr((string)$node['id'],-10)) ?></code></span>
 <span class="monitor-signal"><?= miner_h($signal['label']) ?></span>
</div>
<div class="monitor-node-rate">
 <strong><?= $m!==null?miner_h(number_format($m['hashrate_ths'],2,'.','')):'—' ?></strong>
 <span>TH/s</span>
</div>
<?php if($signal['floor']!==null): ?>
<p class="monitor-margin">Umbral inferior orientativo: <?= miner_h(number_format($signal['floor'],0)) ?> TH/s · Margen: <strong><?= $signal['margin']>0?'+':'' ?><?= miner_h(number_format($signal['margin'],2,'.','')) ?> TH/s</strong></p>
<?php elseif($m===null): ?><p class="monitor-margin">Sin medición individual reciente. Contenedor listo no significa shares aceptados.</p>
<?php else: ?><p class="monitor-margin">Sin umbral de rentabilidad validado para esta GPU o moneda.</p><?php endif; ?>
<div class="monitor-trend">
<span class="monitor-chart-label">Últimas mediciones · <?= count($node['history']) ?> muestras</span>
<?php if($node['chart']!==null): ?>
<svg viewBox="0 0 360 90" preserveAspectRatio="none" role="img" aria-label="Tendencia histórica de hashrate de esta instancia">
<line x1="6" y1="82" x2="354" y2="82" stroke="currentColor" stroke-opacity=".2"/>
<polyline points="<?= miner_h($node['chart']) ?>" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
</svg>
<?php else: ?><span class="muted">Esperando al menos dos muestras verificadas</span><?php endif; ?>
<?php if($node['avg_recent']!==null): ?><small>Promedio de últimas muestras: <?= miner_h(number_format($node['avg_recent'],2,'.','')) ?> TH/s</small><?php endif; ?>
</div>
<div class="monitor-node-facts">
<span>GPU: <?= miner_h($m['gpu_model']??$g['gpu_class']??'No identificada') ?></span>
<span>Potencia: <?= $m!==null && $m['watts']!==null?miner_h(number_format($m['watts'],0)).' W':'Sin datos' ?></span>
<span>Estado: <?= miner_h($node['state']) ?></span>
<span>Última medida UTC: <?= miner_h($m['at']??'Sin datos') ?></span>
</div>
<div class="monitor-node-action">
<?php if($cooldown>0): ?><span class="muted">Reasignación solicitada · esperar <?= (int)ceil($cooldown/60) ?> min</span>
<?php elseif($g['recent'] && $g['state']==='running' && $node['ready'] && $node['state']==='running'): ?>
<a class="reallocate-link" href="/?page=reallocate&amp;group_id=<?= (int)$g['id'] ?>&amp;instance_id=<?= rawurlencode((string)$node['id']) ?>">Buscar otro nodo</a>
<?php else: ?><span class="muted">Reasignación no disponible</span><?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<details class="monitor-group">
<summary>Ver detalles técnicos del grupo <small><?= (int)$g['warnings'] ?> advertencias en 15 min</small></summary>
<p class="muted">Estado del grupo: <?= miner_h($g['state']) ?> · prioridad: <?= miner_h($g['priority']??'desconocida') ?> · última lectura UTC: <?= miner_h($g['last_seen_at']) ?></p>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Instancia</th><th>Estado</th><th>Lista</th><th>Última observación UTC</th></tr></thead><tbody>
<?php foreach($g['instances'] as $node): ?><tr>
<td data-label="Instancia"><code><?= miner_h($node['id']) ?></code></td>
<td data-label="Estado"><?= miner_h($node['state']) ?></td>
<td data-label="Lista"><?= $node['ready']?'Sí':'No' ?></td>
<td data-label="Observado"><?= miner_h($node['observed_at']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></details>
</article>
<?php endforeach;endforeach; ?>
</section>
<?php elseif($page==='finance'): ?>
<section class="card">
<h2>Contabilidad de minería · USD y PRL</h2>
<p class="muted">El flujo de caja registrado es diferente al costo de GPU consumido. Las recargas de Salad son anticipos de crédito; sus cargos por uso se registran aparte y <strong>no se restan otra vez</strong> al flujo de caja. Los saldos de Kryptex y transferencias PRL no son dólares cobrados.</p>
<?php if($ledgerSaved!==null): ?><p class="success"><?= miner_h($ledgerSaved) ?></p><?php endif; ?>
<?php if(!$ledgerData['ready']): ?>
<p class="error">El libro contable todavía no está habilitado: falta aplicar la migración privada 004 desde el servidor como administrador root. El monitor y los datos existentes siguen funcionando.</p>
<?php else: ?>
<div class="kpis">
<div><strong><?= $ledgerData['registered_cash_in']===null?'Sin registros':'$'.miner_h(number_format((float)$ledgerData['registered_cash_in'],2)) ?></strong><small>Entradas registradas USD equivalente</small></div>
<div><strong><?= $ledgerData['registered_cash_out']===null?'Sin registros':'$'.miner_h(number_format((float)$ledgerData['registered_cash_out'],2)) ?></strong><small>Salidas registradas USD equivalente</small></div>
<div><strong><?= $ledgerData['registered_cash_net']===null?'Desconocido':'$'.miner_h(number_format((float)$ledgerData['registered_cash_net'],2)) ?></strong><small>Flujo neto registrado (no utilidad)</small></div>
<div><strong><?= $ledgerData['prl_sold']===null?'Sin registros':miner_h(number_format((float)$ledgerData['prl_sold'],6)) ?></strong><small>PRL vendidos con comprobante</small></div>
</div>
<?php endif; ?>
<section class="accounting-panel">
<h3>Salad · facturación oficial acumulada</h3>
<p class="muted">Datos de la pestaña Credits. El consumo es crédito usado hasta la fecha de cada captura, no un cargo adicional ni una factura nueva. Los créditos emitidos pueden incluir promociones y no representan necesariamente dinero pagado.</p>
<?php if($creditSaved!==null): ?><p class="success"><?= miner_h($creditSaved) ?></p><?php endif; ?>
<?php if(!$creditData['ready']): ?><p class="muted">Falta activar el historial de capturas de facturación en MariaDB (migración 005). No se alteran los movimientos ni el monitoreo.</p>
<?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Organización</th><th>Captura</th><th>Emitidos USD</th><th>Consumidos USD</th><th>Disponibles USD</th><th>Vencidos USD</th></tr></thead><tbody>
<?php foreach($creditData['items'] as $item): ?>
<tr>
<td data-label="Organización"><?= miner_h(strtoupper($item['organization'])) ?></td>
<td data-label="Fecha de corte"><?= miner_h($item['snapshot_date']??'Sin registrar') ?></td>
<td data-label="Emitidos"><?= miner_h($item['issued_usd']??'Desconocido') ?></td>
<td data-label="Consumidos"><?= miner_h($item['consumed_usd']??'Desconocido') ?></td>
<td data-label="Disponibles"><?= miner_h($item['available_usd']??'Desconocido') ?></td>
<td data-label="Vencidos"><?= miner_h($item['expired_usd']??'Desconocido') ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if($creditData['totals']!==null): ?>
<div class="kpis">
<div><strong>$<?= miner_h($creditData['totals']['issued_usd']) ?></strong><small>Créditos totales emitidos</small></div>
<div><strong>$<?= miner_h($creditData['totals']['consumed_usd']) ?></strong><small>Consumo confirmado acumulado</small></div>
<div><strong>$<?= miner_h($creditData['totals']['available_usd']) ?></strong><small>Crédito disponible al corte</small></div>
</div>
<?php else: ?><p class="muted">Totales globales incompletos: falta alguna organización.</p><?php endif; ?>
<details><summary>Registrar nueva captura de créditos de Salad</summary>
<p class="muted">Introduce las cifras del mismo día de corte y una referencia única a la captura o estado de cuenta. Se conservarán las fotografías anteriores; nunca sustituyen los pagos realizados.</p>
<form method="post" action="/?page=finance">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="accounting-input-grid">
<label>Organización<select name="snapshot_org" required>
<?php foreach(array_unique(array_column($targets,'organization_slug')) as $org): ?><option value="<?= miner_h($org) ?>"><?= miner_h(strtoupper($org)) ?></option><?php endforeach; ?></select></label>
<label>Fecha de corte<input name="snapshot_date" required value="<?= gmdate('Y-m-d') ?>" maxlength="10"></label>
<label>Créditos emitidos USD<input name="issued_usd" required inputmode="decimal" placeholder="70.00"></label>
<label>Créditos consumidos USD<input name="consumed_usd" required inputmode="decimal" placeholder="62.23"></label>
<label>Créditos disponibles USD<input name="available_usd" required inputmode="decimal" placeholder="7.77"></label>
<label>Créditos vencidos USD<input name="expired_usd" required inputmode="decimal" value="0.00"></label>
<label>Referencia única de comprobante<input name="snapshot_reference" required maxlength="160" placeholder="salad-credits-YYYY-MM-DD-hache"></label>
</div><button name="action" value="credit_snapshot">Guardar fotografía acumulada</button>
</form></details>
<?php endif; ?>
</section>
<div class="accounting-grid">
<div class="accounting-panel">
<h3>Kryptex · existencias observadas</h3>
<p><strong><?= $ledgerPool['all_balances_fresh']?miner_h($ledgerPool['confirmed_prl']).' PRL':'Lectura incompleta' ?></strong><small> Confirmados en el pool (no vendidos)</small></p>
<p><strong><?= $ledgerPool['all_balances_fresh']?miner_h($ledgerPool['pending_prl']).' PRL':'Sin datos completos' ?></strong><small> Pendientes de confirmar</small></p>
<p class="muted">Una billetera compartida se cuenta una sola vez. Estas fotografías de saldo no son ingresos por periodo.</p>
</div>
<div class="accounting-panel">
<h3>Salad · consumo facturado</h3>
<p class="muted">Los cargos de uso de GPU se concilian con referencias de factura en Configuración. Nunca se deducen también las recargas del costo de producción.</p>
<?php foreach($financeData as $fin): ?>
<p><strong><?= miner_h(strtoupper($fin['org'])) ?>:</strong> <?= miner_h($fin['charges_usd']??'Sin conciliar') ?> <?= $fin['charges_usd']!==null?'USD facturados':'· facturas pendientes' ?>
<small> · estimación parcial 24 h: <?= miner_h($fin['estimated_24h_usd']??'sin cobertura suficiente') ?> USD</small></p>
<?php endforeach; ?>
</div>
</div>
<?php if($ledgerData['ready']): ?>
<section class="accounting-panel">
<h3>Registrar movimiento con comprobante</h3>
<p class="muted">No introduzcas saldo pendiente como venta. Para una venta PRL introduce PRL vendidos, USD/USDT bruto recibido y comisión: el sistema calcula la entrada neta. Para una recarga de Salad registra solo el dinero que salió realmente, no el consumo de GPU. Fechas UTC.</p>
<form method="post" action="/?page=finance">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="accounting-input-grid">
<label>Tipo de movimiento
<select name="movement_type" required>
<?php foreach(miner_accounting_types() as $type=>$name): ?>
<option value="<?= miner_h($type) ?>"><?= miner_h($name) ?></option>
<?php endforeach; ?>
</select></label>
<label>Organización (obligatoria para pagos Salad y otros cobros/gastos)
<select name="movement_org">
<option value="">Compartido / sin atribución</option>
<?php foreach(array_unique(array_column($targets,'organization_slug')) as $org): ?>
<option value="<?= miner_h($org) ?>"><?= miner_h(strtoupper($org)) ?></option>
<?php endforeach; ?>
</select></label>
<label>Fecha y hora UTC (AAAA-MM-DD HH:MM:SS)
<input required name="movement_date" value="<?= gmdate('Y-m-d H:i:s') ?>" maxlength="19"></label>
<label>USD bruto o USD equivalente (solo movimientos monetarios)
<input name="movement_usd" inputmode="decimal" placeholder="Ej. 10.00000000" maxlength="22"></label>
<label>PRL transferidos, pagados o vendidos (solo movimientos PRL)
<input name="movement_prl" inputmode="decimal" placeholder="Ej. 50.25000000" maxlength="25"></label>
<label>Comisión de venta en USD (solo venta PRL)
<input name="movement_fee" inputmode="decimal" placeholder="Ej. 0.10000000" maxlength="22"></label>
<label>Referencia única del comprobante
<input required name="movement_reference" maxlength="180" placeholder="ID de recibo, TXID, operación exchange"></label>
<label>Nota opcional
<input name="movement_memo" maxlength="300" placeholder="Ej. pago de crédito Salad con USDC"></label>
</div>
<button name="action" value="accounting_event">Registrar movimiento comprobado</button>
</form>
</section>
<section class="accounting-panel">
<h3>Últimos movimientos registrados</h3>
<?php if(!$ledgerData['entries']): ?><p class="muted">Aún no hay movimientos. No se mostrará flujo de caja cero como si todos los registros estuvieran completos.</p><?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>UTC</th><th>Movimiento</th><th>Organización</th><th>USD bruto</th><th>Comisión USD</th><th>PRL</th><th>Referencia</th></tr></thead><tbody>
<?php foreach($ledgerData['entries'] as $e): ?>
<tr><td data-label="UTC"><?= miner_h($e['occurred_at']) ?></td>
<td data-label="Movimiento"><?= miner_h(miner_accounting_types()[$e['event_type']]??$e['event_type']) ?></td>
<td data-label="Organización"><?= miner_h($e['organization']??'Compartido') ?></td>
<td data-label="USD bruto"><?= miner_h($e['usd_amount']??'—') ?></td>
<td data-label="Comisión USD"><?= miner_h($e['usd_fee']??'—') ?></td>
<td data-label="PRL"><?= miner_h($e['prl_amount']??'—') ?></td>
<td data-label="Referencia"><?= miner_h($e['source_reference']) ?><small><?= miner_h($e['memo']) ?></small></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</section>
<?php endif; ?>
<p class="muted">**Cobertura:** solo incluye operaciones con comprobantes añadidas a este sistema. La diferencia entradas menos salidas NO equivale a utilidad neta ni a saldo disponible en Salad, SafeTrade o bancos; existen fondos PRL pendientes, inversiones prepagadas y costos todavía sin conciliar.</p>
</section>
<?php elseif($page==='diagnostics'): ?>
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
.miner_h(number_format($breakdown['usd_per_hour'],4,'.',',')).' USD/h' ?>
· <?= (int)$breakdown['priced'] ?>/<?= (int)($breakdown['priced']+$breakdown['unpriced']) ?> GPU con precio
<?php if($breakdown['unpriced']>0): ?> · parcial<?php endif; ?></p>
<?php endforeach; ?>
<?php if($liveCost['unpriced']>0): ?><p class="muted">Para completar los costos, introduce las tarifas reales de las GPU faltantes en <a href="/?page=settings">Configuración → Tarifas de GPU</a>. No se estiman precios desconocidos.</p><?php endif; ?>
</details>
<p class="muted">Este total suma únicamente TH/s individuales verificados y recientes, sin contar dos veces la misma instancia. No incluye máquinas sin medición ni sirve para calcular beneficios; si hay algoritmos distintos, sus tasas no son económicamente comparables.</p>
<p class="muted">Actualización automática de Salad cada cinco minutos. Aquí solo aparecen grupos reportados durante las últimas ocho horas. Los eliminados o antiguos conservan su historial privado, pero no saturan el monitor.</p>
<div class="kpis">
<div><strong><?= (int)$monitorData['stats']['current_groups'] ?></strong><small>Grupos recientes</small></div>
<div><strong><?= (int)$monitorData['stats']['desired'] ?></strong><small>Réplicas solicitadas</small></div>
<div><strong><?= (int)$monitorData['stats']['observed'] ?></strong><small>Instancias observadas</small></div>
<div><strong><?= (int)$monitorData['stats']['ready'] ?></strong><small>Contenedores listos</small></div></div>
<p class="muted">En espera: <?= (int)$monitorData['stats']['waiting'] ?>. El color de rendimiento es una <strong>referencia de TH/s</strong> para minería PRL con RTX 4070 Ti SUPER Low, no una afirmación de rentabilidad neta en USD. El automático de Hache Natación permanece activo e independiente.</p>
<?php if (!$monitorData['groups']): ?><p class="muted">No hay grupos reportados durante las últimas ocho horas. Verifica la recolección o Configuración.</p><?php endif; ?>
<?php foreach($monitorData['targets'] as $t):
 $org=(string)$t['organization_slug'];$project=(string)$t['project_slug'];$count=0;
 foreach($monitorData['groups'] as $g) if($g['organization']===$org&&$g['project_name']===$project)$count++;
 if($count===0)continue;
?>
<h3 class="monitor-org-title"><?= miner_h(strtoupper($org)) ?> · <?= miner_h($project) ?> <small><?= $t['enabled']?'Lecturas habilitadas':'Pausado' ?> · <?= $count ?> grupos reportados en 8 h</small></h3>
<?php foreach($monitorData['groups'] as $g):
 if($g['organization']!==$org||$g['project_name']!==$project)continue;
?>
<article class="monitor-entry monitor-entry--<?= miner_h($g['signal']) ?>">
<div class="monitor-topline">
 <div><h4><?= miner_h($g['group_name']) ?></h4><span class="monitor-state"><?= miner_h(miner_monitor_status_label($g['classification'])) ?></span></div>
 <div class="monitor-group-total"><strong><?= $g['total_ths']!==null ? miner_h(number_format($g['total_ths'],2,'.','')).' TH/s':'Sin total verificable' ?></strong>
 <small><?= (int)$g['ready'] ?> listas / <?= (int)$g['desired_replicas'] ?> solicitadas</small></div>
</div>
<?php if(!$g['recent']): ?><p class="muted">Grupo reportado en las últimas ocho horas, pero su última consulta no es reciente. Las mediciones no se presentan como actuales.</p><?php endif; ?>
<?php if($g['group_log_observation']!==null && $g['total_ths']===null): ?>
<div class="monitor-group-evidence" role="note">
 <strong>Último registro de GPU del grupo (no es el total): <?= miner_h(number_format($g['group_log_observation']['hashrate_ths'],2,'.',',')) ?> TH/s</strong>
 <span><?= miner_h($g['group_log_observation']['gpu']) ?> · UTC <?= miner_h($g['group_log_observation']['logged_at']) ?> · <?= (int)$g['group_log_observation']['samples'] ?> registros de GPU recientes</span>
 <small>Salad devolvió lecturas del grupo, pero no hay datos suficientes para atribuirlas a estas réplicas. No se reparten entre las instancias ni se suman al total general.</small>
</div>
<?php endif; ?>
<?php if(!$g['instances']): ?><p class="muted">Sin instancias observadas en los últimos 15 minutos. Puede seguir en asignación o detenido.</p><?php endif; ?>
<div class="monitor-node-grid">
<?php foreach($g['instances'] as $node):
 $signal=$node['signal'];$m=$node['metric'];
 $fingerprint=miner_reallocation_fingerprint($g,(string)$node['id']);
 $cooldown=$manualCooldown[$fingerprint]??0;
?>
<div class="monitor-node monitor-node--<?= miner_h($signal['level']) ?>">
<div class="monitor-node-heading">
 <span>Instancia <code title="<?= miner_h($node['id']) ?>">…<?= miner_h(substr((string)$node['id'],-10)) ?></code></span>
 <span class="monitor-signal"><?= miner_h($signal['label']) ?></span>
</div>
<div class="monitor-node-rate">
 <strong><?= $m!==null?miner_h(number_format($m['hashrate_ths'],2,'.','')):'—' ?></strong>
 <span>TH/s</span>
</div>
<?php if($signal['floor']!==null): ?>
<p class="monitor-margin">Umbral inferior orientativo: <?= miner_h(number_format($signal['floor'],0)) ?> TH/s · Margen: <strong><?= $signal['margin']>0?'+':'' ?><?= miner_h(number_format($signal['margin'],2,'.','')) ?> TH/s</strong></p>
<?php elseif($m===null): ?><p class="monitor-margin">Sin medición individual reciente. Contenedor listo no significa shares aceptados.</p>
<?php else: ?><p class="monitor-margin">Sin umbral de rentabilidad validado para esta GPU o moneda.</p><?php endif; ?>
<div class="monitor-trend">
<span class="monitor-chart-label">Últimas mediciones · <?= count($node['history']) ?> muestras</span>
<?php if($node['chart']!==null): ?>
<svg viewBox="0 0 360 90" preserveAspectRatio="none" role="img" aria-label="Tendencia histórica de hashrate de esta instancia">
<line x1="6" y1="82" x2="354" y2="82" stroke="currentColor" stroke-opacity=".2"/>
<polyline points="<?= miner_h($node['chart']) ?>" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
</svg>
<?php else: ?><span class="muted">Esperando al menos dos muestras verificadas</span><?php endif; ?>
<?php if($node['avg_recent']!==null): ?><small>Promedio de últimas muestras: <?= miner_h(number_format($node['avg_recent'],2,'.','')) ?> TH/s</small><?php endif; ?>
</div>
<div class="monitor-node-facts">
<span>GPU: <?= miner_h($m['gpu_model']??$g['gpu_class']??'No identificada') ?></span>
<span>Potencia: <?= $m!==null && $m['watts']!==null?miner_h(number_format($m['watts'],0)).' W':'Sin datos' ?></span>
<span>Estado: <?= miner_h($node['state']) ?></span>
<span>Última medida UTC: <?= miner_h($m['at']??'Sin datos') ?></span>
</div>
<div class="monitor-node-action">
<?php if($cooldown>0): ?><span class="muted">Reasignación solicitada · esperar <?= (int)ceil($cooldown/60) ?> min</span>
<?php elseif($g['recent'] && $g['state']==='running' && $node['ready'] && $node['state']==='running'): ?>
<a class="reallocate-link" href="/?page=reallocate&amp;group_id=<?= (int)$g['id'] ?>&amp;instance_id=<?= rawurlencode((string)$node['id']) ?>">Buscar otro nodo</a>
<?php else: ?><span class="muted">Reasignación no disponible</span><?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<details class="monitor-group">
<summary>Ver detalles técnicos del grupo <small><?= (int)$g['warnings'] ?> advertencias en 15 min</small></summary>
<p class="muted">Estado del grupo: <?= miner_h($g['state']) ?> · prioridad: <?= miner_h($g['priority']??'desconocida') ?> · última lectura UTC: <?= miner_h($g['last_seen_at']) ?></p>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Instancia</th><th>Estado</th><th>Lista</th><th>Última observación UTC</th></tr></thead><tbody>
<?php foreach($g['instances'] as $node): ?><tr>
<td data-label="Instancia"><code><?= miner_h($node['id']) ?></code></td>
<td data-label="Estado"><?= miner_h($node['state']) ?></td>
<td data-label="Lista"><?= $node['ready']?'Sí':'No' ?></td>
<td data-label="Observado"><?= miner_h($node['observed_at']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></details>
</article>
<?php endforeach;endforeach; ?>
</section>
<?php elseif($page==='finance'): ?>
<section class="card">
<h2>Contabilidad de minería · USD y PRL</h2>
<p class="muted">El flujo de caja registrado es diferente al costo de GPU consumido. Las recargas de Salad son anticipos de crédito; sus cargos por uso se registran aparte y <strong>no se restan otra vez</strong> al flujo de caja. Los saldos de Kryptex y transferencias PRL no son dólares cobrados.</p>
<?php if($ledgerSaved!==null): ?><p class="success"><?= miner_h($ledgerSaved) ?></p><?php endif; ?>
<?php if(!$ledgerData['ready']): ?>
<p class="error">El libro contable todavía no está habilitado: falta aplicar la migración privada 004 desde el servidor como administrador root. El monitor y los datos existentes siguen funcionando.</p>
<?php else: ?>
<div class="kpis">
<div><strong><?= $ledgerData['registered_cash_in']===null?'Sin registros':'$'.miner_h(number_format((float)$ledgerData['registered_cash_in'],2)) ?></strong><small>Entradas registradas USD equivalente</small></div>
<div><strong><?= $ledgerData['registered_cash_out']===null?'Sin registros':'$'.miner_h(number_format((float)$ledgerData['registered_cash_out'],2)) ?></strong><small>Salidas registradas USD equivalente</small></div>
<div><strong><?= $ledgerData['registered_cash_net']===null?'Desconocido':'$'.miner_h(number_format((float)$ledgerData['registered_cash_net'],2)) ?></strong><small>Flujo neto registrado (no utilidad)</small></div>
<div><strong><?= $ledgerData['prl_sold']===null?'Sin registros':miner_h(number_format((float)$ledgerData['prl_sold'],6)) ?></strong><small>PRL vendidos con comprobante</small></div>
</div>
<?php endif; ?>
<section class="accounting-panel">
<h3>Salad · facturación oficial acumulada</h3>
<p class="muted">Datos de la pestaña Credits. El consumo es crédito usado hasta la fecha de cada captura, no un cargo adicional ni una factura nueva. Los créditos emitidos pueden incluir promociones y no representan necesariamente dinero pagado.</p>
<?php if($creditSaved!==null): ?><p class="success"><?= miner_h($creditSaved) ?></p><?php endif; ?>
<?php if(!$creditData['ready']): ?><p class="muted">Falta activar el historial de capturas de facturación en MariaDB (migración 005). No se alteran los movimientos ni el monitoreo.</p>
<?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>Organización</th><th>Captura</th><th>Emitidos USD</th><th>Consumidos USD</th><th>Disponibles USD</th><th>Vencidos USD</th></tr></thead><tbody>
<?php foreach($creditData['items'] as $item): ?>
<tr>
<td data-label="Organización"><?= miner_h(strtoupper($item['organization'])) ?></td>
<td data-label="Fecha de corte"><?= miner_h($item['snapshot_date']??'Sin registrar') ?></td>
<td data-label="Emitidos"><?= miner_h($item['issued_usd']??'Desconocido') ?></td>
<td data-label="Consumidos"><?= miner_h($item['consumed_usd']??'Desconocido') ?></td>
<td data-label="Disponibles"><?= miner_h($item['available_usd']??'Desconocido') ?></td>
<td data-label="Vencidos"><?= miner_h($item['expired_usd']??'Desconocido') ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php if($creditData['totals']!==null): ?>
<div class="kpis">
<div><strong>$<?= miner_h($creditData['totals']['issued_usd']) ?></strong><small>Créditos totales emitidos</small></div>
<div><strong>$<?= miner_h($creditData['totals']['consumed_usd']) ?></strong><small>Consumo confirmado acumulado</small></div>
<div><strong>$<?= miner_h($creditData['totals']['available_usd']) ?></strong><small>Crédito disponible al corte</small></div>
</div>
<?php else: ?><p class="muted">Totales globales incompletos: falta alguna organización.</p><?php endif; ?>
<details><summary>Registrar nueva captura de créditos de Salad</summary>
<p class="muted">Introduce las cifras del mismo día de corte y una referencia única a la captura o estado de cuenta. Se conservarán las fotografías anteriores; nunca sustituyen los pagos realizados.</p>
<form method="post" action="/?page=finance">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="accounting-input-grid">
<label>Organización<select name="snapshot_org" required>
<?php foreach(array_unique(array_column($targets,'organization_slug')) as $org): ?><option value="<?= miner_h($org) ?>"><?= miner_h(strtoupper($org)) ?></option><?php endforeach; ?></select></label>
<label>Fecha de corte<input name="snapshot_date" required value="<?= gmdate('Y-m-d') ?>" maxlength="10"></label>
<label>Créditos emitidos USD<input name="issued_usd" required inputmode="decimal" placeholder="70.00"></label>
<label>Créditos consumidos USD<input name="consumed_usd" required inputmode="decimal" placeholder="62.23"></label>
<label>Créditos disponibles USD<input name="available_usd" required inputmode="decimal" placeholder="7.77"></label>
<label>Créditos vencidos USD<input name="expired_usd" required inputmode="decimal" value="0.00"></label>
<label>Referencia única de comprobante<input name="snapshot_reference" required maxlength="160" placeholder="salad-credits-YYYY-MM-DD-hache"></label>
</div><button name="action" value="credit_snapshot">Guardar fotografía acumulada</button>
</form></details>
<?php endif; ?>
</section>
<div class="accounting-grid">
<div class="accounting-panel">
<h3>Kryptex · existencias observadas</h3>
<p><strong><?= $ledgerPool['all_balances_fresh']?miner_h($ledgerPool['confirmed_prl']).' PRL':'Lectura incompleta' ?></strong><small> Confirmados en el pool (no vendidos)</small></p>
<p><strong><?= $ledgerPool['all_balances_fresh']?miner_h($ledgerPool['pending_prl']).' PRL':'Sin datos completos' ?></strong><small> Pendientes de confirmar</small></p>
<p class="muted">Una billetera compartida se cuenta una sola vez. Estas fotografías de saldo no son ingresos por periodo.</p>
</div>
<div class="accounting-panel">
<h3>Salad · consumo facturado</h3>
<p class="muted">Los cargos de uso de GPU se concilian con referencias de factura en Configuración. Nunca se deducen también las recargas del costo de producción.</p>
<?php foreach($financeData as $fin): ?>
<p><strong><?= miner_h(strtoupper($fin['org'])) ?>:</strong> <?= miner_h($fin['charges_usd']??'Sin conciliar') ?> <?= $fin['charges_usd']!==null?'USD facturados':'· facturas pendientes' ?>
<small> · estimación parcial 24 h: <?= miner_h($fin['estimated_24h_usd']??'sin cobertura suficiente') ?> USD</small></p>
<?php endforeach; ?>
</div>
</div>
<?php if($ledgerData['ready']): ?>
<section class="accounting-panel">
<h3>Registrar movimiento con comprobante</h3>
<p class="muted">No introduzcas saldo pendiente como venta. Para una venta PRL introduce PRL vendidos, USD/USDT bruto recibido y comisión: el sistema calcula la entrada neta. Para una recarga de Salad registra solo el dinero que salió realmente, no el consumo de GPU. Fechas UTC.</p>
<form method="post" action="/?page=finance">
<input type="hidden" name="csrf" value="<?= $csrf ?>">
<div class="accounting-input-grid">
<label>Tipo de movimiento
<select name="movement_type" required>
<?php foreach(miner_accounting_types() as $type=>$name): ?>
<option value="<?= miner_h($type) ?>"><?= miner_h($name) ?></option>
<?php endforeach; ?>
</select></label>
<label>Organización (obligatoria para pagos Salad y otros cobros/gastos)
<select name="movement_org">
<option value="">Compartido / sin atribución</option>
<?php foreach(array_unique(array_column($targets,'organization_slug')) as $org): ?>
<option value="<?= miner_h($org) ?>"><?= miner_h(strtoupper($org)) ?></option>
<?php endforeach; ?>
</select></label>
<label>Fecha y hora UTC (AAAA-MM-DD HH:MM:SS)
<input required name="movement_date" value="<?= gmdate('Y-m-d H:i:s') ?>" maxlength="19"></label>
<label>USD bruto o USD equivalente (solo movimientos monetarios)
<input name="movement_usd" inputmode="decimal" placeholder="Ej. 10.00000000" maxlength="22"></label>
<label>PRL transferidos, pagados o vendidos (solo movimientos PRL)
<input name="movement_prl" inputmode="decimal" placeholder="Ej. 50.25000000" maxlength="25"></label>
<label>Comisión de venta en USD (solo venta PRL)
<input name="movement_fee" inputmode="decimal" placeholder="Ej. 0.10000000" maxlength="22"></label>
<label>Referencia única del comprobante
<input required name="movement_reference" maxlength="180" placeholder="ID de recibo, TXID, operación exchange"></label>
<label>Nota opcional
<input name="movement_memo" maxlength="300" placeholder="Ej. pago de crédito Salad con USDC"></label>
</div>
<button name="action" value="accounting_event">Registrar movimiento comprobado</button>
</form>
</section>
<section class="accounting-panel">
<h3>Últimos movimientos registrados</h3>
<?php if(!$ledgerData['entries']): ?><p class="muted">Aún no hay movimientos. No se mostrará flujo de caja cero como si todos los registros estuvieran completos.</p><?php else: ?>
<div class="tablewrap mobile-stack"><table><thead><tr><th>UTC</th><th>Movimiento</th><th>Organización</th><th>USD bruto</th><th>Comisión USD</th><th>PRL</th><th>Referencia</th></tr></thead><tbody>
<?php foreach($ledgerData['entries'] as $e): ?>
<tr><td data-label="UTC"><?= miner_h($e['occurred_at']) ?></td>
<td data-label="Movimiento"><?= miner_h(miner_accounting_types()[$e['event_type']]??$e['event_type']) ?></td>
<td data-label="Organización"><?= miner_h($e['organization']??'Compartido') ?></td>
<td data-label="USD bruto"><?= miner_h($e['usd_amount']??'—') ?></td>
<td data-label="Comisión USD"><?= miner_h($e['usd_fee']??'—') ?></td>
<td data-label="PRL"><?= miner_h($e['prl_amount']??'—') ?></td>
<td data-label="Referencia"><?= miner_h($e['source_reference']) ?><small><?= miner_h($e['memo']) ?></small></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</section>
<?php endif; ?>
<p class="muted">**Cobertura:** solo incluye operaciones con comprobantes añadidas a este sistema. La diferencia entradas menos salidas NO equivale a utilidad neta ni a saldo disponible en Salad, SafeTrade o bancos; existen fondos PRL pendientes, inversiones prepagadas y costos todavía sin conciliar.</p>
</section>
<?php elseif($page==='diagnostics'): ?>
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
