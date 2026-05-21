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
    public string? Md5zip { get; set; }
    public string? Md5flat { get; set; }
    public int Peso { get; set; }
    public string? UltimoCambio { get; set; }
}
