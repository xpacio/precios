"# precios" 

Cargador de archivos desde sync 
•	Cargador general 
•	Cargador por carpeta
cada archivo conserva su ruta 
Cada archivo se relaciona a una sucursal 
Requisito: manejador de carga 
Carga 
•	Recibe archivos temporales
•	Evalúa el md5
•	Lo mueve a destino 
•	Actualiza la base de datos

Modificar lanzador ZX001 para que abra un exe

Exe muestra menu
•	Descargar lista de precios 
•	Descargar todos los archivos de lista
•	Descargar desblinde*

*Está opción pide clave.
La clave la manda como cabecera de la petición 

La petición hace un get al servidor web, descarga el archivo.ext.tmp y en cabecera el md5 
Termina la descarga
Evalúa md5 
Si correcto borra archivo destino 
Renombra temp a destino 
Muestra mensaje 
Si error muestra error 
Repite por cada archivo si es el caso.

Pagina web permite 
Ver 
•	lista de archivos cargados 
•	peso
•	Fecha de mod.
•	cambiar la clave de descarga 
•	Modificar la asociación con una tienda 
