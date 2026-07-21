<?php
// Validação/sanitização da entrada pública (mesmas regras da versão FastAPI).

// DDDs que existem no Brasil (Anatel).
const DDDS_VALIDOS = [
    11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 24, 27, 28,
    31, 32, 33, 34, 35, 37, 38, 41, 42, 43, 44, 45, 46, 47, 48, 49,
    51, 53, 54, 55, 61, 62, 63, 64, 65, 66, 67, 68, 69,
    71, 73, 74, 75, 77, 79, 81, 82, 83, 84, 85, 86, 87, 88, 89,
    91, 92, 93, 94, 95, 96, 97, 98, 99,
];

// Retorna o telefone normalizado (10-11 dígitos, sem o 55 do país) ou null.
// Além do formato, barra números "de mentira": DDD inexistente, celular sem o
// nono dígito 9, assinante de um dígito só (48 99999-9999), poucos dígitos
// distintos (48 98989-8989) e sequência perfeita (48 91234-5678).
function sanitiza_telefone(string $v): ?string
{
    $d = preg_replace('/\D+/', '', $v);
    // Veio com o código do país? Tira o 55 e valida o resto.
    if (strpos($d, '55') === 0 && (strlen($d) === 12 || strlen($d) === 13)) {
        $d = substr($d, 2);
    }
    $len = strlen($d);
    if ($len < 10 || $len > 11) {
        return null;
    }
    if (!in_array((int) substr($d, 0, 2), DDDS_VALIDOS, true)) {
        return null;
    }
    $sub = substr($d, 2); // assinante: 9 dígitos no celular, 8 no fixo
    if ($len === 11 && $sub[0] !== '9') {
        return null; // celular sempre começa com 9 (nono dígito)
    }
    if (preg_match('/^(\d)\1+$/', $sub)) {
        return null; // assinante inteiro com o mesmo dígito
    }
    // Celular com menos de 3 dígitos distintos no assinante = chute.
    // (só no celular: fixo real tipo 3333-4444 tem 2 e é legítimo)
    if ($len === 11 && count(array_unique(str_split($sub))) < 3) {
        return null;
    }
    // Sequência perfeita (crescente ou decrescente) nos 8 dígitos finais.
    $t = substr($d, -8);
    $asc = true;
    $desc = true;
    for ($i = 1; $i < 8; $i++) {
        $df = (int) $t[$i] - (int) $t[$i - 1];
        if ($df !== 1) {
            $asc = false;
        }
        if ($df !== -1) {
            $desc = false;
        }
    }
    if ($asc || $desc) {
        return null;
    }
    return $d;
}

// Normaliza o MAC para "AA:BB:CC:DD:EE:FF". Retorna:
//   string normalizada | null (não informado) | false (informado e inválido)
function sanitiza_mac(?string $v)
{
    if ($v === null) {
        return null;
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$/', $v)) {
        return false;
    }
    return strtoupper(str_replace('-', ':', $v));
}
