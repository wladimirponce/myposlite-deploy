<?php

declare(strict_types=1);

/** RUT canónico: número sin ceros iniciales, puntos ni guion, seguido del dígito verificador en mayúscula. */
function canonizarRut(mixed $input): string
{
    if (!is_string($input) || strlen($input) > 20) {
        throw new InvalidArgumentException('Ingrese un RUT válido.');
    }
    $rut = strtoupper(str_replace(['.', '-', ' '], '', trim($input)));
    if (!preg_match('/^[0-9]{1,8}[0-9K]$/D', $rut)) {
        throw new InvalidArgumentException('Ingrese un RUT válido.');
    }
    $numero = ltrim(substr($rut, 0, -1), '0');
    $suma = 0;
    foreach (str_split(strrev($numero)) as $i => $digito) {
        $suma += (int) $digito * ($i % 6 + 2);
    }
    $resto = 11 - $suma % 11;
    $dv = match ($resto) { 11 => '0', 10 => 'K', default => (string) $resto };
    if ($numero === '' || substr($rut, -1) !== $dv) {
        throw new InvalidArgumentException('El dígito verificador del RUT es incorrecto.');
    }
    return $numero . $dv;
}
