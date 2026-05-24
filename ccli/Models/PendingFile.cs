namespace Ccli.Models;

public class PendingResponse
{
    public string Status { get; set; } = "";
    public string Sucursal { get; set; } = "";
    public int Pendientes { get; set; }
    public List<PendingFile> Archivos { get; set; } = [];
}

public class PendingFile
{
    public string Id { get; set; } = "";
    public string Nombre { get; set; } = "";
    public string? Br { get; set; }
    public string? Flat { get; set; }
    public int Peso { get; set; }
    public string? Ultimo_Cambio { get; set; }
}
