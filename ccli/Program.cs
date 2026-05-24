using System.Text.Json;
using Ccli.Services;

var configPath = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
if (!File.Exists(configPath))
    configPath = Path.Combine(Directory.GetCurrentDirectory(), "appsettings.json");

if (!File.Exists(configPath))
{
    Console.Error.WriteLine("Error: No se encuentra appsettings.json");
    return 1;
}

var json = File.ReadAllText(configPath);
var config = JsonSerializer.Deserialize(json, AppJsonContext.Default.DictionaryStringString);
if (config == null)
{
    Console.Error.WriteLine("Error: appsettings.json inválido");
    return 1;
}

var apiBaseUrl = config.GetValueOrDefault("ApiBaseUrl", "http://precios.servicios.care");
var apiKey = config.GetValueOrDefault("ApiKey", "precios_api_key_2024");
var sucursalId = config.GetValueOrDefault("SucursalId", "");

if (string.IsNullOrEmpty(sucursalId))
{
    Console.Error.WriteLine("Error: SucursalId no configurado en appsettings.json");
    return 1;
}

var http = new HttpClient { BaseAddress = new Uri(apiBaseUrl) };
var api = new ApiClient(http, apiKey);
var sync = new SyncService(api, sucursalId);

try
{
    if (args.Length > 0 && args[0].ToLower() == "sync")
    {
        await sync.RunSyncAsync();
    }
    else
    {
        await sync.RunInteractiveAsync();
    }
}
catch (HttpRequestException ex)
{
    Console.Error.WriteLine($"\nError de conexión: {ex.Message}");
    return 1;
}
catch (Exception ex)
{
    Console.Error.WriteLine($"\nError inesperado: {ex.Message}");
    return 1;
}

return 0;
