<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/funciones.php';

class FinanzasTest extends TestCase
{
    public function testCalcularMora()
    {
        // 1000 cuota, 1 día de atraso, 15% semanal -> 2.5% diario = 25
        $mora = calcular_mora(1000.0, 1, 15.0);
        $this->assertEquals(25.0, $mora);

        // 0 días hábiles
        $mora0 = calcular_mora(1000.0, 0, 15.0);
        $this->assertEquals(0.0, $mora0);

        // 2 días hábiles (5% = 50)
        $mora2 = calcular_mora(1000.0, 2, 15.0);
        $this->assertEquals(50.0, $mora2);
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
