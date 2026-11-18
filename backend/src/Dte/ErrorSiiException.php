<?php

declare(strict_types=1);

// Adaptado de Farmalider, api/src/Domain/Dte/ErrorSiiException.php, revisión 0c27acd (2026-11-06).
// Cambios en Lite: extiende RuntimeException con código 502 en vez de la excepción de dominio de Farmalider.

namespace MyposLite\Dte;

/** El SII no respondió o respondió algo que no se pudo usar. */
final class ErrorSiiException extends \RuntimeException
{
    /** @param array<string, mixed> $detalles Para diagnóstico interno; no se muestran al usuario. */
    public function __construct(string $mensaje, public readonly array $detalles = [])
    {
        parent::__construct($mensaje, 502);
    }

    /** @param array<string, mixed> $detalles */
    public static function porque(string $mensaje, array $detalles = []): self
    {
        return new self($mensaje, $detalles);
    }
}
