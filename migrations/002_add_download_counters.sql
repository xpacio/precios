ALTER TABLE archivos          ADD COLUMN IF NOT EXISTS n_descargas   INT DEFAULT 0;
ALTER TABLE archivo_sucursal  ADD COLUMN IF NOT EXISTS n_envios      INT DEFAULT 0;
ALTER TABLE archivo_sucursal  ADD COLUMN IF NOT EXISTS n_exitos      INT DEFAULT 0;
ALTER TABLE archivo_sucursal  ADD COLUMN IF NOT EXISTS ultimo_resultado VARCHAR(14) NOT NULL DEFAULT 'pending';
