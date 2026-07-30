<?php

$cargoIds = array_map(
    static fn (string $value): int => (int) trim($value),
    explode(',', (string) env('PORTAL_RESET_ALLOWED_CARGO_IDS', '11,12,13,14'))
);
$roleKeywords = array_map(
    static fn (string $value): string => mb_strtolower(trim($value)),
    explode(',', (string) env('PORTAL_RESET_ALLOWED_ROLE_KEYWORDS', 'gerencia,desarrollo'))
);

return [
    'allowed_cargo_ids' => array_values(array_filter($cargoIds)),
    'allowed_role_keywords' => array_values(array_filter($roleKeywords)),
    'confirmation_phrase' => env('PORTAL_RESET_CONFIRMATION', 'REINICIAR PORTALES'),
];
