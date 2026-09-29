<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/funciones.php';

class FinanzasTest extends TestCase
{
    public function testCalcularMora()
    {
        // calcular_mora() tiene un período de gracia de 6 días hábiles
        // (MORA_DIAS_GRACIA en config/funciones.php) — ningún atraso de
        // 0 a 6 días genera mora. Recién del día 7 al 10 se cobra sobre
        // el EXCESO por encima de la gracia; desde el día 11 se cobra
        // sobre TODOS los días (sin descontar la gracia).

        // Dentro del período de gracia: sin mora
        $this->assertEquals(0.0, calcular_mora(1000.0, 0, 15.0));
        $this->assertEquals(0.0, calcular_mora(1000.0, 6, 15.0));

        // Día 7: 1 día de exceso sobre la gracia. 15% semanal / 6 = 2.5% diario.
        $this->assertEquals(25.0, calcular_mora(1000.0, 7, 15.0));

        // Día 10: 4 días de exceso sobre la gracia (10 - 6).
        $this->assertEquals(100.0, calcular_mora(1000.0, 10, 15.0));

        // Día 11: se cobra sobre los 11 días completos, no solo el exceso.
        $this->assertEquals(275.0, calcular_mora(1000.0, 11, 15.0));
    }

    public function testDiasHabilesAtraso()
    {
        // Vencimiento viernes, hoy es lunes. 
        // Días de atraso: sábado (1) y lunes (1). Domingo(0). Total = 2.
        $dias = dias_atraso_habiles('2026-03-06', '2026-03-09');
        $this->assertEquals(2, $dias);
    }

    public function testDeterminarEstadoCuotaPagadaExacta()
    {
        // Monto 1000, mora 0, pagado 1000
        $estado = determinar_estado_cuota(1000.0, 0.0, 1000.0);
        $this->assertEquals('PAGADA', $estado);
    }

    public function testDeterminarEstadoCuotaPagadaConTolerancia()
    {
        // Monto 1000, mora 0, pagado 999.996 (dentro del rango -0.005)
        $estado = determinar_estado_cuota(1000.0, 0.0, 999.996);
        $this->assertEquals('PAGADA', $estado);
    }

    public function testDeterminarEstadoCuotaParcial()
    {
        // Monto 1000, mora 50, pagado 1000 (Faltan 50)
        $estado = determinar_estado_cuota(1000.0, 50.0, 1000.0);
        $this->assertEquals('PARCIAL', $estado);
    }
}
