const std = @import("std");

const Allocator = std.mem.Allocator;

const has_brotli = true;

const cc = std.builtin.CallingConvention.c;

const extern_fns = struct {
    extern fn BrotliDecoderCreateInstance(
        a: ?*const fn (*anyopaque, usize) callconv(cc) ?*anyopaque,
        b: ?*const fn (*anyopaque, ?*anyopaque) callconv(cc) void,
        c: ?*anyopaque,
    ) callconv(cc) ?*anyopaque;
    extern fn BrotliDecoderDecompressStream(
        a: ?*anyopaque, b: *usize, c: *[*c]const u8,
        d: *usize, e: *[*c]u8, f: ?*usize,
    ) callconv(cc) c_int;
    extern fn BrotliDecoderDestroyInstance(a: ?*anyopaque) callconv(cc) void;

    extern fn fopen(path: [*:0]const u8, mode: [*:0]const u8) callconv(cc) ?*anyopaque;
    extern fn fread(buf: *anyopaque, size: usize, count: usize, f: *anyopaque) callconv(cc) usize;
    extern fn fwrite(buf: *const anyopaque, size: usize, count: usize, f: *anyopaque) callconv(cc) usize;
    extern fn fclose(f: *anyopaque) callconv(cc) c_int;
    extern fn rename(old: [*:0]const u8, new: [*:0]const u8) callconv(cc) c_int;
    extern fn remove(path: [*:0]const u8) callconv(cc) c_int;
    extern fn fflush(stream: *anyopaque) callconv(cc) c_int;
    extern fn fgets(buf: [*:0]u8, size: c_int, stream: *anyopaque) callconv(cc) ?[*:0]u8;
    extern fn __acrt_iob_func(n: c_uint) callconv(cc) *anyopaque;
};

fn stderr() *anyopaque {
    return extern_fns.__acrt_iob_func(2);
}

fn debug(comptime fmt: []const u8, args: anytype) void {
    var buf: [4096]u8 = undefined;
    const msg = std.fmt.bufPrint(&buf, fmt, args) catch "(debug error)";
    _ = extern_fns.fwrite(msg.ptr, 1, msg.len, stderr());
    _ = extern_fns.fwrite("\n".ptr, 1, 1, stderr());
    _ = extern_fns.fflush(stderr());
}

fn debugInline(comptime fmt: []const u8, args: anytype) void {
    var buf: [4096]u8 = undefined;
    const msg = std.fmt.bufPrint(&buf, fmt, args) catch "(debug error)";
    _ = extern_fns.fwrite(msg.ptr, 1, msg.len, stderr());
    _ = extern_fns.fflush(stderr());
}

const BR_OK: c_int = 1;
const BR_NEED: c_int = 2;

pub fn main(init: std.process.Init.Minimal) !u8 {
    const allocator = std.heap.c_allocator;

    const args_slice = try std.process.Args.toSlice(init.args, allocator);
    defer allocator.free(args_slice);
    debug("zcli iniciado, args={any}", .{args_slice});

    debug("Inicializando Io.Threaded...", .{});
    var threaded = std.Io.Threaded.init(allocator, .{ .environ = init.environ });
    defer threaded.deinit();
    const io = threaded.io();
    debug("Io.Threaded OK", .{});

    debug("Inicializando HTTP client...", .{});
    var client = std.http.Client{ .allocator = allocator, .io = io };
    defer client.deinit();
    debug("HTTP client OK", .{});

    debug("Leyendo config...", .{});
    const config: Config = readConfig() catch cfg: {
        debug("readConfig: no encontrado, iniciando setup interactivo", .{});
        break :cfg try setupConfig(allocator);
    };
    defer {
        allocator.free(config.api_base_url);
        allocator.free(config.api_key);
        allocator.free(config.sucursal_id);
    }
    debug("Config OK: url={s}, key={s}, sid={s}", .{ config.api_base_url, config.api_key, config.sucursal_id });

    debug("Iniciando sync batch...", .{});
    const result = runSync(&client, allocator, &config) catch |err| {
        debug("Sync failed: {s}", .{@errorName(err)});
        return 1;
    };
    debug("Exit code: {}", .{result});
    return result;
}

const Config = struct {
    api_base_url: []u8,
    api_key: []u8,
    sucursal_id: []u8,
};

const PendingFile = struct {
    nombre: []u8,
};

const config_paths = [_][:0]const u8{
    "appsettings.json",
    "/var/www/precios/ccli/appsettings.json",
};

fn readConfig() !Config {
    const allocator = std.heap.c_allocator;
    for (config_paths) |path| {
        debug("readConfig: intentando {s}", .{path});
        const content = readFile(path) catch {
            debug("readConfig: {s} no encontrado", .{path});
            continue;
        };
        defer allocator.free(content);
        debug("readConfig: {s} leido ({d} bytes)", .{ path, content.len });

        var tree = std.json.parseFromSlice(std.json.Value, allocator, content, .{}) catch {
            debug("readConfig: {s} JSON invalido", .{path});
            continue;
        };
        defer tree.deinit();
        debug("readConfig: {s} JSON parseado OK", .{path});

        const root = tree.value.object;
        const cfg = Config{
            .api_base_url = try allocator.dupe(u8, if (root.get("ApiBaseUrl")) |v| v.string else "http://precios.servicios.care"),
            .api_key = try allocator.dupe(u8, if (root.get("ApiKey")) |v| v.string else "precios_api_key_2024"),
            .sucursal_id = try allocator.dupe(u8, if (root.get("SucursalId")) |v| v.string else ""),
        };
        debug("readConfig: OK desde {s}", .{path});
        return cfg;
    }
    debug("readConfig: todos los paths fallaron", .{});
    return error.ConfigNotFound;
}

fn readFile(path: [:0]const u8) ![]u8 {
    const f = extern_fns.fopen(path, "rb") orelse return error.FileOpenFailed;
    defer _ = extern_fns.fclose(f);

    const allocator = std.heap.c_allocator;
    var buf = try allocator.alloc(u8, 65536);
    var total: usize = 0;

    while (true) {
        if (total + 4096 > buf.len) buf = try allocator.realloc(buf, buf.len * 2);
        const n = extern_fns.fread(buf.ptr + total, 1, buf.len - total, f);
        total += n;
        if (n < buf.len - total) break;
    }
    return buf[0..total];
}

fn writeFile(path: []const u8, data: []const u8) !void {
    debug("writeFile: {s} ({d} bytes)", .{ path, data.len });
    const path_z = try std.heap.c_allocator.dupeZ(u8, path);
    defer std.heap.c_allocator.free(path_z);
    const f = extern_fns.fopen(path_z, "wb") orelse return error.FileOpenFailed;
    defer _ = extern_fns.fclose(f);
    const written = extern_fns.fwrite(data.ptr, 1, data.len, f);
    if (written != data.len) return error.WriteFailed;
    debug("writeFile: {s} escrito OK", .{path});
}

fn readLine(buf: []u8) ![]const u8 {
    const r = extern_fns.fgets(@as([*:0]u8, @ptrCast(buf.ptr)), @intCast(buf.len), extern_fns.__acrt_iob_func(0));
    if (r == null) return error.Eof;
    return std.mem.trim(u8, std.mem.sliceTo(r.?, 0), " \n\r");
}

fn setupConfig(allocator: Allocator) !Config {
    debugInline("appsettings.json no encontrado.", .{});

    debugInline("Codigo de sucursal: ", .{});
    var sid_buf: [64]u8 = undefined;
    const sid_line = try readLine(&sid_buf);
    if (sid_line.len == 0) return error.SucursalRequired;
    const sucursal_id = try allocator.dupe(u8, sid_line);

    debugInline("Desea configurar valores personalizados? (s/N): ", .{});
    var yn_buf: [8]u8 = undefined;
    const yn_line = readLine(&yn_buf) catch "";
    const custom = yn_line.len > 0 and (yn_line[0] == 's' or yn_line[0] == 'S');

    const def_url = "http://precios.servicios.care";
    const def_key = "precios_api_key_2024";

    const api_base_url = if (custom) blk: {
        debugInline("ApiBaseUrl [{s}]: ", .{def_url});
        var url_buf: [256]u8 = undefined;
        const url_line = readLine(&url_buf) catch def_url;
        break :blk try allocator.dupe(u8, if (url_line.len > 0) url_line else def_url);
    } else try allocator.dupe(u8, def_url);

    const api_key = if (custom) blk: {
        debugInline("ApiKey [{s}]: ", .{def_key});
        var key_buf: [256]u8 = undefined;
        const key_line = readLine(&key_buf) catch def_key;
        break :blk try allocator.dupe(u8, if (key_line.len > 0) key_line else def_key);
    } else try allocator.dupe(u8, def_key);

    const json = try std.fmt.allocPrint(allocator, "{{\n  \"ApiBaseUrl\": \"{s}\",\n  \"ApiKey\": \"{s}\",\n  \"SucursalId\": \"{s}\"\n}}\n", .{ api_base_url, api_key, sucursal_id });
    defer allocator.free(json);

    writeFile("appsettings.json", json) catch |err| {
        debug("Error escribiendo appsettings.json: {s}", .{@errorName(err)});
        return error.ConfigSetupFailed;
    };

    debug("appsettings.json creado.", .{});
    return Config{ .api_base_url = api_base_url, .api_key = api_key, .sucursal_id = sucursal_id };
}

fn httpHeader(name: []const u8, value: []const u8) std.http.Header {
    return .{ .name = name, .value = value };
}

fn fetchPending(client: *std.http.Client, allocator: Allocator, config: *const Config) ![]PendingFile {
    const url = try std.fmt.allocPrint(allocator, "{s}/api/v1/pending/{s}", .{ config.api_base_url, config.sucursal_id });
    defer allocator.free(url);

    const auth = httpHeader("X-API-Key", config.api_key);
    const headers = [_]std.http.Header{auth};
    var redirect_buf: [4096]u8 = undefined;
    var response_buf: [65536]u8 = undefined;
    var fw = std.Io.Writer.fixed(&response_buf);

    debug("fetchPending: GET {s}", .{url});
    const result = client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        debug("fetchPending: ERROR {s}", .{@errorName(err)});
        return err;
    };
    debug("fetchPending: status={s} ({d} bytes)", .{ @tagName(result.status.class()), fw.end });

    if (result.status.class() != .success) return error.HttpError;

    const body = response_buf[0..fw.end];
    debug("fetchPending: body={s}", .{body});

    var tree = std.json.parseFromSlice(std.json.Value, allocator, body, .{}) catch |err| {
        debug("fetchPending: JSON parse ERROR {s}", .{@errorName(err)});
        return err;
    };
    defer tree.deinit();

    const root = tree.value.object;
    const archivos = root.get("archivos") orelse return error.MissingArchivos;
    const arr = archivos.array;
    var files = try allocator.alloc(PendingFile, arr.items.len);
    for (arr.items, 0..) |item, i| {
        const nombre = try allocator.dupe(u8, item.object.get("nombre").?.string);
        files[i] = .{ .nombre = nombre };
    }
    debug("fetchPending: {d} archivos pendientes", .{files.len});
    return files;
}

fn downloadFile(client: *std.http.Client, allocator: Allocator, config: *const Config, nombre: []const u8) ![]u8 {
    const url = try std.fmt.allocPrint(allocator, "{s}/api/v1/serve/{s}/{s}", .{ config.api_base_url, config.sucursal_id, nombre });
    defer allocator.free(url);

    const auth = httpHeader("X-API-Key", config.api_key);
    const headers = [_]std.http.Header{auth};
    var redirect_buf: [4096]u8 = undefined;
    var response_buf: [5242880]u8 = undefined;
    var fw = std.Io.Writer.fixed(&response_buf);

    debug("  downloadFile: GET {s}", .{url});
    const result = client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        debug("  downloadFile: ERROR {s}", .{@errorName(err)});
        return err;
    };
    if (result.status.class() != .success) return error.HttpError;

    const data = try allocator.dupe(u8, response_buf[0..fw.end]);
    debug("  downloadFile: OK ({d} bytes)", .{data.len});
    return data;
}

fn confirmDownload(client: *std.http.Client, allocator: Allocator, config: *const Config, nombre: []const u8, result_type: []const u8) !void {
    const url = try std.fmt.allocPrint(allocator, "{s}/api/v1/confirm", .{config.api_base_url});
    defer allocator.free(url);

    const body = try std.fmt.allocPrint(allocator, "{{\"sucursal_id\":\"{s}\",\"nombre\":\"{s}\",\"resultado\":\"{s}\"}}", .{ config.sucursal_id, nombre, result_type });
    defer allocator.free(body);

    const auth = httpHeader("X-API-Key", config.api_key);
    const content_type = httpHeader("Content-Type", "application/json");
    const headers = [_]std.http.Header{ content_type, auth };
    var redirect_buf: [4096]u8 = undefined;
    var resp_buf: [1024]u8 = undefined;
    var fw = std.Io.Writer.fixed(&resp_buf);

    debug("  confirmDownload: POST {s} body={s}", .{ url, body });
    const result = client.fetch(.{
        .location = .{ .url = url },
        .method = .POST,
        .payload = body,
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        debug("  confirmDownload: ERROR {s}", .{@errorName(err)});
        return err;
    };
    if (result.status.class() != .success) {
        debug("  confirmDownload: status={s}", .{@tagName(result.status.class())});
        return error.HttpError;
    }
    debug("  confirmDownload: OK", .{});
}

fn computeXxh3Hex(data: []const u8) [6]u8 {
    var h = std.hash.XxHash3.init(0);
    h.update(data);
    var hex: [16]u8 = undefined;
    const s = std.fmt.bufPrint(&hex, "{x}", .{h.final()}) catch "000000";
    var result: [6]u8 = undefined;
    for (s[0..6], 0..) |c, i| result[i] = c;
    return result;
}

fn decompressBrotli(input: []const u8, allocator: Allocator) ![]u8 {
    if (!has_brotli) return error.BrotliNotAvailable;

    debug("  decompressBrotli: iniciando ({d} bytes)", .{input.len});
    const d = extern_fns.BrotliDecoderCreateInstance(null, null, null) orelse return error.BrotliInit;
    defer extern_fns.BrotliDecoderDestroyInstance(d);

    var list = std.array_list.Managed(u8).init(allocator);
    defer list.deinit();

    var avail_in: usize = input.len;
    var next_in: [*c]const u8 = input.ptr;
    var buf: [8192]u8 = undefined;

    while (true) {
        var avail_out: usize = buf.len;
        var next_out: [*c]u8 = &buf;
        const rc = extern_fns.BrotliDecoderDecompressStream(d, &avail_in, &next_in, &avail_out, &next_out, null);
        if (avail_out < buf.len) try list.appendSlice(buf[0 .. buf.len - avail_out]);
        if (rc == BR_OK) break;
        if (rc == BR_NEED and avail_in == 0) return error.UnexpectedEnd;
        if (rc != BR_NEED) return error.BrotliError;
    }
    const result = try list.toOwnedSlice();
    debug("  decompressBrotli: OK ({d} -> {d} bytes, {d:0.1}%)", .{ input.len, result.len, @as(f64, @floatFromInt(result.len)) / @as(f64, @floatFromInt(input.len)) * 100 });
    return result;
}

fn runSync(client: *std.http.Client, allocator: Allocator, config: *const Config) !u8 {
    debug("=== Sync iniciado - Sucursal: {s} ===", .{config.sucursal_id});

    const files = fetchPending(client, allocator, config) catch |err| {
        debug("Error fetching pending: {s}", .{@errorName(err)});
        return 1;
    };
    defer {
        for (files) |f| allocator.free(f.nombre);
        allocator.free(files);
    }

    debug("Archivos pendientes: {d}", .{files.len});

    var ok_count: u32 = 0;
    var fail_count: u32 = 0;

    for (files, 1..) |file, i| {
        debugInline("[{d}/{d}] {s} ... ", .{ i, files.len, file.nombre });

        const data = downloadFile(client, allocator, config, file.nombre) catch |err| {
            debug("ERROR download: {s}", .{@errorName(err)});
            fail_count += 1;
            continue;
        };
        defer allocator.free(data);

        const hash6 = computeXxh3Hex(data);
        debug("  xxh3: {s}", .{hash6});

        const is_br = std.mem.endsWith(u8, file.nombre, ".br");
        const output_name = if (is_br) file.nombre[0 .. file.nombre.len - 3] else file.nombre;

        if (is_br) {
            const decompressed = decompressBrotli(data, allocator) catch |err| {
                debug("ERROR decompress: {s}", .{@errorName(err)});
                fail_count += 1;
                continue;
            };
            defer allocator.free(decompressed);

            const dbf_hash6 = computeXxh3Hex(decompressed);
            debug("  xxh3 (dbf): {s}", .{dbf_hash6});

            writeFile(output_name, decompressed) catch |err| {
                debug("ERROR write: {s}", .{@errorName(err)});
                fail_count += 1;
                continue;
            };
        } else {
            writeFile(output_name, data) catch |err| {
                debug("ERROR write: {s}", .{@errorName(err)});
                fail_count += 1;
                continue;
            };
        }

        confirmDownload(client, allocator, config, file.nombre, "ok") catch |err| {
            debug("WARN confirm: {s}", .{@errorName(err)});
        };

        debug("OK", .{});
        ok_count += 1;
    }

    debug("=== Sync completado: {} OK, {} fallos ===", .{ ok_count, fail_count });
    if (fail_count > 0) return 1;
    return 0;
}

fn runInteractive(client: *std.http.Client, allocator: Allocator, config: *const Config) !u8 {
    _ = allocator;
    _ = client;
    _ = config;
    debug("runInteractive: no implementado en modo batch", .{});
    return 0;
}
