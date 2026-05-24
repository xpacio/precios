using System.Text.Json.Serialization;
using Ccli.Models;

[JsonSerializable(typeof(Dictionary<string, string>))]
[JsonSerializable(typeof(PendingResponse))]
[JsonSerializable(typeof(PendingFile))]
[JsonSerializable(typeof(ConfirmRequest))]
public partial class AppJsonContext : JsonSerializerContext
{
}
