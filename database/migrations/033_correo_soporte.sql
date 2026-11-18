-- Avisos de ayuda en línea: al equipo de soporte cuando llega un pedido y a quien lo pidió cuando soporte responde.
-- Van a usuarios de Lite (no a destinatarios de cierre), por eso no esperan la verificación de destinatarios.
ALTER TABLE correo_envios MODIFY tipo ENUM('verificacion', 'documento', 'tramite', 'cierre', 'soporte') NOT NULL;
