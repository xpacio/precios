CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    nickname VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    clavecorta VARCHAR(255),
    enabled BOOLEAN DEFAULT TRUE,
    can_upload BOOLEAN DEFAULT FALSE,
    can_download BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE api_keys (
    id SERIAL PRIMARY KEY,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    descripcion VARCHAR(255),
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sucursales (
    id_sucursal VARCHAR(5) PRIMARY KEY,
    nombre_sucursal VARCHAR(100) NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE archivos (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    path VARCHAR(255) NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    peso BIGINT DEFAULT 0,
    md5zip CHAR(8),
    md5flat CHAR(8),
    ausente BOOLEAN DEFAULT TRUE,
    ultimo_cambio TIMESTAMP,
    fecha_archivo TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_desblinde BOOLEAN DEFAULT FALSE,
    usuario_que_cargo INT REFERENCES usuarios(id),
    n_descargas INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (path, nombre)
);

CREATE TABLE archivo_sucursal (
    archivo_id UUID NOT NULL REFERENCES archivos(id) ON DELETE RESTRICT,
    sucursal_id VARCHAR(5) NOT NULL REFERENCES sucursales(id_sucursal) ON DELETE RESTRICT,
    nombre VARCHAR(50) NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,
    sync BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (archivo_id, sucursal_id),
    UNIQUE (sucursal_id, nombre)
);

CREATE INDEX idx_archivos_md5zip ON archivos (md5zip);
CREATE INDEX idx_archivo_sucursal_sucursal_id ON archivo_sucursal (sucursal_id);
CREATE INDEX idx_archivo_sucursal_archivo_id ON archivo_sucursal (archivo_id);
CREATE UNIQUE INDEX idx_usuarios_nickname ON usuarios (nickname);

INSERT INTO usuarios (nombre, nickname, password, clavecorta, enabled, can_upload, can_download)
VALUES ('Administrador', 'admin', '$2y$12$lNtGKteJ95QtMhcCbt9G2O0gTdnS92qxKfgBY4c99bD2dADwQ9.uS', '1234', TRUE, TRUE, TRUE);

INSERT INTO api_keys (api_key, descripcion, usuario_id, enabled)
VALUES ('precios_api_key_2024', 'API Key por defecto del administrador', 1, TRUE);
