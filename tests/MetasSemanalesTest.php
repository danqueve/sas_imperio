<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/funciones.php';

/**
 * Especifica la cuota que realmente integra una meta semanal.
 *
 * La función bajo prueba recibe el pago anterior y el pago confirmado en la
 * semana ya agregados por la consulta que arma las metas. Esto permite que la
 * regla sea pura, repetible y común a la meta actual y al snapshot histórico.
 */
final class MetasSemanalesTest extends TestCase
{
    private const FECHA_CIERRE_SEMANA = '2026-09-26';

    #[Test]
    #[DataProvider('cuotasDadasDeBajaSinPagoEnSemanaProvider')]
    public function it_excludes_cancelled_or_empty_state_without_payment_in_week(string $estado): void
    {
        // Arrange
        $cuota = $this->cuota(['estado' => $estado]);

        // Act
        $resultado = evaluar_cobrabilidad_meta_semanal($cuota, self::FECHA_CIERRE_SEMANA);

        // Assert
        $this->assertFalse($resultado['incluida']);
        $this->assertFalse($resultado['ya_estaba_paga']);
        $this->assertSame(0.0, $resultado['mora']);
        $this->assertSame(0.0, $resultado['monto_meta']);
    }

    public static function cuotasDadasDeBajaSinPagoEnSemanaProvider(): iterable
    {
        yield 'cuota CANCELADA' => ['CANCELADA'];
        yield 'cuota con estado vacío de una baja antigua' => [''];
    }

    #[Test]
    #[DataProvider('cuotasDadasDeBajaConPagoEnSemanaProvider')]
    public function it_keeps_cancelled_or_empty_state_with_confirmed_payment_in_week(string $estado): void
    {
        // Arrange
        $cuota = $this->cuota([
            'estado'          => $estado,
            'pago_en_semana'  => 1000.0,
        ]);

        // Act
        $resultado = evaluar_cobrabilidad_meta_semanal($cuota, self::FECHA_CIERRE_SEMANA);

        // Assert
        $this->assertTrue($resultado['incluida']);
        $this->assertFalse($resultado['ya_estaba_paga']);
        $this->assertSame(0.0, $resultado['mora']);
        $this->assertSame(1000.0, $resultado['monto_meta']);
    }

    public static function cuotasDadasDeBajaConPagoEnSemanaProvider(): iterable
    {
        yield 'cuota CANCELADA con pago confirmado' => ['CANCELADA'];
        yield 'cuota de baja antigua con estado vacío y pago confirmado' => [''];
    }

    #[Test]
    public function it_excludes_a_quota_fully_confirmed_before_monday(): void
    {
        // Arrange
        $cuota = $this->cuota([
            'pagado_antes' => 1000.0,
        ]);

        // Act
        $resultado = evaluar_cobrabilidad_meta_semanal($cuota, self::FECHA_CIERRE_SEMANA);

        // Assert
        $this->assertFalse($resultado['incluida']);
        $this->assertTrue($resultado['ya_estaba_paga']);
        $this->assertSame(0.0, $resultado['mora']);
        $this->assertSame(0.0, $resultado['monto_meta']);
    }

    #[Test]
    public function it_keeps_a_previously_partially_paid_quota_at_nominal_amount(): void
    {
        // Arrange
        $cuota = $this->cuota([
            'pagado_antes' => 350.0,
        ]);

        // Act
        $resultado = evaluar_cobrabilidad_meta_semanal($cuota, self::FECHA_CIERRE_SEMANA);

        // Assert
        $this->assertTrue($resultado['incluida']);
        $this->assertFalse($resultado['ya_estaba_paga']);
        $this->assertSame(0.0, $resultado['mora']);
        $this->assertSame(1000.0, $resultado['monto_meta']);
    }

    #[Test]
    public function it_keeps_a_payment_confirmed_during_week_and_does_not_mark_it_already_paid(): void
    {
        // Arrange
        $cuota = $this->cuota([
            'estado'         => 'PAGADA',
            'pago_en_semana' => 1000.0,
        ]);

        // Act
        $resultado = evaluar_cobrabilidad_meta_semanal($cuota, self::FECHA_CIERRE_SEMANA);

        // Assert
        $this->assertTrue($resultado['incluida']);
        $this->assertFalse($resultado['ya_estaba_paga']);
        $this->assertSame(0.0, $resultado['mora']);
        $this->assertSame(1000.0, $resultado['monto_meta']);
    }

    #[Test]
    public function it_calculates_historical_snapshot_mora_at_the_week_closing_date(): void
    {
        // Arrange
        $cuota = $this->cuota([
            'fecha_vencimiento'      => '2026-01-01',
            'interes_moratorio_pct'  => 15.0,
        ]);

        // Act
        $resultado = evaluar_cobrabilidad_meta_semanal($cuota, '2026-01-17');

        // Assert: 14 días hábiles al 17/01/2026 => mora de $350.00.
        // Usar la fecha actual produciría otro importe y alteraría snapshots pasados.
        $this->assertTrue($resultado['incluida']);
        $this->assertFalse($resultado['ya_estaba_paga']);
        $this->assertSame(350.0, $resultado['mora']);
        $this->assertSame(1350.0, $resultado['monto_meta']);
    }

    /**
     * @param array<string, mixed> $sobrescribir
     * @return array<string, mixed>
     */
    private function cuota(array $sobrescribir = []): array
    {
        return array_replace([
            'estado'                 => 'PENDIENTE',
            'monto_cuota'            => 1000.0,
            'monto_mora'             => 0.0,
            'fecha_vencimiento'      => '2026-09-24',
            'interes_moratorio_pct'  => 15.0,
            'pagado_antes'           => 0.0,
            'pago_en_semana'         => 0.0,
        ], $sobrescribir);
    }
}
