using System.Text.Json.Serialization;

namespace Ccli.Models;

public class PendingResponse
{
    [JsonPropertyName("status")]
    public string Status { get; set; } = "";

    [JsonPropertyName("sucursal")]
    public string Sucursal { get; set; } = "";

    [JsonPropertyName("pendientes")]
    public int Pendientes { get; set; }

    [JsonPropertyName("archivos")]
    public List<PendingFile> Archivos { get; set; } = [];
}

public class PendingFile
{
    [JsonPropertyName("id")]
    public int Id { get; set; }

    [JsonPropertyName("nombre")]
    public string Nombre { get; set; } = "";

    [JsonPropertyName("br")]
    public string? Br { get; set; }

    [JsonPropertyName("flat")]
    public string? Flat { get; set; }

    [JsonPropertyName("peso")]
    public int Peso { get; set; }

    [JsonPropertyName("ultimo_cambio")]
    public string? UltimoCambio { get; set; }
}

public class ConfirmRequest
{
    [JsonPropertyName("sucursal_id")]
    public string SucursalId { get; set; } = "";

    [JsonPropertyName("nombre")]
    public string Nombre { get; set; } = "";
}
