using System.Net.Http.Json;
using System.Text.Json;
using Ccli.Models;

namespace Ccli.Services;

public class ApiClient(HttpClient http, string apiKey)
{
    public async Task<List<PendingFile>> GetPendingAsync(string sucursalId)
    {
        var req = new HttpRequestMessage(HttpMethod.Get, $"/api/v1/pending/{sucursalId}");
        req.Headers.Add("X-API-Key", apiKey);

        var res = await http.SendAsync(req);
        res.EnsureSuccessStatusCode();

        var body = await res.Content.ReadFromJsonAsync<PendingResponse>(
            new JsonSerializerOptions { PropertyNameCaseInsensitive = true }
        );

        return body?.Archivos ?? [];
    }

    public async Task<(string? Md5zip, string? Md5flat, Stream Stream)> DownloadFileAsync(string sucursalId, string nombre)
    {
        var req = new HttpRequestMessage(HttpMethod.Get, $"/api/v1/serve/{sucursalId}/{Uri.EscapeDataString(nombre)}");
        req.Headers.Add("X-API-Key", apiKey);

        var res = await http.SendAsync(req, HttpCompletionOption.ResponseHeadersRead);
        res.EnsureSuccessStatusCode();

        var md5zip = res.Headers.TryGetValues("Md5zip", out var zipValues) ? zipValues.FirstOrDefault() : null;
        var md5flat = res.Headers.TryGetValues("Md5flat", out var flatValues) ? flatValues.FirstOrDefault() : null;
        var stream = await res.Content.ReadAsStreamAsync();

        return (md5zip, md5flat, stream);
    }

    public async Task<bool> ConfirmAsync(string sucursalId, string nombre)
    {
        var req = new HttpRequestMessage(HttpMethod.Post, "/api/v1/confirm");
        req.Headers.Add("X-API-Key", apiKey);
        req.Content = JsonContent.Create(new { sucursal_id = sucursalId, nombre });

        var res = await http.SendAsync(req);
        return res.IsSuccessStatusCode;
    }
}
