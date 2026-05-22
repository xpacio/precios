using Microsoft.Extensions.Configuration;

var config = new ConfigurationBuilder()
    .SetBasePath(AppContext.BaseDirectory)
    .AddJsonFile("appsettings.json", optional: false)
    .Build();

string apiBaseUrl = config["ApiBaseUrl"]!;
string apiKey = config["ApiKey"]!;
string sucursalId = config["SucursalId"]!;

var http = new HttpClient { BaseAddress = new Uri(apiBaseUrl) };
http.DefaultRequestHeaders.Add("X-API-Key", apiKey);

bool menuMode = args.Length > 0 && args[0] == "menu";

if (menuMode)
    await MenuMode(http, sucursalId);
else
    await FullSync(http, sucursalId);

static async Task FullSync(HttpClient http, string sucursal)
{
    Console.WriteLine("Descargando todos los archivos...\n");

    var files = await FetchFileList(http, sucursal);
    if (files is null || files.Count == 0)
    {
        Console.WriteLine("No hay archivos disponibles.");
        return;
    }

    int ok = 0, err = 0;
    foreach (var f in files)
    {
        if (f.Status != "ready")
        {
            Console.WriteLine($"  {f.Nombre} - SALTADO (no listo)");
            continue;
        }

        Console.Write($"  {f.Nombre} ... ");
        if (await DownloadFile(http, sucursal, f.Nombre))
        {
            Console.WriteLine("OK");
            ok++;
        }
        else
        {
            Console.WriteLine("ERROR");
            err++;
        }
    }

    Console.WriteLine($"\nDescarga completada: {ok} OK, {err} errores");
}

static async Task MenuMode(HttpClient http, string sucursal)
{
    var files = await FetchFileList(http, sucursal);
    if (files is null || files.Count == 0)
    {
        Console.WriteLine("No hay archivos disponibles.");
        return;
    }

    while (true)
    {
        Console.Clear();
        Console.WriteLine($"Sucursal: {sucursal}  |  {files.Count} archivo(s)\n");

        for (int i = 0; i < files.Count; i++)
        {
            var f = files[i];
            string icon = f.Status == "ready" ? " " : "#";
            Console.WriteLine($"  {i + 1,2}. [{icon}] {f.Nombre}");
        }

        Console.WriteLine($"\n  a. Todos");
        Console.WriteLine($"  0. Salir");
        Console.Write("\nSeleccione archivo(s) (ej: 1, 1 3 5, a): ");
        string? input = Console.ReadLine()?.Trim().ToLower();

        if (string.IsNullOrEmpty(input) || input == "0")
            break;

        List<int> indices = [];

        if (input is "a" or "all" or "t" or "todos")
        {
            indices = [.. Enumerable.Range(0, files.Count)];
        }
        else
        {
            foreach (var p in input.Split(new[] { ' ', ',', ';' }, StringSplitOptions.RemoveEmptyEntries))
            {
                if (int.TryParse(p, out int n) && n >= 1 && n <= files.Count)
                    indices.Add(n - 1);
            }
            indices = indices.Distinct().ToList();
        }

        if (indices.Count == 0)
        {
            Console.Write("Seleccion no valida. Presione Enter...");
            Console.ReadLine();
            continue;
        }

        Console.WriteLine($"\nDescargando {indices.Count} archivo(s)...\n");

        int ok = 0, err = 0;
        foreach (int idx in indices)
        {
            var f = files[idx];
            if (f.Status != "ready")
            {
                Console.WriteLine($"  {f.Nombre} - SALTADO (no listo)");
                continue;
            }

            Console.Write($"  {f.Nombre} ... ");
            if (await DownloadFile(http, sucursal, f.Nombre))
            {
                Console.WriteLine("OK");
                ok++;
            }
            else
            {
                Console.WriteLine("ERROR");
                err++;
            }
        }

        Console.WriteLine($"\n  {ok} OK, {err} errores");
        Console.Write("\nPresione Enter para continuar...");
        Console.ReadLine();
    }
}

static async Task<List<FileInfo>?> FetchFileList(HttpClient http, string sucursal)
{
    var resp = await http.GetAsync($"/api/v1/files/{sucursal}");
    if (!resp.IsSuccessStatusCode)
    {
        Console.WriteLine($"Error al obtener lista: {resp.StatusCode}");
        return null;
    }

    string text = await resp.Content.ReadAsStringAsync();
    var files = new List<FileInfo>();

    string currentName = "", currentStatus = "";
    foreach (string line in text.Split('\n'))
    {
        string trimmed = line.Trim();
        if (trimmed.StartsWith("NOMBRE: "))
            currentName = trimmed["NOMBRE: ".Length..];
        else if (trimmed.StartsWith("STATUS: ") && !trimmed.StartsWith("STATUS: OK"))
            currentStatus = trimmed["STATUS: ".Length..];
        else if (trimmed == "---")
        {
            if (!string.IsNullOrEmpty(currentName))
                files.Add(new FileInfo(currentName, currentStatus));
            currentName = "";
            currentStatus = "";
        }
    }

    return files;
}

static async Task<bool> DownloadFile(HttpClient http, string sucursal, string fileName)
{
    try
    {
        var resp = await http.GetAsync($"/api/v1/download/{sucursal}/{fileName}");
        if (!resp.IsSuccessStatusCode)
            return false;

        await using var stream = await resp.Content.ReadAsStreamAsync();
        await using var fs = File.Create(fileName);
        await stream.CopyToAsync(fs);
        return true;
    }
    catch
    {
        return false;
    }
}

record FileInfo(string Nombre, string Status);
