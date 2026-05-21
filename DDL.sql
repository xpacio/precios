CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Tabla de Usuarios
-- Almacena la información de los usuarios que interactúan con el sistema.
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY, -- Identificador único del usuario (auto-incremental)
    nombre VARCHAR(255) NOT NULL, -- Nombre completo del usuario
    nickname VARCHAR(50) UNIQUE NOT NULL, -- Nombre de usuario único para login
    password VARCHAR(255) NOT NULL, -- Contraseña del usuario (debe almacenarse hasheada)
    clavecorta VARCHAR(255), -- Clave de autorización para descargas de tipo 'desblinde'
    enabled BOOLEAN DEFAULT TRUE, -- Indica si la cuenta de usuario está activa
    can_upload BOOLEAN DEFAULT FALSE, -- Permiso para cargar archivos
    can_download BOOLEAN DEFAULT TRUE, -- Permiso para descargar archivos
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Fecha y hora de creación del registro
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- Fecha y hora de la última actualización (requeriría un trigger para auto-actualización)
);

-- Tabla de API Keys
CREATE TABLE api_keys (
    id SERIAL PRIMARY KEY,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    descripcion VARCHAR(255),
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Sucursales
-- Almacena la información de las sucursales.
CREATE TABLE sucursales (
    id_sucursal VARCHAR(5) PRIMARY KEY, -- Identificador único de la sucursal (ej. 'S001', 'S002')
    nombre_sucursal VARCHAR(100) NOT NULL, -- Nombre descriptivo de la sucursal
    enabled BOOLEAN DEFAULT TRUE, -- Indica si la sucursal está activa
    sync BOOLEAN DEFAULT FALSE, -- Indica si la sucursal está configurada para sincronización
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- Requiere trigger para auto-actualización
);

-- Tabla de Archivos
-- Almacena el registro de los archivos cargados y sus metadatos.
CREATE TABLE archivos (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(), -- Identificador único UUID
    nombre VARCHAR(50) NOT NULL, -- Nombre original del archivo (máximo 50 caracteres)
    ruta VARCHAR(255) NOT NULL, -- Ruta origen del archivo (identificador lógico en el cliente)
    peso BIGINT NOT NULL, -- Tamaño del archivo en bytes (hasta 20MB, BIGINT es seguro)
    md5zip CHAR(8) NOT NULL, -- Hash MD5 del archivo para verificación de integridad
    md5flat CHAR(8) NOT NULL,
    fecha_archivo TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Fecha y hora en que el archivo fue cargado (TIMESTAMP simple)
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Fecha y hora en que el archivo fue cargado (TIMESTAMP simple)
    is_desblinde BOOLEAN DEFAULT FALSE, -- Identifica si el archivo es de tipo desblinde
    usuario_que_cargo INT NOT NULL, -- ID del usuario que cargó el archivo (FK a usuarios.id)
    n_descargas INT DEFAULT 0, -- Contador de descargas del archivo
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Requiere trigger para auto-actualización
    
    -- Definición de la clave foránea para el usuario que cargó el archivo
    FOREIGN KEY (usuario_que_cargo) REFERENCES usuarios(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Tabla Intermedia para la relación Muchos-a-Muchos entre Archivos y Sucursales
-- Un archivo puede estar en muchas sucursales, y una sucursal puede tener muchos archivos.
CREATE TABLE archivo_sucursal (
    archivo_id UUID NOT NULL, -- FK a archivos.id (UUID)
    sucursal_id VARCHAR(5) NOT NULL, -- FK a sucursales.id_sucursal
    enabled BOOLEAN DEFAULT TRUE, -- Indica si este archivo está activo para esta sucursal
    sync BOOLEAN DEFAULT FALSE, -- Indica si este archivo está sincronizado para esta sucursal
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Requiere trigger para auto-actualización

    PRIMARY KEY (archivo_id, sucursal_id), -- La combinación de ambos IDs es la clave primaria
    
    -- Definición de las claves foráneas
    FOREIGN KEY (archivo_id) REFERENCES archivos(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id_sucursal) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Índices para optimizar búsquedas
-- Útil para buscar archivos por su MD5
CREATE INDEX idx_archivos_md5zip ON archivos (md5zip);
-- Útil para buscar archivos asociados a una sucursal específica
CREATE INDEX idx_archivo_sucursal_sucursal_id ON archivo_sucursal (sucursal_id);
-- Útil para buscar sucursales asociadas a un archivo específico
CREATE INDEX idx_archivo_sucursal_archivo_id ON archivo_sucursal (archivo_id);
-- Útil para buscar usuarios por nickname
CREATE UNIQUE INDEX idx_usuarios_nickname ON usuarios (nickname);

-- Usuario inicial de administración: admin / admin123
INSERT INTO usuarios (nombre, nickname, password, clavecorta, enabled, can_upload, can_download)
VALUES ('Administrador', 'admin', '$2y$12$lNtGKteJ95QtMhcCbt9G2O0gTdnS92qxKfgBY4c99bD2dADwQ9.uS', '1234', TRUE, TRUE, TRUE);

-- API Key inicial para el administrador
INSERT INTO api_keys (api_key, descripcion, usuario_id, enabled)
VALUES ('precios_api_key_2024', 'API Key por defecto del administrador', 1, TRUE);

-- Sucursales iniciales
INSERT INTO sucursales (id_sucursal, nombre_sucursal, enabled, sync) VALUES
('S001', 'Sucursal Central', TRUE, TRUE),
('S002', 'Sucursal Norte', TRUE, FALSE),
('S003', 'Sucursal Sur', TRUE, FALSE);
