<?php

declare(strict_types=1);

function promocionVigente(array $regla, string $local, DateTimeImmutable $ahora): bool
{
    if (!$regla['activa'] || ($regla['local_codigo'] !== null && $regla['local_codigo'] !== $local)) {
        return false;
    }
    $fecha = $ahora->format('Y-m-d');
    if ($regla['tipo'] === 'horario') {
        $hora = $ahora->format('H:i:s');
        $inicio = $regla['hora_inicio'];
        $fin = $regla['hora_fin'];
        if ($inicio < $fin) {
            if ($hora < $inicio || $hora >= $fin) {
                return false;
            }
        } elseif ($hora < $fin) {
            $fecha = $ahora->modify('-1 day')->format('Y-m-d');
        } elseif ($hora < $inicio) {
            return false;
        }
    }
    return $fecha >= $regla['desde'] && $fecha <= $regla['hasta'];
}

function fechaPromocion(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        throw new InvalidArgumentException('Ingrese fechas válidas.');
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if ($year < 1000 || !checkdate($month, $day, $year)) {
        throw new InvalidArgumentException('Ingrese fechas válidas.');
    }
    return $value;
}

function horaPromocion(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) {
        throw new InvalidArgumentException('Ingrese horas válidas.');
    }
    return $value . ':00';
}
