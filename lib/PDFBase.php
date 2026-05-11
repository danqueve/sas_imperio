<?php
// lib/PDFBase.php — Base FPDF class shared by all PDF export scripts
require_once __DIR__ . '/../fpdf/fpdf.php';

if (!function_exists('lat')) {
    function lat(string $s): string {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    }
}

if (!function_exists('fmt')) {
    function fmt(float $v): string {
        return '$ ' . number_format($v, 0, ',', '.');
    }
}

class PDFBase extends FPDF
{
    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    public function fitText(string $text, float $maxW, string $suffix = '..'): string
    {
        $encoded = lat($text);
        if ($this->GetStringWidth($encoded) <= $maxW) {
            return $encoded;
        }
        while (mb_strlen($text) > 1) {
            $text = mb_substr($text, 0, -1);
            $encoded = lat($text);
            if ($this->GetStringWidth($encoded . $suffix) <= $maxW) {
                return $encoded . $suffix;
            }
        }
        return $suffix;
    }

    protected function resetStyles(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);
    }
}
