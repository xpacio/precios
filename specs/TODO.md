# TODO

## Compression (Brotli) - verify.php
- [ ] Detectar .DBF nuevo/cambiado → calcular `md5flat`
- [ ] Crear `{nombre}.br` con Brotli (máxima compresión)
- [ ] Calcular `md5zip` del .br
- [ ] Actualizar DB: `md5flat`, `md5zip`, `peso` (tamaño .br), `ausente=FALSE`
- [ ] Marcar `sync=FALSE` en todas las asociaciones del archivo
- [ ] Si falta .DBF → `ausente=TRUE`

## Serve - serve.php
- [ ] Servir `{nombre}.br` (application/octet-stream)
- [ ] Headers: `Md5zip`, `Md5flat`
- [ ] Verificar que la asociación sucursal→archivo exista y esté enabled

## Pending - pending.php
- [ ] Incluir `md5flat` en JSON response

## Cliente C# - SyncService.cs
- [ ] Descargar archivo .br a temp
- [ ] Verificar MD5 del .br descargado vs `md5zip`
- [ ] Descomprimir Brotli (.br → .DBF)
- [ ] Verificar MD5 del .DBF extraído vs `md5flat`
- [ ] Eliminar .br temporal, renombrar .DBF viejo (.1, .2...), mover nuevo
- [ ] Llamar confirm

## Cliente C# - PendingFile.cs
- [ ] Agregar propiedad `Md5flat`

## Cliente C# - ApiClient.cs
- [ ] Pasar `Md5flat` header en respuesta de serve
- [ ] Enviar `md5flat` en confirm payload

## Asociaciones
- [ ] Determinar región por sucursal
- [ ] Crear asociaciones masivas para sucursales reales

## Distribución
- [ ] Publicar cliente Windows: `dotnet publish -r win-x64 --self-contained`
- [ ] Probar cliente en sucursal real
