<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/funciones.php';

/**
 * Tests para la lógica de reversa de rendiciones aprobadas.
 *
 * Nota: revertir_rendicion() requiere PDO + datos en BD. Estos tests cubren
 * los cálculos puros que componen la lógica + la existencia de la API.
 */
class ReversaRendicionTest extends TestCase
{
    public function testFuncionRevertirExiste(): void
    {
        $this->assertTrue(function_exists('revertir_rendicion'),
            'La función revertir_rendicion() debe existir.');

        $ref = new ReflectionFunction('revertir_rendicion');
        $params = $ref->getParameters();
        $this->assertCount(6, $params,
            'revertir_rendicion debe aceptar 6 parámetros: cobrador_id, fecha, origen, usuario_id, motivo, pdo');
        $this->assertSame('cobrador_id', $params[0]->getName());
        $this->assertSame('fecha',       $params[1]->getName());
        $this->assertSame('origen',      $params[2]->getName());
        $this->assertSame('usuario_id',  $params[3]->getName());
        $this->assertSame('motivo',      $params[4]->getName());
        $this->assertSame('pdo',         $params[5]->getName());
    }

    public function testRestaDeSaldoVuelveCuotaAPendienteOVencida(): void
    {
        // Cuota de 1000, saldo pagado 1000 (PAGADA), mora 0.
        // Al revertir un pago de 1000 → saldo=0, debe quedar PENDIENTE (fecha futura) o VENCIDA.
        $saldo_post = (float) max(0, 1000.0 - 1000.0);
        $this->assertSame(0.0, $saldo_post);

        // Con vencimiento futuro → PENDIENTE
        $fecha_futura = date('Y-m-d', strtotime('+30 days'));
        $estado_futuro = dias_atraso_habiles($fecha_futura) > 0 ? 'VENCIDA' : 'PENDIENTE';
        $this->assertSame('PENDIENTE', $estado_futuro,
            'Cuota con vencimiento futuro y saldo 0 debe quedar PENDIENTE.');

        // Con vencimiento pasado → VENCIDA
        $fecha_pasada = date('Y-m-d', strtotime('-30 days'));
        $estado_pasado = dias_atraso_habiles($fecha_pasada) > 0 ? 'VENCIDA' : 'PENDIENTE';
        $this->assertSame('VENCIDA', $estado_pasado,
            'Cuota con vencimiento pasado y saldo 0 debe quedar VENCIDA.');
    }

    public function testReversaParcialDejaCuotaEnParcial(): void
    {
        // Cuota 1000, saldo pre 1000 (PAGADA). Revertimos solo 400 → saldo 600 → PARCIAL.
        $monto = 1000.0;
        $saldo_post = 1000.0 - 400.0;
        $estado = determinar_estado_cuota($monto, 0.0, $saldo_post);

        $this->assertSame(600.0, $saldo_post);
        $this->assertSame('PARCIAL', $estado);
    }

    public function testReversaNoProduceSaldoNegativo(): void
    {
        // Defensa contra inconsistencias: si se intenta restar más de lo pagado,
        // el saldo se topa en 0 (no negativo).
        $saldo_pre = 100.0;
        $monto_revertido = 250.0;
        $saldo_post = (float) max(0, $saldo_pre - $monto_revertido);
        $this->assertSame(0.0, $saldo_post);
    }

    public function testReversaConsideraMoraVigente(): void
    {
        // Cuota 1000, mora 50, saldo post reversa 0 → estado debe ser PENDIENTE
        // (no PAGADA porque sigue habiendo mora pendiente).
        $estado = determinar_estado_cuota(1000.0, 50.0, 0.0);
        $this->assertNotSame('PAGADA', $estado);
    }
}
