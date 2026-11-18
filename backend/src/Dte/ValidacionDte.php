<?php

declare(strict_types=1);

namespace MyposLite\Dte;

/**
 * Error de datos del motor DTE. Las clases copiadas de Farmalider lanzan `ValidacionException::campo($campo, $mensaje)`;
 * en Lite ese llamado se conserva con este nombre y la API responde 422 con el mensaje, como cualquier
 * InvalidArgumentException.
 */
final class ValidacionDte extends \InvalidArgumentException
{
    public function __construct(public readonly string $campo, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    public static function campo(string $campo, string $mensaje): self
    {
        return new self($campo, $mensaje);
    }
}
