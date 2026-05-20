#include <stdio.h>
#include <string.h>
#include <curl/curl.h>

/**
 * Cliente Cargador básico en C para la API de Precios.
 * Compilación: gcc client_uploader.c -lcurl -o uploader
 */

int main(int argc, char *argv[]) {
    CURL *curl;
    CURLcode res;

    // Parámetros de ejemplo (En una app real vendrían de argumentos o config)
    const char *url = "http://localhost/api/v1/upload/S001/archivo_prueba.zip";
    const char *filepath = "archivo_prueba.zip"; // El archivo debe existir localmente
    
    // Metadatos para los Headers
    const char *h_nombre = "archivo_prueba.zip";
    const char *h_ruta = "C:\\MisDocumentos\\Precios\\";
    const char *h_md5zip = "e99a18c4"; // Solo 8 caracteres según DDL
    const char *h_md5flat = "a1b2c3d4";
    const char *h_fecha = "2023-10-27 10:00:00";
    const char *h_is_desblinde = "0"; // "1" para desblinde

    curl_global_init(CURL_GLOBAL_ALL);
    curl = curl_easy_init();

    if (curl) {
        curl_mime *mime;
        curl_mimepart *part;
        struct curl_slist *headers = NULL;

        // 1. Configurar Headers personalizados
        char buf[256];
        
        snprintf(buf, sizeof(buf), "NOMBRE: %s", h_nombre);
        headers = curl_slist_append(headers, buf);
        
        snprintf(buf, sizeof(buf), "RUTA: %s", h_ruta);
        headers = curl_slist_append(headers, buf);
        
        snprintf(buf, sizeof(buf), "MD5ZIP: %s", h_md5zip);
        headers = curl_slist_append(headers, buf);
        
        snprintf(buf, sizeof(buf), "MD5FLAT: %s", h_md5flat);
        headers = curl_slist_append(headers, buf);
        
        snprintf(buf, sizeof(buf), "FECHA-ARCHIVO: %s", h_fecha);
        headers = curl_slist_append(headers, buf);
        
        snprintf(buf, sizeof(buf), "IS-DESBLINDE: %s", h_is_desblinde);
        headers = curl_slist_append(headers, buf);

        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);

        // 2. Configurar la URL
        curl_easy_setopt(curl, CURLOPT_URL, url);

        // 3. Crear el formulario multipart (equivalente a $_FILES en PHP)
        mime = curl_mime_init(curl);
        part = curl_mime_addpart(mime);
        
        // El nombre del campo debe ser "archivo" tal como lo busca el PHP
        curl_mime_name(part, "archivo");
        curl_mime_filedata(part, filepath);

        curl_easy_setopt(curl, CURLOPT_MIMEPOST, mime);

        // 4. Ejecutar la petición
        printf("Subiendo archivo a %s...\n", url);
        res = curl_easy_perform(curl);

        // 5. Verificar errores y respuesta
        if (res != CURLE_OK) {
            fprintf(stderr, "Error en la petición: %s\n", curl_easy_strerror(res));
        } else {
            long response_code;
            curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &response_code);
            printf("\nHTTP Status: %ld\n", response_code);
            printf("Respuesta del servidor:\n----------------\n");
            // La respuesta se imprimirá directamente en consola (es text/plain)
        }

        // Limpiar recursos
        curl_easy_cleanup(curl);
        curl_mime_free(mime);
        curl_slist_free_all(headers);
    }

    curl_global_cleanup();
    return 0;
}
