<?php
// tickets/nuevo.php — Crear nuevo ticket
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

$pdo = obtener_conexion();

// Lista de usuarios (para delegación individual)
$usuarios = $pdo->query("SELECT id, CONCAT(nombre,' ',apellido) AS nombre_completo, rol
    FROM ic_usuarios WHERE activo=1 ORDER BY rol, nombre, apellido")->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title   = 'Nuevo Ticket';
$page_current = 'tickets';
$topbar_actions = '<a href="index" class="btn-ic btn-sm" style="background:rgba(107,114,128,.2);color:#9ca3af;border:1px solid rgba(107,114,128,.3)"><i class="fa fa-arrow-left me-1"></i> Volver</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<style>
.form-card {
    background: rgba(30,41,59,.4); border: 1px solid rgba(255,255,255,.05);
    border-radius: 16px; padding: 30px; margin: 0 auto;
}
.form-label-premium {
    font-size: .72rem; font-weight: 700; color: #94a3b8;
    text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 10px; display: block;
}
.input-premium {
    background: rgba(15,23,42,.6) !important; border: 1px solid rgba(255,255,255,.1) !important;
    color: #f1f5f9 !important; padding: 12px 16px !important; border-radius: 10px !important;
    font-size: .92rem !important; transition: all .2s !important;
}
.input-premium:focus {
    border-color: #3c50e0 !important; box-shadow: 0 0 0 4px rgba(60,80,224,0.15) !important;
    background: rgba(15,23,42,.8) !important;
}

.delegation-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
.del-option {
    background: rgba(15,23,42,.4); border: 1px solid rgba(255,255,255,.05);
    border-radius: 12px; padding: 15px; cursor: pointer; text-align: center;
    transition: all .2s; position: relative;
}
.del-option:hover { background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.15); }
.del-option i { font-size: 1.2rem; display: block; margin-bottom: 8px; color: #64748b; }
.del-option span { font-size: .75rem; font-weight: 600; color: #94a3b8; }

.del-radio { position: absolute; opacity: 0; }
.del-radio:checked + .del-option {
    background: rgba(60,80,224,0.1); border-color: #3c50e0;
}
.del-radio:checked + .del-option i { color: #3c50e0; }
.del-radio:checked + .del-option span { color: #f1f5f9; }
</style>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-7">
        <div class="form-card">
            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] ?> mb-4 border-0 rounded-3 shadow-sm"><?= e($flash['msg']) ?></div>
            <?php endif; ?>

            <form method="POST" action="procesar_ticket">
                <div class="mb-4">
                    <label class="form-label-premium">ASUNTO DEL PROBLEMA</label>
                    <input type="text" name="titulo" class="form-control input-premium"
                           placeholder="Ej: Error al cargar cobranza en zona norte" maxlength="200" required>
                </div>

                <div class="mb-4">
                    <label class="form-label-premium">DESCRIPCIÓN DETALLADA</label>
                    <textarea name="descripcion" class="form-control input-premium"
                              rows="6" placeholder="Cuéntanos un poco más sobre lo que está pasando..." required></textarea>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6">
                        <label class="form-label-premium">NIVEL DE PRIORIDAD</label>
                        <select name="prioridad" class="form-select input-premium">
                            <option value="baja">Baja (Consulta general)</option>
                            <option value="media" selected>Media (Incidencia normal)</option>
                            <option value="alta">Alta (Bloqueo / Urgencia)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label-premium">¿A QUIÉN VA DIRIGIDO?</label>
                    <div class="delegation-grid">
                        <label>
                            <input type="radio" name="tipo_delegacion" value="ninguna" checked class="del-radio" onchange="toggleDelegacion(this.value)">
                            <div class="del-option">
                                <i class="fa fa-minus-circle"></i>
                                <span>SIN ASIGNAR</span>
                            </div>
                        </label>
                        <label>
                            <input type="radio" name="tipo_delegacion" value="rol" class="del-radio" onchange="toggleDelegacion(this.value)">
                            <div class="del-option">
                                <i class="fa fa-users-gear"></i>
                                <span>A UN ROL</span>
                            </div>
                        </label>
                        <label>
                            <input type="radio" name="tipo_delegacion" value="usuario" class="del-radio" onchange="toggleDelegacion(this.value)">
                            <div class="del-option">
                                <i class="fa fa-user-tie"></i>
                                <span>A UN USUARIO</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div id="wrap_delegado_rol" class="mb-4 animate__animated animate__fadeIn" style="display:none">
                    <label class="form-label-premium">SELECCIONAR ROL DESTINATARIO</label>
                    <select name="delegado_a_rol" class="form-select input-premium">
                        <option value="">— Seleccionar un rol —</option>
                        <option value="admin">Administrador</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="cobrador">Cobrador</option>
                        <option value="vendedor">Vendedor</option>
                    </select>
                </div>

                <div id="wrap_delegado_usuario" class="mb-4 animate__animated animate__fadeIn" style="display:none">
                    <label class="form-label-premium">SELECCIONAR USUARIO ESPECÍFICO</label>
                    <select name="delegado_a_usuario" class="form-select input-premium">
                        <option value="">— Seleccionar un usuario —</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>">[<?= ucfirst(e($u['rol'])) ?>] <?= e($u['nombre_completo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="d-flex gap-3 mt-5">
                    <button type="submit" class="btn-ic btn-primary flex-grow-1 justify-content-center py-3">
                        <i class="fa fa-paper-plane me-2"></i> Crear Ticket de Soporte
                    </button>
                    <a href="index" class="btn-ic btn-ghost py-3 px-4">
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDelegacion(val) {
    document.getElementById('wrap_delegado_rol').style.display     = val === 'rol'     ? '' : 'none';
    document.getElementById('wrap_delegado_usuario').style.display = val === 'usuario' ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
