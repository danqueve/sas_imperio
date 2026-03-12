<?php
// ============================================================
// services/WhatsAppService.php
// Servicio centralizado para envío de mensajes vía
// WhatsApp Cloud API (Meta for Developers v22.0)
// ============================================================
// Uso:
//   require_once __DIR__ . '/../config/whatsapp.php';
//   require_once __DIR__ . '/WhatsAppService.php';
//
//   $wa = new WhatsAppService();
//
//   // Mensaje de texto libre (solo dentro de ventana de 24h)
//   $wa->enviarTexto($telefono, 'Hola, su pago fue acreditado.');
//
//   // Template aprobado (outbound, sin ventana activa)
//   $wa->enviarTemplate($telefono, WA_TPL_PAGO, WA_TPL_LANG, ['Juan', '$5.000', '#3']);
// ============================================================

class WhatsAppService
{
    private string $phoneId;
    private string $token;
    private string $apiVersion;
    private string $logFile;

    public function __construct()
    {
        $this->phoneId    = WA_PHONE_ID;
        $this->token      = WA_TOKEN;
        $this->apiVersion = WA_API_VERSION;
        $this->logFile    = __DIR__ . '/../logs/whatsapp.log';
    }

    // ── API pública ──────────────────────────────────────────

    /**
     * Mensaje de texto libre.
     * Solo funciona si hay una sesión activa (el cliente escribió
     * en las últimas 24 horas). Para mensajes salientes sin sesión
     * usar enviarTemplate().
     */
    public function enviarTexto(string $telefono, string $texto): bool
    {
        if (!WA_ENABLED) return false;

        return $this->post([
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizarTel($telefono),
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $texto],
        ]);
    }

    /**
     * Template aprobado por Meta.
     * Necesario para mensajes salientes (cron, notificaciones, etc.)
     *
     * @param string   $telefono  Número destino
     * @param string   $template  Nombre del template (ej: WA_TPL_PAGO)
     * @param string   $lang      Código de idioma (ej: 'es', 'en_US')
     * @param string[] $params    Variables {{1}}, {{2}}... en el body del template
     */
    public function enviarTemplate(string $telefono, string $template, string $lang = 'es', array $params = []): bool
    {
        if (!WA_ENABLED) return false;

        $components = [];
        if (!empty($params)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn($p) => ['type' => 'text', 'text' => (string) $p],
                    array_values($params)
                ),
            ];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizarTel($telefono),
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => $lang],
                'components' => $components,
            ],
        ]);
    }

    // ── Internals ────────────────────────────────────────────

    /**
     * Normaliza un número argentino a formato internacional E.164
     * Ejemplos:
     *   0381XXXXXXX  → 549381XXXXXXX
     *   15XXXXXXXXX  → 54915XXXXXXX  (no debería ocurrir pero lo manejamos)
     *   381XXXXXXX   → 549381XXXXXXX (si tiene 9 dígitos locales)
     */
    private function normalizarTel(string $tel): string
    {
        $tel = preg_replace('/[^0-9]/', '', $tel);

        // Ya tiene prefijo Argentina
        if (str_starts_with($tel, '54') && strlen($tel) >= 12) {
            return $tel;
        }

        // Número con 0 adelante: 0381... → 549381...
        if (str_starts_with($tel, '0') && strlen($tel) === 11) {
            return '549' . substr($tel, 1);
        }

        // 10 dígitos sin cero: 381XXXXXXX → 549381XXXXXXX
        if (strlen($tel) === 10) {
            return '549' . $tel;
        }

        return $tel; // devolver tal cual si no reconoce el formato
    }

    /**
     * Ejecuta el POST a la API de Meta y registra el resultado.
     */
    private function post(array $payload): bool
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $this->phoneId
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        $ok = ($httpCode === 200);
        $this->registrarLog($httpCode, $payload['to'] ?? '?', $payload['type'] ?? '?', $response, $curlErr);

        return $ok;
    }

    /**
     * Escribe una línea en logs/whatsapp.log
     */
    private function registrarLog(int $code, string $to, string $type, string|false $response, string $error): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $estado = $code === 200 ? 'OK' : 'ERROR';
        $resp   = $response ? substr(preg_replace('/\s+/', ' ', $response), 0, 200) : $error;
        $linea  = sprintf(
            "[%s] %s | HTTP %d | to=%s type=%s | %s\n",
            date('Y-m-d H:i:s'),
            $estado,
            $code,
            $to,
            $type,
            $resp
        );

        file_put_contents($this->logFile, $linea, FILE_APPEND | LOCK_EX);
    }
}
