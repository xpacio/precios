# Nice to have

## Errores descriptivos en zcli

Cuando una descarga falla, el cliente muestra `[!] error-br` sin detalle del motivo real (404, 403, hash mismatch, timeout, etc.). El servidor devuelve un mensaje de error en el body HTTP, pero el cliente no lo captura ni lo muestra.

### Mejora propuesta

- En `downloadFile()` de zcli, cuando `result.status.class() != .success`, leer el body de la respuesta HTTP (que contiene el mensaje de error del servidor, ej. "ERROR: Archivo comprimido no encontrado") e incluirlo en el mensaje de error.
- Propagar ese mensaje hasta la salida al usuario, ej:
  ```
  [1/4] ARCERO.DBF ... descarga exitosa, error al confirmar con central. error #JHKA
  [2/4] LISTA.DBF ... error 404: Archivo comprimido no encontrado
  ```
- También capturar errores de `confirmDownload()` (POST a confirm.php) que actualmente se tragan con `catch {}` sin mostrar nada.
- En lugar de `[!] error-br` genérico, mostrar el código de error HTTP y el body:
  ```
  [!] HTTP 404 - Archivo comprimido no encontrado
  [!] HTTP 403 - Clave DBD incorrecta
  [!] error-br: hash mismatch tras 5 intentos
  ```
- En `downloadGroup()`, usar un `summary_lines` compartido (como hace `processFiles()`) para mostrar los errores de cada archivo, en lugar del `summary_dummy` por iteración que se crea y se libera sin imprimirse.

### Archivos involucrados

- `zcli/src/main.zig`: funciones `downloadFile()`, `tryDownloadWithRetry()`, `processFile()`, `downloadGroup()`
- `api/v1/serve.php`: ya incluye mensajes en el body HTTP (OK)
- `api/v1/confirm.php`: idem

### Prioridad

Baja — no bloquea funcionalidad, solo mejora la experiencia de depuración.
