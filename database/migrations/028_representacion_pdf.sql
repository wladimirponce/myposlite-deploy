-- Representación impresa de los DTE: bajo el recuadro va "S.I.I. - <unidad regional>" del domicilio del emisor.
ALTER TABLE dte_emisores ADD COLUMN IF NOT EXISTS unidad_sii VARCHAR(40) NULL AFTER comuna;
