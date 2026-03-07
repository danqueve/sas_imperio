<?php
require_once __DIR__ . '/config/conexion.php';
$pdo = obtener_conexion();

// Buscar cuotas PARCIAL o PAGADA que NO tengan pagos en ic_pagos_confirmados
$stmt = $pdo->prepare("
    SELECT c.id, c.numero_cuota, c.estado, c.saldo_pagado, 
           cr.id as credito_id, cl.nombres, cl.apellidos
    FROM ic_cuotas c
    JOIN ic_creditos cr ON c.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE c.estado IN ('PARCIAL', 'PAGADA')
    AND c.saldo_pagado > 0
    AND NOT EXISTS (
        SELECT 1 FROM ic_pagos_confirmados pc WHERE pc.cuota_id = c.id
    )
");
$stmt->execute();
$cuotas_inconsistentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Cuotas PARCIAL / PAGADA sin pagos confirmados reales:\n";
foreach ($cuotas_inconsistentes as $c) {
    echo "Credito {$c['credito_id']} ({$c['nombres']} {$c['apellidos']}) - Cuota {$c['numero_cuota']} - Estado: {$c['estado']} - Saldo: {$c['saldo_pagado']}\n";
}

echo "\nChequeando pagos_confirmados para crédito de Cinthia Aredes (id de Cinthia):\n";
// Buscamos a Cinthia
$cli = $pdo->prepare("SELECT id FROM ic_clientes WHERE nombres LIKE '%Cinthia%' AND apellidos LIKE '%Aredes%'");
$cli->execute();
$cinthia_id = $cli->fetchColumn();

if ($cinthia_id) {
    $cr = $pdo->prepare("SELECT id FROM ic_creditos WHERE cliente_id = ?");
    $cr->execute([$cinthia_id]);
    $credito_id = $cr->fetchColumn();
    
    echo "Crédito de Cinthia: $credito_id\n";
    $pagos = $pdo->prepare("SELECT pc.cuota_id, pc.monto_total, c.numero_cuota FROM ic_pagos_confirmados pc JOIN ic_cuotas c ON pc.cuota_id = c.id WHERE pc.cuota_id IN (SELECT id FROM ic_cuotas WHERE credito_id = ?)");
    $pagos->execute([$credito_id]);
    foreach ($pagos->fetchAll() as $p) {
         echo "Cuota #{$p['numero_cuota']} - Pago confirmado de: {$p['monto_total']}\n";
    }
}
