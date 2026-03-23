<?php
// ============================================================
// creditos/refinanciar.php — Refinanciación de crédito activo
// Reestructura las cuotas pendientes sin tocar las ya pagadas.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: index'); exit; }

// ── Crédito ──────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a  ON cr.articulo_id = a.id
    JOIN ic_usuarios u   ON cr.cobrador_id = u.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) { header('Location: index'); exit; }

if (!in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Solo se pueden refinanciar créditos activos o morosos.'];
    header("Location: ver?id=$id");
    exit;
}

// ── Cuotas pagadas ───────────────────────────────────────────
$p_stmt = $pdo->prepare("
    SELECT COUNT(*) AS c, COALESCE(SUM(monto_cuota), 0) AS total
    FROM ic_cuotas WHERE credito_id = ? AND estado = 'PAGADA'
");
$p_stmt->execute([$id]);
$pi             = $p_stmt->fetch();
$cuotas_pagadas = (int)   $pi['c'];
$total_pagado   = (float) $pi['total'];

// ── Cuotas pendientes / vencidas ──────────────────────────────
$pend_stmt = $pdo->prepare("
    SELECT * FROM ic_cuotas
    WHERE credito_id = ? AND estado IN ('PENDIENTE','VENCIDA')
    ORDER BY numero_cuota
");
$pend_stmt->execute([$id]);
$cuotas_pendientes = $pend_stmt->fetchAll();

$deuda_capital = 0.0;
$total_mora    = 0.0;
foreach ($cuotas_pendientes as $cp) {
    $deuda_capital += (float) $cp['monto_cuota'];
    $dias = dias_atraso_habiles($cp['fecha_vencimiento']);
    if ($dias > 0) {
        $total_mora += calcular_mora($cp['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
    }
}
$cant_pendientes = count($cuotas_pendientes);

// ── Cuotas CAP_PAGADA: capital ya cobrado, mora pendiente (#12) ─
$cap_stmt = $pdo->prepare("
    SELECT id, monto_mora, monto_cuota, fecha_vencimiento
    FROM ic_cuotas
    WHERE credito_id = ? AND estado = 'CAP_PAGADA'
");
$cap_stmt->execute([$id]);
$cuotas_cap_pagada = $cap_stmt->fetchAll();
$mora_cap_pagada   = 0.0;
foreach ($cuotas_cap_pagada as $cp) {
    // Si hay mora congelada la usamos; si no, la calculamos
    if ((float)$cp['monto_mora'] > 0) {
        $mora_cap_pagada += (float) $cp['monto_mora'];
    } else {
        $dias = dias_atraso_habiles($cp['fecha_vencimiento']);
        if ($dias > 0) {
            $mora_cap_pagada += calcular_mora($cp['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
        }
    }
}
$total_mora += $mora_cap_pagada;

// ── Pagos temporales pendientes (bloquean el borrado de cuotas) ─
$pt_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM ic_pagos_temporales pt
    JOIN ic_cuotas c ON pt.cuota_id = c.id
    WHERE c.credito_id = ? AND pt.estado = 'PENDIENTE' AND c.estado != 'PAGADA'
");
$pt_stmt->execute([$id]);
$tiene_pagos_pendientes = (int) $pt_stmt->fetchColumn() > 0;

// ── Fecha sugerida para primer nuevo vencimiento ──────────────
$lp_stmt = $pdo->prepare("
    SELECT fecha_vencimiento FROM ic_cuotas
    WHERE credito_id = ? AND estado = 'PAGADA'
    ORDER BY numero_cuota DESC LIMIT 1
");
$lp_stmt->execute([$id]);
$last_paid_date = $lp_stmt->fetchColumn() ?: $cr['primer_vencimiento'];

function next_venc(string $base, string $frec): string {
    $f = new DateTime($base);
    match ($frec) {
        'semanal'   => $f->modify('+7 days'),
        'quincenal' => $f->modify('+15 days'),
        default     => $f->modify('+1 month'),
    };
    return $f->format('Y-m-d');
}

$cobradores = $pdo->query(
    "SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre"
)->fetchAll();

$veces_ref = (int) ($cr['veces_refinanciado'] ?? 0);
$error = '';

// ── Valores por defecto del formulario ───────────────────────
$f = [
    'nuevas_cuotas'       => max(1, (int)$cr['cant_cuotas'] - $cuotas_pagadas),
    'frecuencia'          => $cr['frecuencia'],
    'primer_vencimiento'  => next_venc($last_paid_date, $cr['frecuencia']),
    'capitalizar_mora'    => true,
    'interes_adicional'   => 0,
    'cobrador_id'         => $cr['cobrador_id'],
    'observaciones'       => $cr['observaciones'] ?? '',
];

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['nuevas_cuotas']      = (int)   ($_POST['nuevas_cuotas']      ?? 0);
    $f['frecuencia']         =         ($_POST['frecuencia']         ?? $cr['frecuencia']);
    $f['primer_vencimiento'] =         ($_POST['primer_vencimiento'] ?? '');
    $f['capitalizar_mora']   = isset($_POST['capitalizar_mora']);
    $f['interes_adicional']  = max(0, (float) ($_POST['interes_adicional'] ?? 0));
    $f['cobrador_id']        = (int)   ($_POST['cobrador_id']         ?? $cr['cobrador_id']);
    $f['observaciones']      = trim(   ($_POST['observaciones']       ?? ''));

    if ($tiene_pagos_pendientes) {
        $error = 'Hay cobros pendientes de aprobación en la rendición. Aprobá o rechazá esas entradas antes de refinanciar.';
    } elseif ($f['nuevas_cuotas'] < 1 || $f['nuevas_cuotas'] > 520) {
        $error = 'La cantidad de nuevas cuotas debe estar entre 1 y 520.';
    } elseif (empty($f['primer_vencimiento'])) {
        $error = 'Ingresá la fecha del primer nuevo vencimiento.';
    } else {
        $monto_fin = $deuda_capital + ($f['capitalizar_mora'] ? $total_mora : 0);
        if ($f['interes_adicional'] > 0) {
            $monto_fin *= (1 + $f['interes_adicional'] / 100);
        }
        $nuevo_valor_cuota = round($monto_fin / $f['nuevas_cuotas'], 2);

        if ($monto_fin <= 0) {
            $error = 'El saldo a refinanciar es cero o negativo. No hay deuda pendiente.';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Eliminar cuotas PENDIENTE y VENCIDA (sin pagos confirmados).
                //    CAP_PAGADA se mantiene (tiene FK en ic_pagos_confirmados),
                //    pero si capitalizamos mora, la condonamos en ellas (#12).
                $pdo->prepare("DELETE FROM ic_cuotas WHERE credito_id = ? AND estado IN ('PENDIENTE','VENCIDA')")
                    ->execute([$id]);

                // Condonar mora en cuotas CAP_PAGADA si se capitalizó (#12)
                if ($f['capitalizar_mora'] && $mora_cap_pagada > 0) {
                    $pdo->prepare("UPDATE ic_cuotas SET monto_mora = 0 WHERE credito_id = ? AND estado = 'CAP_PAGADA'")
                        ->execute([$id]);
                }

                // Determinar desde qué número continuar
                $mn_stmt = $pdo->prepare("SELECT COALESCE(MAX(numero_cuota),0) FROM ic_cuotas WHERE credito_id=?");
                $mn_stmt->execute([$id]);
                $max_num = (int) $mn_stmt->fetchColumn();

                // 2. Generar nuevas cuotas
                $fecha = new DateTime($f['primer_vencimiento']);
                $ins   = $pdo->prepare("
                    INSERT INTO ic_cuotas (credito_id, numero_cuota, fecha_vencimiento, monto_cuota)
                    VALUES (?,?,?,?)
                ");
                for ($i = 1; $i <= $f['nuevas_cuotas']; $i++) {
                    if ($i > 1) {
                        match ($f['frecuencia']) {
                            'semanal'   => $fecha->modify('+7 days'),
                            'quincenal' => $fecha->modify('+15 days'),
                            default     => $fecha->modify('+1 month'),
                        };
                    }
                    $ins->execute([$id, $max_num + $i, $fecha->format('Y-m-d'), $nuevo_valor_cuota]);
                }

                // 3. Actualizar crédito
                $nueva_cant = $max_num + $f['nuevas_cuotas'];
                $obs        = $f['observaciones'] !== '' ? $f['observaciones'] : ($cr['observaciones'] ?? '');
                $pdo->prepare("
                    UPDATE ic_creditos SET
                        cant_cuotas  = ?,
                        monto_cuota  = ?,
                        frecuencia   = ?,
                        cobrador_id  = ?,
                        veces_refinanciado          = COALESCE(veces_refinanciado, 0) + 1,
                        fecha_ultima_refinanciacion = CURDATE(),
                        observaciones = ?
                    WHERE id = ?
                ")->execute([$nueva_cant, $nuevo_valor_cuota, $f['frecuencia'], $f['cobrador_id'], $obs, $id]);

                // 4. Registrar en historial de refinanciaciones (#7)
                $pdo->prepare("
                    INSERT INTO ic_historial_refinanciaciones
                        (credito_id, usuario_id, cuotas_anteriores, monto_cuota_anterior,
                         cuotas_nuevas, monto_cuota_nueva, deuda_capital, frecuencia_nueva, observaciones)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $id,
                    $_SESSION['user_id'],
                    $cant_pendientes + count($cuotas_cap_pagada),
                    $cr['monto_cuota'],
                    $f['nuevas_cuotas'],
                    $nuevo_valor_cuota,
                    $deuda_capital,
                    $f['frecuencia'],
                    $f['observaciones'] ?: null,
                ]);

                $pdo->commit();

                $det = 'Ref.#' . ($veces_ref + 1)
                    . ' | ' . $f['nuevas_cuotas'] . ' cuotas nuevas de ' . formato_pesos($nuevo_valor_cuota)
                    . ($f['capitalizar_mora'] && $total_mora > 0 ? ' | Mora capitalizada: ' . formato_pesos($total_mora) : '');
                registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_REFINANCIADO', 'credito', $id, $det);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'msg'  => 'Refinanciación #' . ($veces_ref + 1) . ' aplicada. '
                            . $f['nuevas_cuotas'] . ' nuevas cuotas de ' . formato_pesos($nuevo_valor_cuota) . '.',
                ];
                header("Location: ver?id=$id");
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error al procesar la refinanciación: ' . $e->getMessage();
            }
        }
    }
}

$page_title    = 'Refinanciar Crédito #' . $id;
$page_current  = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:900px">

    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($tiene_pagos_pendientes): ?>
        <div class="alert-ic alert-warning">
            <i class="fa fa-exclamation-triangle"></i>
            <strong>Atención:</strong> Hay cobros pendientes de aprobación para este crédito.
            Aprobá o rechazá esas rendiciones antes de refinanciar.
        </div>
    <?php endif; ?>

    <!-- ESTADO ACTUAL -->
    <div class="card-ic mb-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-info-circle"></i> Estado Actual del Crédito</span>
            <span class="badge-ic badge-warning" style="font-size:.75rem">
                <?= $veces_ref > 0 ? 'Refinanciado ' . $veces_ref . ' vez' . ($veces_ref > 1 ? 'ces' : '') : 'Sin refinanciaciones previas' ?>
            </span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;padding:4px 0 8px">
            <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--primary)">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Cliente</div>
                <div class="fw-bold" style="font-size:.9rem;margin-top:4px"><?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= e($cr['articulo']) ?></div>
            </div>
            <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--success)">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Cuotas Pagadas</div>
                <div class="fw-bold" style="font-size:1.3rem;color:var(--success);margin-top:4px"><?= $cuotas_pagadas ?></div>
                <div class="text-muted" style="font-size:.78rem">de <?= $cr['cant_cuotas'] ?> totales</div>
            </div>
            <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--primary)">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Total Cobrado</div>
                <div class="fw-bold" style="font-size:1.1rem;color:var(--primary);margin-top:4px"><?= formato_pesos($total_pagado) ?></div>
            </div>
            <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--warning)">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Saldo Capital</div>
                <div class="fw-bold" style="font-size:1.1rem;color:var(--warning);margin-top:4px"><?= formato_pesos($deuda_capital) ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= $cant_pendientes ?> cuota<?= $cant_pendientes !== 1 ? 's' : '' ?> pendiente<?= $cant_pendientes !== 1 ? 's' : '' ?></div>
            </div>
            <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--danger)">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Mora Acumulada</div>
                <div class="fw-bold" style="font-size:1.1rem;color:var(--danger);margin-top:4px"><?= formato_pesos($total_mora) ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= $cr['interes_moratorio_pct'] ?>% semanal</div>
            </div>
        </div>
    </div>

    <!-- FORMULARIO DE REFINANCIACIÓN -->
    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-sync-alt"></i> Condiciones de la Refinanciación</span>
            </div>
            <div class="form-grid">

                <div class="form-group">
                    <label>Nuevas cuotas a generar *</label>
                    <input type="number" name="nuevas_cuotas" id="inp_nuevas_cuotas"
                           value="<?= $f['nuevas_cuotas'] ?>" min="1" max="520" required
                           oninput="recalcular()">
                    <small class="text-muted">Las cuotas ya pagadas (<?= $cuotas_pagadas ?>) quedan intactas.</small>
                </div>

                <div class="form-group">
                    <label>Frecuencia de pago *</label>
                    <select name="frecuencia" id="inp_frecuencia" required onchange="recalcular()">
                        <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $f['frecuencia'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Primer nuevo vencimiento *</label>
                    <input type="date" name="primer_vencimiento" id="inp_primer_venc"
                           value="<?= $f['primer_vencimiento'] ?>" required min="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label>Cobrador</label>
                    <select name="cobrador_id">
                        <?php foreach ($cobradores as $cob): ?>
                            <option value="<?= $cob['id'] ?>" <?= $f['cobrador_id'] == $cob['id'] ? 'selected' : '' ?>>
                                <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Capitalizar mora -->
                <div class="form-group" style="grid-column:span 2">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none">
                        <input type="checkbox" name="capitalizar_mora" id="chk_mora"
                               <?= $f['capitalizar_mora'] ? 'checked' : '' ?>
                               onchange="recalcular()"
                               style="width:18px;height:18px;cursor:pointer">
                        <span>
                            <strong>Capitalizar mora acumulada</strong>
                            — Sumar la mora de <?= formato_pesos($total_mora) ?> al saldo a financiar
                        </span>
                    </label>
                    <?php if ($total_mora == 0): ?>
                        <small class="text-muted">No hay mora acumulada a la fecha.</small>
                    <?php elseif ($mora_cap_pagada > 0): ?>
                        <small class="text-muted">
                            Incluye <?= formato_pesos($mora_cap_pagada) ?> de mora en cuotas CAP_PAGADA, que será condonada al capitalizar.
                        </small>
                    <?php endif; ?>
                </div>

                <!-- Interés adicional sobre saldo -->
                <div class="form-group">
                    <label>Interés adicional % sobre saldo <span class="text-muted">(opcional)</span></label>
                    <input type="number" name="interes_adicional" id="inp_interes_adic"
                           value="<?= $f['interes_adicional'] ?>" step="0.01" min="0" max="999"
                           oninput="recalcular()" placeholder="0">
                    <small class="text-muted">Se aplica sobre el saldo total después de sumar mora (si corresponde).</small>
                </div>

                <div class="form-group" style="grid-column:span 2">
                    <label>Motivo / Observaciones de la refinanciación</label>
                    <textarea name="observaciones" rows="2"
                              placeholder="Ej: Cliente solicitó extensión de plazo, acordado el <?= date('d/m/Y') ?>..."><?= e($f['observaciones']) ?></textarea>
                </div>
            </div>

            <!-- CALCULADOR LIVE -->
            <div style="margin-top:20px;background:var(--dark-bg);border:1px solid var(--dark-border);border-radius:10px;padding:20px">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:14px">
                    <i class="fa fa-calculator"></i> Resumen calculado
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px">
                    <div class="text-center">
                        <div class="text-muted" style="font-size:.75rem">Saldo Capital</div>
                        <div id="lbl_saldo" class="fw-bold" style="font-size:1.1rem;color:var(--warning)"><?= formato_pesos($deuda_capital) ?></div>
                    </div>
                    <div class="text-center">
                        <div class="text-muted" style="font-size:.75rem">+ Mora a capitalizar</div>
                        <div id="lbl_mora_cap" class="fw-bold" style="font-size:1.1rem;color:var(--danger)">
                            <?= $f['capitalizar_mora'] ? formato_pesos($total_mora) : formato_pesos(0) ?>
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-muted" style="font-size:.75rem">+ Interés adicional</div>
                        <div id="lbl_interes_adic" class="fw-bold" style="font-size:1.1rem">$ 0,00</div>
                    </div>
                    <div class="text-center" style="border-left:2px solid var(--dark-border);padding-left:14px">
                        <div class="text-muted" style="font-size:.75rem">Total a Financiar</div>
                        <div id="lbl_total_fin" class="fw-bold" style="font-size:1.3rem;color:var(--primary)">
                            <?= formato_pesos($deuda_capital + ($f['capitalizar_mora'] ? $total_mora : 0)) ?>
                        </div>
                    </div>
                    <div class="text-center" style="border-left:2px solid var(--dark-border);padding-left:14px">
                        <div class="text-muted" style="font-size:.75rem">Valor Cuota Nueva</div>
                        <div id="lbl_valor_cuota" class="fw-bold" style="font-size:1.5rem;color:var(--success)">
                            <?php
                                $tot_fin = $deuda_capital + ($f['capitalizar_mora'] ? $total_mora : 0);
                                echo formato_pesos($f['nuevas_cuotas'] > 0 ? $tot_fin / $f['nuevas_cuotas'] : 0);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3 mb-5">
            <button type="button" class="btn-ic btn-primary"
                    <?= $tiene_pagos_pendientes ? 'disabled' : '' ?>
                    onclick="abrirModalConfirmar()">
                <i class="fa fa-sync-alt"></i> Confirmar Refinanciación
            </button>
            <a href="ver?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<!-- ── Modal confirmación con cuenta regresiva ─────────────────── -->
<div id="modal-refin" style="
        display:none;position:fixed;inset:0;z-index:9999;
        background:rgba(0,0,0,.65);backdrop-filter:blur(3px);
        align-items:center;justify-content:center">
    <div style="
            background:var(--dark-card,#1e2130);border:1px solid var(--dark-border,#2d3250);
            border-radius:14px;padding:32px 36px;max-width:440px;width:90%;
            box-shadow:0 20px 60px rgba(0,0,0,.5);text-align:center">

        <!-- Icono advertencia -->
        <div style="width:64px;height:64px;border-radius:50%;background:rgba(245,158,11,.15);
                    display:flex;align-items:center;justify-content:center;margin:0 auto 18px">
            <i class="fa fa-triangle-exclamation" style="font-size:1.8rem;color:#f59e0b"></i>
        </div>

        <div style="font-size:1.15rem;font-weight:800;margin-bottom:8px">¿Confirmar refinanciación?</div>
        <div style="font-size:.875rem;color:var(--text-muted,#94a3b8);margin-bottom:24px;line-height:1.5">
            Esta acción eliminará las cuotas pendientes y generará nuevas cuotas.<br>
            <strong>No se puede deshacer.</strong>
        </div>

        <!-- Cuenta regresiva -->
        <div id="modal-countdown-wrap" style="margin-bottom:20px">
            <div style="font-size:.78rem;color:var(--text-muted,#94a3b8);margin-bottom:8px">
                Podés confirmar en
            </div>
            <div style="
                    width:60px;height:60px;border-radius:50%;margin:0 auto;
                    border:3px solid var(--dark-border,#2d3250);
                    display:flex;align-items:center;justify-content:center;
                    font-size:1.6rem;font-weight:900;color:#f59e0b;
                    position:relative">
                <svg style="position:absolute;inset:0;width:100%;height:100%;transform:rotate(-90deg)" viewBox="0 0 60 60">
                    <circle cx="30" cy="30" r="27" fill="none" stroke="var(--dark-border,#2d3250)" stroke-width="3"/>
                    <circle id="modal-arc" cx="30" cy="30" r="27" fill="none" stroke="#f59e0b" stroke-width="3"
                            stroke-dasharray="169.6" stroke-dashoffset="0"
                            style="transition:stroke-dashoffset .9s linear"/>
                </svg>
                <span id="modal-num">10</span>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:center">
            <button id="btn-confirmar-refin" class="btn-ic btn-primary" disabled
                    onclick="submitRefinanciar()"
                    style="min-width:160px;opacity:.45;cursor:not-allowed;transition:opacity .3s">
                <i class="fa fa-sync-alt"></i> Confirmar
            </button>
            <button class="btn-ic btn-ghost" onclick="cerrarModalConfirmar()">Cancelar</button>
        </div>
    </div>
</div>

<script>
const DEUDA_CAPITAL = <?= $deuda_capital ?>;
const MORA_TOTAL    = <?= $total_mora ?>;

function fmtMoney(n) {
    return '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function recalcular() {
    const cuotas       = parseInt(document.getElementById('inp_nuevas_cuotas').value) || 1;
    const capMora      = document.getElementById('chk_mora').checked;
    const interesAdic  = parseFloat(document.getElementById('inp_interes_adic').value) || 0;

    const moraUsada   = capMora ? MORA_TOTAL : 0;
    let   saldoBase   = DEUDA_CAPITAL + moraUsada;
    const incremento  = saldoBase * (interesAdic / 100);
    const totalFin    = saldoBase + incremento;
    const valorCuota  = cuotas > 0 ? totalFin / cuotas : 0;

    document.getElementById('lbl_mora_cap').textContent     = fmtMoney(moraUsada);
    document.getElementById('lbl_interes_adic').textContent = fmtMoney(incremento);
    document.getElementById('lbl_total_fin').textContent    = fmtMoney(totalFin);
    document.getElementById('lbl_valor_cuota').textContent  = fmtMoney(valorCuota);
}

document.addEventListener('DOMContentLoaded', recalcular);

// ── Modal cuenta regresiva ────────────────────────────────────
let _cdTimer = null;

function abrirModalConfirmar() {
    const modal  = document.getElementById('modal-refin');
    const num    = document.getElementById('modal-num');
    const arc    = document.getElementById('modal-arc');
    const btnOk  = document.getElementById('btn-confirmar-refin');
    const TOTAL  = 169.6; // circunferencia (2π×27)
    let   seg    = 10;

    // Reset
    num.textContent      = seg;
    arc.style.strokeDashoffset = 0;
    btnOk.disabled       = true;
    btnOk.style.opacity  = '.45';
    btnOk.style.cursor   = 'not-allowed';

    modal.style.display  = 'flex';
    clearInterval(_cdTimer);

    _cdTimer = setInterval(() => {
        seg--;
        num.textContent = seg;
        arc.style.strokeDashoffset = TOTAL * (1 - seg / 10);

        if (seg <= 0) {
            clearInterval(_cdTimer);
            num.textContent      = '✓';
            arc.style.stroke     = '#10b981';
            btnOk.disabled       = false;
            btnOk.style.opacity  = '1';
            btnOk.style.cursor   = 'pointer';
        }
    }, 1000);
}

function cerrarModalConfirmar() {
    clearInterval(_cdTimer);
    document.getElementById('modal-refin').style.display = 'none';
}

function submitRefinanciar() {
    cerrarModalConfirmar();
    document.querySelector('form.form-ic').submit();
}

// Cerrar con Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarModalConfirmar();
});
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
