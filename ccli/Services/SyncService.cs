using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Ccli.Models;

namespace Ccli.Services;

public class SyncService(ApiClient api, string sucursalId)
{
    public async Task RunSyncAsync()
    {
        Console.WriteLine($"=== Sync automático - Sucursal: {sucursalId} ===\n");

        var files = await api.GetPendingAsync(sucursalId);
        if (files.Count == 0)
        {
            Console.WriteLine("No hay archivos pendientes.");
            return;
        }

        Console.WriteLine($"Archivos pendientes: {files.Count}\n");

        int ok = 0, fail = 0;

        foreach (var f in files)
        {
            Console.Write($"{f.Nombre,-25} ");

            try
            {
                await DownloadAndConfirm(f);
                Console.WriteLine("✓ OK");
                ok++;
            }
            catch (IOException) when (IsLocked())
            {
                Console.WriteLine("⚠ BLOQUEADO - saltando");
                fail++;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"✗ ERROR: {ex.Message}");
                fail++;
            }
        }

        Console.WriteLine($"\n=== Resumen: {ok} OK, {fail} fallos ===");
    }

    public async Task RunInteractiveAsync()
    {
        Console.WriteLine($"=== Sincronización interactiva - Sucursal: {sucursalId} ===\n");

        var files = await api.GetPendingAsync(sucursalId);
        if (files.Count == 0)
        {
            Console.WriteLine("No hay archivos pendientes. Todo sincronizado.");
            return;
        }

        Console.WriteLine("Archivos pendientes:");
        Console.WriteLine(new string('-', 60));
        Console.WriteLine($"{"#",-4} {"Archivo",-25} {"MD5",-10} {"Peso",-10}");
        Console.WriteLine(new string('-', 60));

        for (int i = 0; i < files.Count; i++)
        {
            var peso = files[i].Peso switch
            {
                < 1024 => $"{files[i].Peso} B",
                < 1024 * 1024 => $"{files[i].Peso / 1024} KB",
                _ => $"{files[i].Peso / (1024 * 1024)} MB"
            };
            Console.WriteLine($"{i + 1,-4} {files[i].Nombre,-25} {files[i].Md5zip ?? "-",-10} {peso,-10}");
        }

        Console.WriteLine();

        while (true)
        {
            Console.Write("Seleccione archivo (número), T=todos, S=salir: ");
            var line = Console.ReadLine();
            if (line == null) break;
            var input = line.Trim().ToLower();

            if (input == "s") break;

            if (input == "t")
            {
                await DownloadAllInteractive(files);
                break;
            }

            if (int.TryParse(input, out int idx) && idx >= 1 && idx <= files.Count)
            {
                await DownloadOneInteractive(files[idx - 1]);
                files = await api.GetPendingAsync(sucursalId);
                if (files.Count == 0)
                {
                    Console.WriteLine("\nNo quedan archivos pendientes.");
                    break;
                }
            }
            else
            {
                Console.WriteLine("Opción inválida.");
            }
        }
    }

    private async Task DownloadAllInteractive(List<PendingFile> files)
    {
        foreach (var f in files)
        {
            Console.Write($"\n{f.Nombre,-25} ");

            try
            {
                await DownloadAndConfirm(f);
                Console.WriteLine("✓ OK");
            }
            catch (IOException) when (IsLocked())
            {
                Console.WriteLine("⚠ BLOQUEADO");
                Console.Write("  [R] Reintentar  [C] Continuar  [S] Salir: ");
                var r = Console.ReadLine()?.Trim().ToLower();
                if (r == "r") { try { await DownloadAndConfirm(f); Console.WriteLine("  ✓ OK"); } catch (Exception ex2) { Console.WriteLine($"  ✗ {ex2.Message}"); } }
                else if (r == "s") break;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"✗ ERROR: {ex.Message}");
            }
        }
    }

    private async Task DownloadOneInteractive(PendingFile f)
    {
        while (true)
        {
            try
            {
                await DownloadAndConfirm(f);
                Console.WriteLine("✓ Descargado y confirmado.");
                return;
            }
            catch (IOException) when (IsLocked())
            {
                Console.Write("Archivo bloqueado. [R] Reintentar  [C] Continuar  [S] Salir: ");
                var r = Console.ReadLine()?.Trim().ToLower();
                if (r == "c" || r == "s") return;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"Error: {ex.Message}");
                return;
            }
        }
    }

    private async Task DownloadAndConfirm(PendingFile f)
    {
        var destDir = Directory.GetCurrentDirectory();
        var destPath = Path.Combine(destDir, f.Nombre);

        // 1. Download .br to temp
        var (remoteMd5zip, remoteMd5flat, stream) = await api.DownloadFileAsync(sucursalId, f.Nombre);

        var tmpBrPath = Path.GetTempFileName();
        try
        {
            await using (var fs = File.Create(tmpBrPath))
                await stream.CopyToAsync(fs);
        }
        catch
        {
            File.Delete(tmpBrPath);
            throw;
        }

        try
        {
            // 2. Verify md5zip (MD5 of .br)
            var brMd5 = GetMd58(tmpBrPath);
            if (remoteMd5zip != null && !brMd5.Equals(remoteMd5zip, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException($"MD5zip mismatch: esperado {remoteMd5zip}, local {brMd5}");

            // 3. Decompress Brotli to temp .DBF
            var tmpDbfPath = Path.GetTempFileName();
            try
            {
                await using (var brStream = File.OpenRead(tmpBrPath))
                await using (var dbfStream = File.Create(tmpDbfPath))
                using (var brotli = new BrotliStream(brStream, CompressionMode.Decompress))
                    await brotli.CopyToAsync(dbfStream);

                // 4. Verify md5flat (MD5 of .DBF)
                var dbfMd5 = GetMd58(tmpDbfPath);
                if (remoteMd5flat != null && !dbfMd5.Equals(remoteMd5flat, StringComparison.OrdinalIgnoreCase))
                    throw new InvalidDataException($"MD5flat mismatch: esperado {remoteMd5flat}, local {dbfMd5}");

                // 5. Rename existing .DBF with consecutive suffix
                if (File.Exists(destPath))
                {
                    int seq = 1;
                    while (File.Exists($"{destPath}.{seq}")) seq++;
                    File.Move(destPath, $"{destPath}.{seq}");
                }

                // 6. Move decompressed .DBF to destination
                File.Move(tmpDbfPath, destPath);
            }
            finally
            {
                if (File.Exists(tmpDbfPath)) File.Delete(tmpDbfPath);
            }
        }
        finally
        {
            if (File.Exists(tmpBrPath)) File.Delete(tmpBrPath);
        }

        // 7. Confirm
        await api.ConfirmAsync(sucursalId, f.Nombre);
    }

    private static string GetMd58(string path)
    {
        using var md5 = MD5.Create();
        using var stream = File.OpenRead(path);
        var hash = md5.ComputeHash(stream);
        return Convert.ToHexString(hash)[..8].ToLower();
    }

    private static bool IsLocked()
    {
        return true;
    }
}