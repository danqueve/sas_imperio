<?php
// ============================================================
// creditos/reconocimiento_guardar.php — Guardar reconocimiento
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function limpiar(string $v): string {
    return trim(strip_tags($v));
}

$pdo        = obtener_conexion();
$credito_id = (int) ($_POST['credito_id'] ?? 0);
$accion     = $_POST['accion'] ?? 'guardar';

if (!$credito_id) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'ID inválido']); exit; }
    header('Location: index'); exit;
}

// Verificar que el crédito existe y obtener cliente_id + tiene_garante
$cr = $pdo->prepare("
    SELECT cr.cliente_id, cl.tiene_garante, g.id AS garante_id
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_garantes g ON g.cliente_id = cl.id
    WHERE cr.id = ?
");
$cr->execute([$credito_id]);
$info = $cr->fetch();
if (!$info) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Crédito no encontrado']); exit; }
    header('Location: index'); exit;
}

$campos = [
    'credito_id'       => $credito_id,
    'deudor_genero'    => limpiar($_POST['deudor_genero']    ?? 'El'),
    'deudor_cuil'      => limpiar($_POST['deudor_cuil']      ?? ''),
    'deudor_calle1'    => limpiar($_POST['deudor_calle1']    ?? ''),
    'deudor_calle2'    => limpiar($_POST['deudor_calle2']    ?? ''),
    'suma_letras'      => limpiar($_POST['suma_letras']      ?? ''),
    'tiene_garante'    => (int)$info['tiene_garante'],
    'garante_genero'   => limpiar($_POST['garante_genero']   ?? 'Sr.'),
    'garante_cuil'     => limpiar($_POST['garante_cuil']     ?? ''),
    'garante_localidad'=> limpiar($_POST['garante_localidad']?? ''),
    'dia_firma'        => (int) ($_POST['dia_firma']         ?? 1),
    'mes_firma'        => limpiar($_POST['mes_firma']        ?? ''),
    'anio_firma'       => (int) ($_POST['anio_firma']        ?? date('Y')),
    'ciudad_firma'     => limpiar($_POST['ciudad_firma']     ?? 'San Miguel de Tucuman'),
    'provincia_firma'  => limpiar($_POST['provincia_firma']  ?? 'Tucuman'),
];

// UPSERT en ic_reconocimientos
$pdo->prepare("
    INSERT INTO ic_reconocimientos
        (credito_id, deudor_genero, deudor_cuil, deudor_calle1, deudor_calle2,
         suma_letras, tiene_garante, garante_genero, garante_cuil, garante_localidad,
         dia_firma, mes_firma, anio_firma, ciudad_firma, provincia_firma, pdf_generado)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)
    ON DUPLICATE KEY UPDATE
        deudor_genero    = VALUES(deudor_genero),
        deudor_cuil      = VALUES(deudor_cuil),
        deudor_calle1    = VALUES(deudor_calle1),
        deudor_calle2    = VALUES(deudor_calle2),
        suma_letras      = VALUES(suma_letras),
        tiene_garante    = VALUES(tiene_garante),
        garante_genero   = VALUES(garante_genero),
        garante_cuil     = VALUES(garante_cuil),
        garante_localidad= VALUES(garante_localidad),
        dia_firma        = VALUES(dia_firma),
        mes_firma        = VALUES(mes_firma),
        anio_firma       = VALUES(anio_firma),
        ciudad_firma     = VALUES(ciudad_firma),
        provincia_firma  = VALUES(provincia_firma),
        pdf_generado     = 0
")->execute([
    $campos['credito_id'],
    $campos['deudor_genero'],
    $campos['deudor_cuil'],
    $campos['deudor_calle1'],
    $campos['deudor_calle2'],
    $campos['suma_letras'],
    $campos['tiene_garante'],
    $campos['garante_genero'],
    $campos['garante_cuil'],
    $campos['garante_localidad'],
    $campos['dia_firma'],
    $campos['mes_firma'],
    $campos['anio_firma'],
    $campos['ciudad_firma'],
    $campos['provincia_firma'],
]);

// Persistir calle1/calle2 en ic_clientes
if ($campos['deudor_calle1'] !== '' || $campos['deudor_calle2'] !== '') {
    $pdo->prepare("UPDATE ic_clientes SET calle1=?, calle2=? WHERE id=?")
        ->execute([$campos['deudor_calle1'], $campos['deudor_calle2'], $info['cliente_id']]);
}

// Persistir cuil/localidad en ic_garantes
if ($info['garante_id'] && ($campos['garante_cuil'] !== '' || $campos['garante_localidad'] !== '')) {
    $pdo->prepare("UPDATE ic_garantes SET cuil=?, localidad=? WHERE id=?")
        ->execute([$campos['garante_cuil'], $campos['garante_localidad'], $info['garante_id']]);
}

registrar_log($pdo, $_SESSION['user_id'], 'RECONOCIMIENTO_GUARDADO', 'credito', $credito_id,
    'Reconocimiento de deuda guardado');

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'credito_id' => $credito_id, 'accion' => $accion]);
    exit;
}

if ($accion === 'guardar_pdf') {
    header('Location: reconocimiento_pdf.php?credito_id=' . $credito_id);
} else {
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Reconocimiento guardado correctamente.'];
    header('Location: ver?id=' . $credito_id);
}
exit;
