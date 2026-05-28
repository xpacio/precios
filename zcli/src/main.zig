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
    extern fn time(t: ?*i64) callconv(cc) i64;
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

    var threaded = std.Io.Threaded.init(allocator, .{ .environ = init.environ });
    defer threaded.deinit();
    const io = threaded.io();

    var client = std.http.Client{ .allocator = allocator, .io = io };
    defer client.deinit();

    const config: Config = readConfig() catch cfg: {
        break :cfg try setupConfig(allocator);
    };
    defer {
        allocator.free(config.api_base_url);
        allocator.free(config.api_key);
        allocator.free(config.sucursal_id);
    }

    const result = runSync(&client, allocator, &config) catch |err| {
        debug("Sync failed: {s}", .{@errorName(err)});
        return 1;
    };
    return result;
}

const Config = struct {
    api_base_url: []u8,
    api_key: []u8,
    sucursal_id: []u8,
};

const PendingFile = struct {
    nombre: []u8,
    ruta: []u8,
    flat: []u8,
    br: []u8,
    peso: u64,
    ultimo_cambio: []u8,
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

    writeFile("appsettings.json", json) catch {
        return error.ConfigSetupFailed;
    };

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

    const result = client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        return err;
    };

    if (result.status.class() != .success) return error.HttpError;

    const body = response_buf[0..fw.end];

    var tree = std.json.parseFromSlice(std.json.Value, allocator, body, .{}) catch |err| {
        return err;
    };
    defer tree.deinit();

    const root = tree.value.object;
    const archivos = root.get("archivos") orelse return error.MissingArchivos;
    const arr = archivos.array;
    var files = try allocator.alloc(PendingFile, arr.items.len);
    for (arr.items, 0..) |item, i| {
        const obj = item.object;
        const nombre = try allocator.dupe(u8, obj.get("nombre").?.string);
        const ruta = try allocator.dupe(u8, if (obj.get("ruta")) |v| v.string else "");
        const flat = try allocator.dupe(u8, if (obj.get("flat")) |v| v.string else "");
        const br = try allocator.dupe(u8, if (obj.get("br")) |v| v.string else "");
        const peso: u64 = if (obj.get("peso")) |v| @intCast(v.integer) else 0;
        const uc = try allocator.dupe(u8, if (obj.get("ultimo_cambio")) |v| v.string else "");
        files[i] = .{
            .nombre = nombre, .ruta = ruta, .flat = flat, .br = br,
            .peso = peso, .ultimo_cambio = uc,
        };
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

    const result = client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        return err;
    };
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

    const result = client.fetch(.{
        .location = .{ .url = url },
        .method = .POST,
        .payload = body,
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        return err;
    };
    if (result.status.class() != .success) return error.HttpError;
}

fn formatFullHash(data: []const u8) [9]u8 {
    var h = std.hash.XxHash3.init(0);
    h.update(data);
    const val = h.final();
    const hi: u32 = @truncate(val >> 32);
    const lo: u32 = @truncate(val);
    var buf: [32]u8 = undefined;
    const s = std.fmt.bufPrint(&buf, "{d}.{d}", .{ hi, lo }) catch "0.0";
    var result: [9]u8 = [9]u8{ ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ' };
    for (s[0..@min(s.len, 9)], 0..) |c, i| result[i] = c;
    return result;
}

fn parseTimestampEpoch(ts: []const u8) i64 {
    if (ts.len < 10) return 0;
    const year = std.fmt.parseInt(i64, ts[0..4], 10) catch return 0;
    const month = std.fmt.parseInt(i64, ts[5..7], 10) catch return 0;
    const day = std.fmt.parseInt(i64, ts[8..10], 10) catch return 0;

    var total_days: i64 = 0;
    var y: i64 = 1970;
    while (y < year) : (y += 1) {
        total_days += if (std.time.epoch.isLeapYear(@intCast(y))) 366 else 365;
    }
    var m: i64 = 1;
    while (m < month) : (m += 1) {
        const mon: std.time.epoch.Month = @enumFromInt(@as(u4, @intCast(m)));
        total_days += std.time.epoch.getDaysInMonth(@intCast(year), mon);
    }
    total_days += day - 1;

    var seconds = total_days * 86400;
    if (ts.len >= 19) {
        const hour = std.fmt.parseInt(i64, ts[11..13], 10) catch 0;
        const min = std.fmt.parseInt(i64, ts[14..16], 10) catch 0;
        const sec = std.fmt.parseInt(i64, ts[17..19], 10) catch 0;
        seconds += hour * 3600 + min * 60 + sec;
    }
    return seconds;
}

fn computeAge(ts: []const u8) [8]u8 {
    var result: [8]u8 = [8]u8{ ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ' };
    if (ts.len == 0) return result;

    const ts_epoch = parseTimestampEpoch(ts);
    if (ts_epoch == 0) return result;

    const now = extern_fns.time(null);
    const diff = now - ts_epoch;
    if (diff < 0) return result;

    const days = @divTrunc(diff, 86400);
    const years = @divTrunc(days, 365);
    const hours = @divTrunc(diff, 3600);
    const minutes = @divTrunc(diff, 60);

    var buf: [16]u8 = undefined;
    const s = if (years >= 1)
        std.fmt.bufPrint(&buf, "{d}a+", .{years}) catch return result
    else if (days >= 1)
        std.fmt.bufPrint(&buf, "{d}d+", .{days}) catch return result
    else if (hours >= 1)
        std.fmt.bufPrint(&buf, "{d}h+", .{hours}) catch return result
    else
        std.fmt.bufPrint(&buf, "{d}m+", .{minutes + 1}) catch return result;

    for (s[0..@min(s.len, 8)], 0..) |c, i| result[i] = c;
    return result;
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
    return try list.toOwnedSlice();
}

fn runSync(client: *std.http.Client, allocator: Allocator, config: *const Config) !u8 {
    const files = fetchPending(client, allocator, config) catch |err| {
        debug("Error fetching pending: {s}", .{@errorName(err)});
        return 1;
    };
    defer {
        for (files) |f| {
            allocator.free(f.nombre);
            allocator.free(f.ruta);
            allocator.free(f.flat);
            allocator.free(f.br);
            allocator.free(f.ultimo_cambio);
        }
        allocator.free(files);
    }

    if (files.len == 0) {
        debug("No hay archivos pendientes", .{});
        return 0;
    }

    var ok_count: u32 = 0;
    var fail_count: u32 = 0;

    for (files) |file| {
        const is_br = std.mem.endsWith(u8, file.nombre, ".br");
        const output_name = if (is_br) file.nombre[0 .. file.nombre.len - 3] else file.nombre;

        const origin_path = if (file.ruta.len > 0)
            try std.fmt.allocPrint(allocator, "{s}/{s}", .{ file.ruta, output_name })
        else
            try allocator.dupe(u8, output_name);

        var status: u8 = '+';

        var existing_data: ?[]u8 = null;
        defer if (existing_data) |d| allocator.free(d);

        {
            const local_path_z = try std.heap.c_allocator.dupeZ(u8, origin_path);
            defer std.heap.c_allocator.free(local_path_z);
            const local_f = extern_fns.fopen(local_path_z, "rb");
            if (local_f) |lf| {
                defer _ = extern_fns.fclose(lf);
                var lbuf: [65536]u8 = undefined;
                const n = extern_fns.fread(&lbuf, 1, lbuf.len, lf);
                if (n > 0) {
                    existing_data = try allocator.dupe(u8, lbuf[0..n]);
                }
            }
        }

        const data = downloadFile(client, allocator, config, file.nombre) catch |err| {
            debug("[{c}] {s} ERROR download: {s}", .{ status, origin_path, @errorName(err) });
            allocator.free(origin_path);
            fail_count += 1;
            continue;
        };

        const br_full = formatFullHash(data);

        if (is_br) {
            const decompressed = decompressBrotli(data, allocator) catch |err| {
                debug("[{c}] {s} ERROR decompress: {s}", .{ status, origin_path, @errorName(err) });
                allocator.free(origin_path);
                allocator.free(data);
                fail_count += 1;
                continue;
            };

            const dbf_full = formatFullHash(decompressed);

            if (existing_data) |ed| {
                if (decompressed.len == ed.len) {
                    const ed_hash = formatFullHash(ed);
                    if (std.mem.eql(u8, &dbf_full, &ed_hash)) status = '=';
                }
            }

            writeFile(output_name, decompressed) catch |err| {
                debug("[{c}] {s} ERROR write: {s}", .{ status, origin_path, @errorName(err) });
                allocator.free(origin_path);
                allocator.free(data);
                allocator.free(decompressed);
                fail_count += 1;
                continue;
            };

            const age = computeAge(file.ultimo_cambio);
            debug("[{c}] {s} {s} {s} {s} ({s}) - {s} ({s})", .{
                status, origin_path,
                file.flat, file.br,
                dbf_full, age,
                br_full, age,
            });

            allocator.free(decompressed);
        } else {
            const dbf_full = br_full;

            if (existing_data) |ed| {
                if (data.len == ed.len) {
                    const ed_hash = formatFullHash(ed);
                    if (std.mem.eql(u8, &dbf_full, &ed_hash)) status = '=';
                }
            }

            writeFile(output_name, data) catch |err| {
                debug("[{c}] {s} ERROR write: {s}", .{ status, origin_path, @errorName(err) });
                allocator.free(origin_path);
                allocator.free(data);
                fail_count += 1;
                continue;
            };

            const age = computeAge(file.ultimo_cambio);
            debug("[{c}] {s} {s} {s} {s} ({s}) - {s} ({s})", .{
                status, origin_path,
                file.flat, file.br,
                dbf_full, age,
                br_full, age,
            });
        }

        confirmDownload(client, allocator, config, file.nombre, "ok") catch {};

        allocator.free(origin_path);
        allocator.free(data);
        ok_count += 1;
    }

    debug("=== Sync completado: {} OK, {} fallos ===", .{ ok_count, fail_count });
    if (fail_count > 0) return 1;
    return 0;
}
