#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <curl/curl.h>

/**
 * Cliente Descargador básico en C para la API de Precios.
 * Compilación: gcc client_downloader.c -lcurl -o downloader
 */

struct Config {
    const char *url;
    const char *dest_file;
    const char *local_md5;
    const char *clave_corta;
    int is_desblinde;
};

// Estructura para capturar metadatos de la respuesta
struct ResponseMetadata {
    char server_md5[9];
    int is_no_change;
    FILE *fp;
};

// Callback para procesar las cabeceras de respuesta del servidor
static size_t header_callback(char *buffer, size_t size, size_t nitems, void *userdata) {
    size_t numbytes = size * nitems;
    struct ResponseMetadata *meta = (struct ResponseMetadata *)userdata;

    // Buscamos la cabecera md5zip: enviada por el controlador download.php
    if (strncasecmp(buffer, "md5zip:", 7) == 0) {
        sscanf(buffer + 7, "%s", meta->server_md5);
    }
    return numbytes;
}

// Callback para escribir el cuerpo de la respuesta (el archivo o el status)
static size_t write_callback(void *ptr, size_t size, size_t nmemb, void *userdata) {
    size_t numbytes = size * nmemb;
    struct ResponseMetadata *meta = (struct ResponseMetadata *)userdata;

    // Si el servidor responde con "STATUS: SIN_CAMBIOS", marcamos la bandera y no escribimos a archivo
    if (numbytes >= 18 && memcmp(ptr, "STATUS: SIN_CAMBIOS", 18) == 0) {
        meta->is_no_change = 1;
        return numbytes;
    }

    // Si no es un mensaje de estado, escribimos los bytes en el archivo temporal
    if (meta->fp) {
        return fwrite(ptr, size, nmemb, meta->fp);
    }
    return numbytes;
}

int download_file(struct Config config) {
    CURL *curl;
    CURLcode res;
    struct ResponseMetadata meta = { .server_md5 = "", .is_no_change = 0, .fp = NULL };
    struct curl_slist *headers = NULL;
    char tmp_file[256];

    snprintf(tmp_file, sizeof(tmp_file), "%s.tmp", config.dest_file);

    curl_global_init(CURL_GLOBAL_ALL);
    curl = curl_easy_init();

    if (curl) {
        // 1. Abrir archivo temporal para escritura binaria
        meta.fp = fopen(tmp_file, "wb");
        if (!meta.fp) {
            fprintf(stderr, "Error: No se pudo crear el archivo temporal %s\n", tmp_file);
            return -1;
        }

        // 2. Configurar cabeceras de petición
        char buf[256];
        if (config.local_md5) {
            snprintf(buf, sizeof(buf), "MD5: %s", config.local_md5);
            headers = curl_slist_append(headers, buf);
        }
        if (config.is_desblinde) {
            headers = curl_slist_append(headers, "IS-DESBLINDE: 1");
            if (config.clave_corta) {
                snprintf(buf, sizeof(buf), "CLAVECORTA: %s", config.clave_corta);
                headers = curl_slist_append(headers, buf);
            }
        }

        // 3. Configurar cURL
        curl_easy_setopt(curl, CURLOPT_URL, config.url);
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, write_callback);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &meta);
        curl_easy_setopt(curl, CURLOPT_HEADERFUNCTION, header_callback);
        curl_easy_setopt(curl, CURLOPT_HEADERDATA, &meta);

        // 4. Ejecutar
        printf("Conectando a %s...\n", config.url);
        res = curl_easy_perform(curl);

        fclose(meta.fp);

        if (res != CURLE_OK) {
            fprintf(stderr, "Error de red: %s\n", curl_easy_strerror(res));
            remove(tmp_file);
        } else {
            long http_code = 0;
            curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &http_code);

            if (http_code == 200) {
                if (meta.is_no_change) {
                    printf("Aviso: El archivo ya está actualizado (SIN_CAMBIOS).\n");
                    remove(tmp_file);
                } else {
                    printf("Descarga completada. MD5 Servidor: %s\n", meta.server_md5);
                    
                    // En un caso real, aquí calcularíamos el MD5 del .tmp y compararíamos con meta.server_md5
                    printf("Verificando integridad...\n");

                    // Simulación de validación y renombrado
                    if (remove(config.dest_file) == 0 || !fopen(config.dest_file, "r")) {
                        if (rename(tmp_file, config.dest_file) == 0) {
                            printf("ÉXITO: Archivo actualizado a %s\n", config.dest_file);
                        } else {
                            perror("Error al renombrar archivo");
                        }
                    }
                }
            } else {
                fprintf(stderr, "Error del servidor (HTTP %ld)\n", http_code);
                // Imprimir cuerpo del error si es texto
                remove(tmp_file);
            }
        }

        curl_slist_free_all(headers);
        curl_easy_cleanup(curl);
    }

    curl_global_cleanup();
    return 0;
}

int main() {
    struct Config cfg;
    
    // Ejemplo de descarga normal
    cfg.url = "http://localhost/api/v1/download/S001/precios.zip";
    cfg.dest_file = "precios.zip";
    cfg.local_md5 = "e99a18c4"; // MD5 del archivo que ya tenemos
    cfg.is_desblinde = 0;
    cfg.clave_corta = NULL;

    printf("--- Iniciando descarga normal ---\n");
    download_file(cfg);

    // Ejemplo de descarga desblinde
    /*
    cfg.url = "http://localhost/api/v1/download/S001/secreto.zip";
    cfg.dest_file = "secreto.zip";
    cfg.local_md5 = ""; 
    cfg.is_desblinde = 1;
    cfg.clave_corta = "1234";
    printf("\n--- Iniciando descarga desblinde ---\n");
    download_file(cfg);
    */

    return 0;
}