<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/funciones.php';

/**
 * Cubre los huecos de Prioridad 1 en la lógica financiera core:
 *  - dias_habiles()          (función base, sin tests previos)
 *  - dias_atraso_habiles()   (solo 1 caso existía)
 *  - calcular_mora()         (casos de tasa variable, decimales, negativos)
 *  - determinar_estado_cuota() (sobrepago, tolerancia límite, pago cero)
 */
final class LogicaFinancieraTest extends TestCase
{
    // ── dias_habiles() ────────────────────────────────────────────

    #[Test]
    #[DataProvider('diasHabilesProvider')]
    public function it_cuenta_dias_habiles_entre_dos_fechas(
        DateTime $desde,
        DateTime $hasta,
        int $esperado
    ): void {
        $resultado = dias_habiles($desde, $hasta);

        $this->assertSame($esperado, $resultado);
    }

    public static function diasHabilesProvider(): iterable
    {
        yield 'mismo día lunes cuenta como 1' => [
            new DateTime('2026-03-02'), new DateTime('2026-03-02'), 1,
        ];
        yield 'mismo día domingo cuenta como 0' => [
            new DateTime('2026-03-08'), new DateTime('2026-03-08'), 0,
        ];
        yield 'desde mayor que hasta devuelve 0' => [
            new DateTime('2026-03-09'), new DateTime('2026-03-02'), 0,
        ];
        yield 'lunes a sábado son 6 días hábiles' => [
            new DateTime('2026-03-02'), new DateTime('2026-03-07'), 6,
        ];
        yield 'lunes a domingo: domingo no suma, sigue siendo 6' => [
            new DateTime('2026-03-02'), new DateTime('2026-03-08'), 6,
        ];
        yield 'viernes a lunes cruzando fin de semana son 3 (vie+sáb+lun)' => [
            new DateTime('2026-03-06'), new DateTime('2026-03-09'), 3,
        ];
    }

    // ── dias_atraso_habiles() ─────────────────────────────────────

    #[Test]
    #[DataProvider('diasAtrasoProvider')]
    public function it_calcula_dias_habiles_de_atraso(
        string $vencimiento,
        string $referencia,
        int $esperado
    ): void {
        $resultado = dias_atraso_habiles($vencimiento, $referencia);

        $this->assertSame($esperado, $resultado);
    }

    public static function diasAtrasoProvider(): iterable
    {
        yield 'mismo día de vencimiento → 0 (hoy <= venc)' => [
            '2026-03-09', '2026-03-09', 0,
        ];
        yield 'referencia antes del vencimiento → 0 (fecha futura)' => [
            '2026-03-16', '2026-03-09', 0,
        ];
        yield 'vence viernes, ref sábado → 1 (sábado es hábil)' => [
            '2026-03-06', '2026-03-07', 1,
        ];
        yield 'vence sábado, ref lunes → 1 (domingo no cuenta)' => [
            '2026-03-07', '2026-03-09', 1,
        ];
        yield 'vence domingo, ref lunes → 1 (lunes es el primer día hábil)' => [
            '2026-03-08', '2026-03-09', 1,
        ];
        yield 'una semana exacta de atraso → 6 días hábiles' => [
            '2026-03-09', '2026-03-16', 6,
        ];
    }

    // ── calcular_mora() ───────────────────────────────────────────

    #[Test]
    #[DataProvider('calcularMoraProvider')]
    public function it_calcula_mora_segun_tasa_y_dias(
        float $monto,
        int $dias,
        float $tasa,
        float $esperado
    ): void {
        $resultado = calcular_mora($monto, $dias, $tasa);

        $this->assertSame($esperado, $resultado);
    }

    public static function calcularMoraProvider(): iterable
    {
        yield 'tasa 12% semanal, 1 día: 12/6=2% diario → 20.00' => [
            1000.0, 1, 12.0, 20.0,
        ];
        yield 'días negativos siempre devuelven 0' => [
            1000.0, -1, 15.0, 0.0,
        ];
        yield 'monto con decimales se redondea a 2 cifras' => [
            // 1500.50 × 2.5% × 1 = 37.5125 → 37.51
            1500.50, 1, 15.0, 37.51,
        ];
        yield '6 días hábiles a tasa 15%: 1000 × 2.5% × 6 = 150.00' => [
            1000.0, 6, 15.0, 150.0,
        ];
    }

    // ── determinar_estado_cuota() ─────────────────────────────────

    #[Test]
    #[DataProvider('estadoCuotaProvider')]
    public function it_determina_el_estado_final_de_la_cuota(
        float $monto_base,
        float $mora,
        float $saldo_pagado,
        string $esperado
    ): void {
        $resultado = determinar_estado_cuota($monto_base, $mora, $saldo_pagado);

        $this->assertSame($esperado, $resultado);
    }

    public static function estadoCuotaProvider(): iterable
    {
        yield 'sobrepago: pagado supera monto+mora → PAGADA' => [
            1000.0, 50.0, 1200.0, 'PAGADA',
        ];
        yield 'mora exacta: pagado == monto+mora → PAGADA' => [
            1000.0, 50.0, 1050.0, 'PAGADA',
        ];
        yield 'fuera de tolerancia: 0.006 de diferencia → PARCIAL' => [
            // saldo=999.994, total_requerido=999.995 → 999.994 < 999.995
            1000.0, 0.0, 999.994, 'PARCIAL',
        ];
        yield 'pago en cero → PARCIAL' => [
            1000.0, 0.0, 0.0, 'PARCIAL',
        ];
    }
}
