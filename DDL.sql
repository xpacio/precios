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
    id SERIAL PRIMARY KEY,
    ruta VARCHAR(500) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    peso BIGINT NOT NULL DEFAULT 0,
    flat CHAR(6) DEFAULT '',
    br CHAR(6) DEFAULT '',
    xxh3 CHAR(6),

    comprimido BOOLEAN DEFAULT TRUE,
    status VARCHAR(10) DEFAULT 'ready',
    enabled BOOLEAN DEFAULT FALSE,
    n_descargas INT DEFAULT 0,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_archivo TIMESTAMP,
    compr_pct INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    compr_pct INT DEFAULT NULL,
    fecha_archivo TIMESTAMP DEFAULT NULL,
    UNIQUE (ruta, nombre)
);

CREATE TABLE archivo_sucursal (
    archivo_id INT NOT NULL REFERENCES archivos(id) ON DELETE CASCADE,
    sucursal_id VARCHAR(5) NOT NULL REFERENCES sucursales(id_sucursal),
    nombre VARCHAR(255) NOT NULL,
    es_desblinde BOOLEAN DEFAULT FALSE,
    enabled BOOLEAN DEFAULT TRUE,
    sync BOOLEAN DEFAULT FALSE,
    ultimo_resultado VARCHAR(14) NOT NULL DEFAULT 'pending',
    n_envios INT DEFAULT 0,
    n_exitos INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (archivo_id, sucursal_id),
    UNIQUE (sucursal_id, nombre, es_desblinde)
);

CREATE INDEX idx_archivo_sucursal_sucursal_id ON archivo_sucursal (sucursal_id);
CREATE INDEX idx_archivo_sucursal_archivo_id ON archivo_sucursal (archivo_id);
CREATE UNIQUE INDEX idx_usuarios_nickname ON usuarios (nickname);

INSERT INTO usuarios (nombre, nickname, password, clavecorta, enabled, can_upload, can_download)
VALUES ('Administrador', 'admin', '$2y$12$lNtGKteJ95QtMhcCbt9G2O0gTdnS92qxKfgBY4c99bD2dADwQ9.uS', '1234', TRUE, TRUE, TRUE);

INSERT INTO api_keys (api_key, descripcion, usuario_id, enabled)
VALUES ('precios_api_key_2024', 'API Key por defecto del administrador', 1, TRUE);

INSERT INTO sucursales (id_sucursal, nombre_sucursal, enabled) VALUES
('00000', 'Sucursal de Pruebas', TRUE);

CREATE TABLE cli_log (
    id SERIAL PRIMARY KEY,
    sucursal_id VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(10) NOT NULL,
    api_key_id INT REFERENCES api_keys(id),
    usuario_id INT REFERENCES usuarios(id),
    ip_address VARCHAR(45),
    status VARCHAR(10) DEFAULT 'ok',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX cli_log_sucursal_idx ON cli_log(sucursal_id);
CREATE INDEX cli_log_created_idx ON cli_log(created_at);
CREATE INDEX cli_log_type_idx ON cli_log(file_type);

CREATE TABLE sync_log (
    id SERIAL PRIMARY KEY,
    mode VARCHAR(20) NOT NULL,
    params VARCHAR(100) DEFAULT '',
    status VARCHAR(10) NOT NULL,
    total INT DEFAULT 0,
    transferidos INT DEFAULT 0,
    procesados INT DEFAULT 0,
    omitidos INT DEFAULT 0,
    errores INT DEFAULT 0,
    exit_code INT DEFAULT 0,
    duration_sec NUMERIC(8,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
