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
    extern fn fgets(buf: [*:0]u8, size: c_int, stream: *anyopaque) callconv(cc) ?[*:0]u8;
    extern fn __acrt_iob_func(n: c_uint) callconv(cc) *anyopaque;
};

const BR_OK: c_int = 1;
const BR_NEED: c_int = 2;

pub fn main(init: std.process.Init.Minimal) !u8 {
    const allocator = std.heap.c_allocator;
    const args_slice = try std.process.Args.toSlice(init.args, allocator);
    defer allocator.free(args_slice);

    var threaded = std.Io.Threaded.init(allocator, .{ .environ = init.environ });
    defer threaded.deinit();
    const io = threaded.io();

    var client = std.http.Client{ .allocator = allocator, .io = io };
    defer client.deinit();

    const config = readConfig() catch |err| {
        std.debug.print("Error: appsettings.json: {s}\n", .{@errorName(err)});
        return 1;
    };
    defer {
        allocator.free(config.api_base_url);
        allocator.free(config.api_key);
        allocator.free(config.sucursal_id);
    }

    const mode: enum { sync, interactive } = if (args_slice.len > 1 and
        std.ascii.eqlIgnoreCase(args_slice[1], "sync")) .sync else .interactive;

    return switch (mode) {
        .sync => runSync(&client, allocator, &config),
        .interactive => runInteractive(&client, allocator, &config),
    };
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
        const content = readFile(path) catch continue;
        defer allocator.free(content);

        var tree = std.json.parseFromSlice(std.json.Value, allocator, content, .{}) catch continue;
        defer tree.deinit();

        const root = tree.value.object;
        return Config{
            .api_base_url = try allocator.dupe(u8, if (root.get("ApiBaseUrl")) |v| v.string else "http://precios.servicios.care"),
            .api_key = try allocator.dupe(u8, if (root.get("ApiKey")) |v| v.string else "precios_api_key_2024"),
            .sucursal_id = try allocator.dupe(u8, if (root.get("SucursalId")) |v| v.string else ""),
        };
    }
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
    const path_z = try std.heap.c_allocator.dupeZ(u8, path);
    defer std.heap.c_allocator.free(path_z);
    const f = extern_fns.fopen(path_z, "wb") orelse return error.FileOpenFailed;
    defer _ = extern_fns.fclose(f);
    const written = extern_fns.fwrite(data.ptr, 1, data.len, f);
    if (written != data.len) return error.WriteFailed;
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

    const result = try client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    });
    if (result.status.class() != .success) return error.HttpError;

    const body = response_buf[0..fw.end];
    var tree = try std.json.parseFromSlice(std.json.Value, allocator, body, .{});
    defer tree.deinit();

    const arr = tree.value.array;
    var files = try allocator.alloc(PendingFile, arr.items.len);
    for (arr.items, 0..) |item, i| {
        const nombre = try allocator.dupe(u8, item.object.get("nombre").?.string);
        files[i] = .{ .nombre = nombre };
    }
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

    const result = try client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    });
    if (result.status.class() != .success) return error.HttpError;

    return try allocator.dupe(u8, response_buf[0..fw.end]);
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

    const result = try client.fetch(.{
        .location = .{ .url = url },
        .method = .POST,
        .payload = body,
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    });
    if (result.status.class() != .success) return error.HttpError;
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
    return list.toOwnedSlice();
}

fn runSync(client: *std.http.Client, allocator: Allocator, config: *const Config) !u8 {
    const files = fetchPending(client, allocator, config) catch |err| {
        std.debug.print("Error fetching pending: {s}\n", .{@errorName(err)});
        return 1;
    };
    defer {
        for (files) |f| allocator.free(f.nombre);
        allocator.free(files);
    }

    std.debug.print("Descargas pendientes: {d}\n", .{files.len});

    for (files) |file| {
        std.debug.print("  {s} ... ", .{file.nombre});

        const data = downloadFile(client, allocator, config, file.nombre) catch |err| {
            std.debug.print("ERROR download: {s}\n", .{@errorName(err)});
            continue;
        };
        defer allocator.free(data);

        const is_br = std.mem.endsWith(u8, file.nombre, ".br");
        const output_name = if (is_br) file.nombre[0 .. file.nombre.len - 3] else file.nombre;

        if (is_br) {
            const decompressed = decompressBrotli(data, allocator) catch |err| {
                std.debug.print("ERROR decompress: {s}\n", .{@errorName(err)});
                continue;
            };
            defer allocator.free(decompressed);

            writeFile(output_name, decompressed) catch |err| {
                std.debug.print("ERROR write: {s}\n", .{@errorName(err)});
                continue;
            };
        } else {
            writeFile(output_name, data) catch |err| {
                std.debug.print("ERROR write: {s}\n", .{@errorName(err)});
                continue;
            };
        }

        confirmDownload(client, allocator, config, file.nombre, "ok") catch |err| {
            std.debug.print("WARN confirm: {s}\n", .{@errorName(err)});
        };

        std.debug.print("OK\n", .{});
    }

    std.debug.print("Listo\n", .{});
    return 0;
}

fn runInteractive(client: *std.http.Client, allocator: Allocator, config: *const Config) !u8 {
    const files = fetchPending(client, allocator, config) catch |err| {
        std.debug.print("Error fetching pending: {s}\n", .{@errorName(err)});
        return 1;
    };
    defer {
        for (files) |f| allocator.free(f.nombre);
        allocator.free(files);
    }

    if (files.len == 0) {
        std.debug.print("No hay descargas pendientes.\n", .{});
        return 0;
    }

    std.debug.print("Archivos pendientes ({d}):\n", .{files.len});
    for (files, 1..) |file, i| {
        std.debug.print("  {d}. {s}\n", .{ i, file.nombre });
    }

    var c_line: [64]u8 = undefined;
    while (true) {
        std.debug.print("Opcion [1-{d}, a=all, q=quit]: ", .{files.len});
        const r = extern_fns.fgets(@as([*:0]u8, @ptrCast(&c_line)), @intCast(c_line.len), extern_fns.__acrt_iob_func(0));
        if (r == null) return 0;
        const line = std.mem.trim(u8, std.mem.sliceTo(r.?, 0), " \n\r");
        if (line.len == 0) continue;

        if (std.mem.eql(u8, line, "q")) break;
        if (std.mem.eql(u8, line, "a")) {
            for (files) |file| {
                std.debug.print("Procesando {s}... ", .{file.nombre});
                processFile(client, allocator, config, file.nombre) catch |err| {
                    std.debug.print("ERROR: {s}\n", .{@errorName(err)});
                };
            }
            std.debug.print("OK\n", .{});
            continue;
        }

        const num = std.fmt.parseInt(usize, line, 10) catch continue;
        if (num < 1 or num > files.len) continue;
        std.debug.print("Procesando {s}... ", .{files[num - 1].nombre});
        processFile(client, allocator, config, files[num - 1].nombre) catch |err| {
            std.debug.print("ERROR: {s}\n", .{@errorName(err)});
        };
        std.debug.print("OK\n", .{});
    }
    return 0;
}

fn processFile(client: *std.http.Client, allocator: Allocator, config: *const Config, nombre: []const u8) !void {
    const data = try downloadFile(client, allocator, config, nombre);
    defer allocator.free(data);

    const is_br = std.mem.endsWith(u8, nombre, ".br");
    const output_name = if (is_br) nombre[0 .. nombre.len - 3] else nombre;

    if (is_br) {
        const decompressed = try decompressBrotli(data, allocator);
        defer allocator.free(decompressed);
        try writeFile(output_name, decompressed);
    } else {
        try writeFile(output_name, data);
    }

    try confirmDownload(client, allocator, config, nombre, "ok");
}
