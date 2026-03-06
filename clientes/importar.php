<?php
// ============================================================
// clientes/importar.php — Importación masiva de clientes desde CSV
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('editar_clientes');

$pdo = obtener_conexion();
$error = '';
$resumen = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (is_uploaded_file($file)) {
        if (($handle = fopen($file, "r")) !== FALSE) {
            $headers = fgetcsv($handle, 1000, ",");
            
            // Validar headers (mínimos necesarios)
            $required = ['nombres', 'apellidos', 'telefono'];
            $missing = array_diff($required, $headers);
            
            if (empty($missing)) {
                $inserted = 0;
                $updated = 0;
                $errors = 0;
                $line = 1;

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $line++;
                    $row = array_combine($headers, $data);
                    
                    if (!$row) {
                        $errors++;
                        continue;
                    }

                    try {
                        $pdo->beginTransaction();

                        $token = generar_token();
                        $stmt = $pdo->prepare("
                            INSERT INTO ic_clientes
                              (nombres, apellidos, dni, cuil, telefono, telefono_alt, fecha_nacimiento,
                               direccion, direccion_laboral, coordenadas, cobrador_id, dia_cobro,
                               zona, estado, token_acceso, tiene_garante)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");
                        
                        $stmt->execute([
                            trim($row['nombres'] ?? ''),
                            trim($row['apellidos'] ?? ''),
                            trim($row['dni'] ?? ''),
                            trim($row['cuil'] ?? ''),
                            trim($row['telefono'] ?? ''),
                            trim($row['telefono_alt'] ?? ''),
                            ($row['fecha_nacimiento'] ?? null) ?: null,
                            trim($row['direccion'] ?? ''),
                            trim($row['direccion_laboral'] ?? ''),
                            trim($row['coordenadas'] ?? ''),
                            ((isset($row['cobrador_id']) && $row['cobrador_id'] !== '') ? (int)$row['cobrador_id'] : null),
                            ((isset($row['dia_cobro']) && $row['dia_cobro'] !== '') ? (int)$row['dia_cobro'] : null),
                            trim($row['zona'] ?? ''),
                            $row['estado'] ?? 'ACTIVO',
                            $token,
                            (!empty($row['tiene_garante']) ? 1 : 0),
                        ]);
                        
                        $cliente_id = (int)$pdo->lastInsertId();
                        
                        // Garante
                        if (!empty($row['tiene_garante']) && !empty($row['g_nombres']) && !empty($row['g_apellidos'])) {
                            $pdo->prepare("
                                INSERT INTO ic_garantes (cliente_id, nombres, apellidos, dni, telefono, direccion, coordenadas)
                                VALUES (?,?,?,?,?,?,?)
                            ")->execute([
                                $cliente_id,
                                trim($row['g_nombres']),
                                trim($row['g_apellidos']),
                                trim($row['g_dni'] ?? ''),
                                trim($row['g_telefono'] ?? ''),
                                trim($row['g_direccion'] ?? ''),
                                trim($row['g_coordenadas'] ?? ''),
                            ]);
                        }

                        registrar_log($pdo, $_SESSION['user_id'], 'CLIENTE_IMPORTADO', 'cliente', $cliente_id,
                            trim($row['apellidos']) . ', ' . trim($row['nombres']));

                        $pdo->commit();
                        $inserted++;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors++;
                        // Opcional: registrar error específico
                    }
                }
                
                $resumen = [
                    'success' => true,
                    'msg' => "Importación finalizada: $inserted insertados, $errors errores.",
                    'inserted' => $inserted,
                    'errors' => $errors
                ];
            } else {
                $error = "El archivo CSV no tiene las columnas requeridas: " . implode(', ', $missing);
            }
            fclose($handle);
        } else {
            $error = "No se pudo abrir el archivo.";
        }
    } else {
        $error = "Error al subir el archivo.";
    }
}

$page_title = 'Importar Clientes';
$page_current = 'clientes';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:800px">

    <div class="mb-4">
        <a href="index" class="btn-ic btn-ghost"><i class="fa fa-arrow-left"></i> Volver al listado</a>
    </div>

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-file-import"></i> Migración de Clientes via CSV</span>
        </div>
        
        <?php if ($error): ?>
            <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($resumen)): ?>
            <div class="alert-ic alert-success">
                <i class="fa fa-check-circle"></i> <?= e($resumen['msg']) ?>
            </div>
        <?php endif; ?>

        <div class="mb-4 p-3 bg-light border-radius-ic">
            <p><strong>Instrucciones:</strong></p>
            <ol>
                <li>Descarga la <a href="plantilla_clientes.csv" download>plantilla CSV</a>.</li>
                <li>Completa los datos de tus clientes respetando los encabezados.</li>
                <li>Los campos <strong>nombres</strong>, <strong>apellidos</strong> y <strong>telefono</strong> son obligatorios.</li>
                <li>Sube el archivo aquí para procesarlo.</li>
            </ol>
        </div>

        <form method="POST" enctype="multipart/form-data" class="form-ic">
            <div class="form-group">
                <label>Seleccionar archivo CSV</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn-ic btn-primary"><i class="fa fa-upload"></i> Procesar Importación</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
