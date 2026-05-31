const std = @import("std");

const Allocator = std.mem.Allocator;

const has_brotli = true;

var g_verbose: bool = false;

const cc = std.builtin.CallingConvention.c;

const Stat64 = extern struct {
    st_dev: u32,
    st_ino: u16,
    st_mode: u16,
    st_nlink: i16,
    st_uid: i16,
    st_gid: i16,
    st_rdev: u32,
    st_size: i64,
    st_atime: i64,
    st_mtime: i64,
    st_ctime: i64,
};

const Utimbuf = extern struct {
    actime: i64,
    modtime: i64,
};

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
    extern fn _stat64(path: [*:0]const u8, buf: *Stat64) callconv(cc) c_int;
    extern fn _kbhit() callconv(cc) c_int;
    extern fn _getch() callconv(cc) c_int;
    extern fn _utime(path: [*:0]const u8, times: ?*const Utimbuf) callconv(cc) c_int;
};

fn stderr() *anyopaque {
    return extern_fns.__acrt_iob_func(2);
}

fn stdout() *anyopaque {
    return extern_fns.__acrt_iob_func(1);
}

fn print(comptime fmt: []const u8, args: anytype) void {
    var buf: [4096]u8 = undefined;
    const msg = std.fmt.bufPrint(&buf, fmt, args) catch "(print error)";
    _ = extern_fns.fwrite(msg.ptr, 1, msg.len, stdout());
    _ = extern_fns.fwrite("\n".ptr, 1, 1, stdout());
    _ = extern_fns.fflush(stdout());
}

fn printInline(comptime fmt: []const u8, args: anytype) void {
    var buf: [4096]u8 = undefined;
    const msg = std.fmt.bufPrint(&buf, fmt, args) catch "(print error)";
    _ = extern_fns.fwrite(msg.ptr, 1, msg.len, stdout());
    _ = extern_fns.fflush(stdout());
}


fn debug(comptime fmt: []const u8, args: anytype) void {
    if (!g_verbose) return;
    var buf: [4096]u8 = undefined;
    const msg = std.fmt.bufPrint(&buf, fmt, args) catch "(debug error)";
    _ = extern_fns.fwrite(msg.ptr, 1, msg.len, stderr());
    _ = extern_fns.fwrite("\n".ptr, 1, 1, stderr());
    _ = extern_fns.fflush(stderr());
}

fn debugInline(comptime fmt: []const u8, args: anytype) void {
    if (!g_verbose) return;
    var buf: [4096]u8 = undefined;
    const msg = std.fmt.bufPrint(&buf, fmt, args) catch "(debug error)";
    _ = extern_fns.fwrite(msg.ptr, 1, msg.len, stderr());
    _ = extern_fns.fflush(stderr());
}

const BR_OK: c_int = 1;
const BR_NEED_INPUT: c_int = 2;
const BR_NEED_OUTPUT: c_int = 3;

pub fn main(init: std.process.Init.Minimal) !u8 {
    const allocator = std.heap.c_allocator;

    const args_slice = try std.process.Args.toSlice(init.args, allocator);
    defer allocator.free(args_slice);

    for (args_slice) |arg| {
        if (std.mem.eql(u8, arg, "-v")) {
            g_verbose = true;
        }
    }

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
    ruta: []u8,
    flat: []u8,
    br: []u8,
    peso: u64,
    ultimo_cambio: []u8,
    fecha_archivo: []u8,
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

fn fetchFiles(client: *std.http.Client, allocator: Allocator, config: *const Config) ![]PendingFile {
    const url = try std.fmt.allocPrint(allocator, "{s}/api/v1/pending/{s}", .{ config.api_base_url, config.sucursal_id });
    defer allocator.free(url);

    const auth = httpHeader("X-API-Key", config.api_key);
    const headers = [_]std.http.Header{auth};
    var redirect_buf: [4096]u8 = undefined;
    var response_buf: [65536]u8 = undefined;
    var fw = std.Io.Writer.fixed(&response_buf);

    debug("fetchFiles: GET {s}", .{url});
    const result = client.fetch(.{
        .location = .{ .url = url },
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        debug("fetchFiles: ERROR {s}", .{@errorName(err)});
        return err;
    };
    debug("fetchFiles: status={s} ({d} bytes)", .{ @tagName(result.status.class()), fw.end });

    if (result.status.class() != .success) return error.HttpError;

    const body = response_buf[0..fw.end];
    debug("fetchFiles: body={s}", .{body});

    var tree = std.json.parseFromSlice(std.json.Value, allocator, body, .{}) catch |err| {
        debug("fetchFiles: JSON parse ERROR {s}", .{@errorName(err)});
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
        const fa = try allocator.dupe(u8, if (obj.get("fecha_archivo")) |v| v.string else "");
        files[i] = .{
            .nombre = nombre, .ruta = ruta, .flat = flat, .br = br,
            .peso = peso, .ultimo_cambio = uc, .fecha_archivo = fa,
        };
    }
    debug("fetchFiles: {d} archivos en lista", .{files.len});
    return files;
}

fn downloadFile(client: *std.http.Client, allocator: Allocator, config: *const Config, nombre: []const u8, ruta: []const u8) ![]u8 {
    const url = try std.fmt.allocPrint(allocator, "{s}/api/v1/serve/{s}/{s}", .{ config.api_base_url, config.sucursal_id, nombre });
    defer allocator.free(url);

    const auth = httpHeader("X-API-Key", config.api_key);
    const ruta_hdr = httpHeader("X-Ruta", ruta);
    const headers = [_]std.http.Header{ auth, ruta_hdr };
    var redirect_buf: [4096]u8 = undefined;
    var response_buf: [10485760]u8 = undefined;
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

fn confirmBatch(client: *std.http.Client, allocator: Allocator, config: *const Config, skip_names: []const []const u8) !void {
    if (skip_names.len == 0) return;

    var body_list = std.array_list.Managed(u8).init(allocator);
    defer body_list.deinit();

    try body_list.appendSlice("{\"sucursal_id\":\"");
    try body_list.appendSlice(config.sucursal_id);
    try body_list.appendSlice("\",\"batch\":[");
    for (skip_names, 0..) |name, i| {
        if (i > 0) try body_list.appendSlice(",");
        try body_list.appendSlice("{\"nombre\":\"");
        try body_list.appendSlice(name);
        try body_list.appendSlice("\",\"resultado\":\"skip\"}");
    }
    try body_list.appendSlice("]}");

    const url = try std.fmt.allocPrint(allocator, "{s}/api/v1/confirm", .{config.api_base_url});
    defer allocator.free(url);

    const auth = httpHeader("X-API-Key", config.api_key);
    const content_type = httpHeader("Content-Type", "application/json");
    const x_batch = httpHeader("X-Batch", "true");
    const headers = [_]std.http.Header{ content_type, x_batch, auth };
    var redirect_buf: [4096]u8 = undefined;
    var resp_buf: [1024]u8 = undefined;
    var fw = std.Io.Writer.fixed(&resp_buf);

    debug("  confirmBatch: POST {s} ({d} archivos)", .{ url, skip_names.len });
    const result = client.fetch(.{
        .location = .{ .url = url },
        .method = .POST,
        .payload = body_list.items,
        .extra_headers = &headers,
        .redirect_buffer = &redirect_buf,
        .response_writer = &fw,
    }) catch |err| {
        debug("  confirmBatch: ERROR {s}", .{@errorName(err)});
        return err;
    };
    if (result.status.class() != .success) {
        debug("  confirmBatch: status={s}", .{@tagName(result.status.class())});
        return error.HttpError;
    }
    debug("  confirmBatch: OK", .{});
}

fn computeFullHashU64(data: []const u8) u64 {
    var h = std.hash.XxHash3.init(0);
    h.update(data);
    return h.final();
}

fn sleepSeconds(sec: i64) void {
    const deadline = extern_fns.time(null) + sec;
    while (extern_fns.time(null) < deadline) {}
}

fn toUpper(c: u8) u8 {
    return if (c >= 'a' and c <= 'z') c - 32 else c;
}

fn computeShortHash(data: []const u8) [4]u8 {
    var h = std.hash.XxHash3.init(0);
    h.update(data);
    const val = h.final();
    var hex_buf: [32]u8 = undefined;
    const hex_slice = std.fmt.bufPrint(&hex_buf, "{x}", .{val}) catch return .{ '0', '0', '0', '0' };
    var result: [4]u8 = .{ '0', '0', '0', '0' };
    const start = if (hex_slice.len > 4) hex_slice.len - 4 else 0;
    var i: usize = 0;
    while (start + i < hex_slice.len and i < 4) : (i += 1) {
        result[i] = toUpper(hex_slice[start + i]);
    }
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

var g_age_buf: [16]u8 = undefined;

fn computeAge(ts: []const u8) []const u8 {
    if (ts.len == 0) return "-";

    const ts_epoch = parseTimestampEpoch(ts);
    if (ts_epoch == 0) return "-";

    const now = extern_fns.time(null);
    const diff = now - ts_epoch;
    if (diff < 0) return "-";

    const days = @divTrunc(diff, 86400);
    const years = @divTrunc(days, 365);
    const hours = @divTrunc(diff, 3600);
    const minutes = @divTrunc(diff, 60);

    const s = if (years >= 1)
        std.fmt.bufPrint(&g_age_buf, "{d}a+", .{years}) catch return "-"
    else if (days >= 1)
        std.fmt.bufPrint(&g_age_buf, "{d}d+", .{days}) catch return "-"
    else if (hours >= 1)
        std.fmt.bufPrint(&g_age_buf, "{d}h+", .{hours}) catch return "-"
    else
        std.fmt.bufPrint(&g_age_buf, "{d}m+", .{minutes + 1}) catch return "-";

    return s;
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
        if (rc == BR_NEED_INPUT and avail_in == 0) return error.UnexpectedEnd;
        if (rc == BR_NEED_INPUT or rc == BR_NEED_OUTPUT) continue;
        return error.BrotliError;
    }
    const result = try list.toOwnedSlice();
    debug("  decompressBrotli: OK ({d} -> {d} bytes, {d:0.1}% of original)", .{ input.len, result.len, @as(f64, @floatFromInt(input.len)) / @as(f64, @floatFromInt(result.len)) * 100 });
    return result;
}

fn getFileMtime(path_z: [:0]const u8) i64 {
    var st: Stat64 = undefined;
    if (extern_fns._stat64(path_z, &st) != 0) return 0;
    return st.st_mtime;
}

fn promptWithTimeout(prompt: []const u8, timeout_sec: i64) bool {
    _ = extern_fns.fwrite(prompt.ptr, 1, prompt.len, stdout());
    _ = extern_fns.fflush(stderr());
    const deadline = extern_fns.time(null) + timeout_sec;
    while (extern_fns.time(null) < deadline) {
        if (extern_fns._kbhit() != 0) {
            const ch: u8 = @intCast(extern_fns._getch());
            return ch == 's' or ch == 'S';
        }
    }
    return false;
}

fn menuChoice(prompt: []const u8, timeout_sec: i64, default: u8) u8 {
    _ = extern_fns.fwrite(prompt.ptr, 1, prompt.len, stdout());
    _ = extern_fns.fflush(stderr());
    const deadline = extern_fns.time(null) + timeout_sec;
    while (extern_fns.time(null) < deadline) {
        if (extern_fns._kbhit() != 0) {
            const ch: u8 = @intCast(extern_fns._getch());
            return toUpper(ch);
        }
    }
    return default;
}

fn readMenuLine(prompt: []const u8, timeout_sec: i64, default: []const u8, buf: []u8) []const u8 {
    _ = extern_fns.fwrite(prompt.ptr, 1, prompt.len, stdout());
    _ = extern_fns.fflush(stderr());
    const deadline = extern_fns.time(null) + timeout_sec;
    while (extern_fns.time(null) < deadline) {
        if (extern_fns._kbhit() != 0) {
            // Read first char
            const first: u8 = @intCast(extern_fns._getch());
            if (first == '\r' or first == '\n') {
                return default;
            }
            const up = toUpper(first);
            // If it's a letter, process immediately
            if (up == 'T' or up == 'F' or up == 'B' or up == 'S') {
                buf[0] = up;
                _ = extern_fns.fwrite(&first, 1, 1, stdout());
                _ = extern_fns.fwrite("\n", 1, 1, stdout());
                _ = extern_fns.fflush(stdout());
                return buf[0..1];
            }
            // It's a digit (or anything else) — collect number
            var pos: usize = 0;
            buf[pos] = first;
            pos += 1;
            // Print the first digit
            _ = extern_fns.fwrite(&first, 1, 1, stdout());
            // Poll for more digits with short timeout
            const digit_deadline = extern_fns.time(null) + 1;
            while (pos < buf.len and extern_fns.time(null) < digit_deadline) {
                if (extern_fns._kbhit() != 0) {
                    const next: u8 = @intCast(extern_fns._getch());
                    if (next < '0' or next > '9') {
                        // Not a digit, but we consumed it — ignore and stop
                        break;
                    }
                    buf[pos] = next;
                    pos += 1;
                    _ = extern_fns.fwrite(&next, 1, 1, stdout());
                }
            }
            // Echo newline
            _ = extern_fns.fwrite("\n", 1, 1, stdout());
            _ = extern_fns.fflush(stdout());
            return buf[0..pos];
        }
    }
    // Timeout — return default
    return default;
}

fn tryDownloadWithRetry(client: *std.http.Client, allocator: Allocator, config: *const Config, nombre: []const u8, ruta: []const u8, file_br: []const u8, max_retries: u5) ![]u8 {
    var i: u5 = 0;
    while (i < max_retries) : (i += 1) {
        const data = downloadFile(client, allocator, config, nombre, ruta) catch |err| {
            if (i + 1 < max_retries) {
                debug("  [!] {s} | Error download (intento {}/{}), re-descargando...", .{ nombre, i + 1, max_retries });
                continue;
            }
            return err;
        };
        if (file_br.len > 0) {
            const hash = computeShortHash(data);
            if (!std.mem.eql(u8, &hash, file_br)) {
                allocator.free(data);
                if (i + 1 < max_retries) {
                    debug("  [!] {s} | Hash br no coincide (intento {}/{}), re-descargando...", .{ nombre, i + 1, max_retries });
                    continue;
                }
                debug("  [!] {s} | Hash br no coincide tras {} intentos", .{ nombre, max_retries });
                return error.BrHashMismatch;
            }
        }
        return data;
    }
    return error.BrHashMismatch;
}

fn isDbd(ruta: []const u8) bool {
    return std.mem.indexOf(u8, ruta, "DSBLIND") != null;
}

fn isNor(ruta: []const u8) bool {
    return !isDbd(ruta);
}

fn processFile(client: *std.http.Client, allocator: Allocator, config: *const Config, file: *const PendingFile, summary_lines: *std.array_list.Managed([]u8), force_download: bool) !void {
    const output_name = if (std.mem.endsWith(u8, file.nombre, ".br"))
        file.nombre[0 .. file.nombre.len - 3]
    else
        file.nombre;

    const origin_path = if (file.ruta.len > 0)
        try std.fmt.allocPrint(allocator, "{s}/{s}", .{ file.ruta, output_name })
    else
        try allocator.dupe(u8, output_name);
    defer allocator.free(origin_path);

    const output_path_z = try std.heap.c_allocator.dupeZ(u8, output_name);
    defer std.heap.c_allocator.free(output_path_z);

    var local_data: ?[]u8 = null;
    defer if (local_data) |ld| allocator.free(ld);

    local_data = readFile(output_path_z) catch null;

    const data = tryDownloadWithRetry(client, allocator, config, file.nombre, file.ruta, file.br, 5) catch |err| {
        debug("  [!] {s} | error-br tras {d} intentos: {s}", .{ file.nombre, 5, @errorName(err) });
        _ = confirmDownload(client, allocator, config, file.nombre, "error-br") catch {};
        try summary_lines.append(try allocator.dupe(u8, "[!] error-br"));
        return err;
    };
    defer allocator.free(data);

    const decompressed = decompressBrotli(data, allocator) catch |err| {
        debug("  [!] {s} | error-flat: descompresión falló ({s})", .{ file.nombre, @errorName(err) });
        _ = confirmDownload(client, allocator, config, file.nombre, "error-flat") catch {};
        try summary_lines.append(try allocator.dupe(u8, "[!] error-flat decompress"));
        return err;
    };
    defer allocator.free(decompressed);

    {
        const d_hash = computeShortHash(decompressed);
        if (file.flat.len > 0 and !std.mem.eql(u8, &d_hash, file.flat)) {
            debug("  [!] {s} | error-flat: esperado={s} calculado={s}", .{ file.nombre, file.flat, &d_hash });
            _ = confirmDownload(client, allocator, config, file.nombre, "error-flat") catch {};
            try summary_lines.append(try allocator.dupe(u8, "[!] error-flat hash"));
            return error.FlatHashMismatch;
        }
    }

    if (!force_download) {
        if (local_data) |ld| {
            const local_hash = computeShortHash(ld);
            if (file.flat.len > 0 and std.mem.eql(u8, &local_hash, file.flat)) {
                try confirmDownload(client, allocator, config, file.nombre, "skip");
                const age = computeAge(file.fecha_archivo);
                const line = try std.fmt.allocPrint(allocator, "[=] {s} {s} {s} ({s})", .{
                    file.flat, file.br, origin_path, age,
                });
                try summary_lines.append(line);
                print("{s}", .{line});
                return;
            }
            const local_mtime = getFileMtime(output_path_z);
            const server_epoch = parseTimestampEpoch(file.fecha_archivo);
            if (server_epoch > 0 and local_mtime > 0 and local_mtime > server_epoch) {
                const answer = promptWithTimeout("  Local mas reciente. Sobrescribir? (s/N): ", 4);
                if (!answer) {
                    try confirmDownload(client, allocator, config, file.nombre, "skip");
                    const age = computeAge(file.fecha_archivo);
                    const comp_pct = @as(f64, @floatFromInt(data.len)) / @as(f64, @floatFromInt(decompressed.len)) * 100;
                    const line = try std.fmt.allocPrint(allocator, "[-] {s} {s} {s} ({s}) {d:.0}%", .{
                        file.flat, file.br, origin_path, age, comp_pct,
                    });
                    try summary_lines.append(line);
                    print("{s}", .{line});
                    return;
                }
            }
        }
    }

    {
        const tmp_path = try std.fmt.allocPrint(allocator, "{s}.{d}.tmp", .{ output_name, extern_fns.time(null) });
        defer allocator.free(tmp_path);
        const tmp_path_z = try std.heap.c_allocator.dupeZ(u8, tmp_path);
        defer std.heap.c_allocator.free(tmp_path_z);
        const dest_path_z = try std.heap.c_allocator.dupeZ(u8, output_name);
        defer std.heap.c_allocator.free(dest_path_z);

        writeFile(tmp_path, decompressed) catch |err| {
            debug("  [!] {s} | error-tmp: {s}", .{ file.nombre, @errorName(err) });
            _ = confirmDownload(client, allocator, config, file.nombre, "error-tmp") catch {};
            try summary_lines.append(try allocator.dupe(u8, "[!] error-tmp"));
            return err;
        };

        var rename_ok = false;
        var attempt: u5 = 0;
        while (attempt < 10) : (attempt += 1) {
            _ = extern_fns.remove(dest_path_z);
            if (extern_fns.rename(tmp_path_z, dest_path_z) == 0) {
                rename_ok = true;
                break;
            }
            debug("  [!] La actualizacion del archivo {s} no puede completarse.\n    Debe cerrar el punto de venta y todas las terminales para poder actualizar los archivos.\n    Reintentando en 4s... (intento {}/10)", .{ output_name, attempt + 1 });
            sleepSeconds(4);
        }

        if (!rename_ok) {
            debug("  [!] {s} | error-blocked tras 10 intentos", .{file.nombre});
            _ = confirmDownload(client, allocator, config, file.nombre, "error-blocked") catch {};
            try summary_lines.append(try allocator.dupe(u8, "[!] error-blocked"));
            return error.FileBlocked;
        }

        const epoch = parseTimestampEpoch(file.fecha_archivo);
        if (epoch > 0) {
            const ub = Utimbuf{ .actime = epoch, .modtime = epoch };
            _ = extern_fns._utime(dest_path_z, &ub);
        }
    }

    try confirmDownload(client, allocator, config, file.nombre, "downloaded");

    const age = computeAge(file.fecha_archivo);
    const comp_pct = @as(f64, @floatFromInt(data.len)) / @as(f64, @floatFromInt(decompressed.len)) * 100;
    const line = try std.fmt.allocPrint(allocator, "[+] {s} {s} {s} ({s}) {d:.0}%", .{
        file.flat, file.br, origin_path, age, comp_pct,
    });
    try summary_lines.append(line);
    print("{s}", .{line});
}

fn downloadGroup(client: *std.http.Client, allocator: Allocator, config: *const Config, files: []PendingFile, indices: []const usize, group_name: []const u8) u8 {
    const t0 = extern_fns.time(null);
    var fail_count: u32 = 0;
    for (indices, 1..) |idx, i| {
        var summary_dummy = std.array_list.Managed([]u8).init(allocator);
        defer {
            for (summary_dummy.items) |s| allocator.free(s);
            summary_dummy.deinit();
        }
        printInline("[{d}/{d}] {s} ... ", .{ i, indices.len, files[idx].nombre });
        processFile(client, allocator, config, &files[idx], &summary_dummy, true) catch {
            fail_count += 1;
        };
    }
    const elapsed = extern_fns.time(null) - t0;
    const elapsed_m = @divTrunc(elapsed, 60);
    const elapsed_s = @mod(elapsed, 60);
    print("", .{});
    print("=== Resumen ({s}) ===", .{group_name});
    print("{} fallos en {d}m {d}s", .{ fail_count, elapsed_m, elapsed_s });
    if (fail_count > 0) return 1;
    return 0;
}

fn processFiles(client: *std.http.Client, allocator: Allocator, config: *const Config, files: []PendingFile, indices: []const usize, group_name: []const u8, has_dbd: bool) !u8 {
    const t0 = extern_fns.time(null);

    var summary_lines = std.array_list.Managed([]u8).init(allocator);
    defer {
        for (summary_lines.items) |s| allocator.free(s);
        summary_lines.deinit();
    }

    if (indices.len == 0) {
        print("No hay archivos {s}.", .{group_name});
        return 0;
    }

    // Pre-analysis: determine file status
    var eq_indices = std.array_list.Managed(usize).init(allocator);
    defer eq_indices.deinit();
    var new_indices = std.array_list.Managed(usize).init(allocator);
    defer new_indices.deinit();
    var old_indices = std.array_list.Managed(usize).init(allocator);
    defer old_indices.deinit();

    for (indices) |idx| {
        const file = &files[idx];
        const output_name = if (std.mem.endsWith(u8, file.nombre, ".br"))
            file.nombre[0 .. file.nombre.len - 3]
        else
            file.nombre;
        const output_z = try std.heap.c_allocator.dupeZ(u8, output_name);
        defer std.heap.c_allocator.free(output_z);

        const local_data = readFile(output_z) catch null;
        if (local_data) |ld| {
            defer allocator.free(ld);
            const local_hash = computeShortHash(ld);
            if (file.flat.len > 0 and std.mem.eql(u8, &local_hash, file.flat)) {
                try eq_indices.append(idx);
                continue;
            }
            const local_mtime = getFileMtime(output_z);
            const server_epoch = parseTimestampEpoch(file.fecha_archivo);
            if (server_epoch > 0 and local_mtime > 0 and local_mtime > server_epoch) {
                try old_indices.append(idx);
            } else {
                try new_indices.append(idx);
            }
        } else {
            try new_indices.append(idx);
        }
    }

    // Initialize status array from pre-analysis
    var statuses = try allocator.alloc(u8, indices.len);
    defer allocator.free(statuses);
    for (indices, 0..) |idx, i| {
        var found: bool = false;
        for (eq_indices.items) |ti| if (ti == idx) {
            statuses[i] = '=';
            found = true;
            break;
        };
        if (!found) for (old_indices.items) |ti| if (ti == idx) {
            statuses[i] = '-';
            found = true;
            break;
        };
        if (!found) statuses[i] = '+';
    }

    var fail_count: u32 = 0;

    const menu_prompt = if (has_dbd)
        "Numero, [t]odos, [f]altantes, [b]linde, [s]alir [f]: "
    else
        "Numero, [t]odos, [f]altantes, [s]alir [f]: ";

    while (true) {
        // Show file list each time before prompt
        print("", .{});
        for (indices, 0..) |idx, i| {
            const file = &files[idx];
            if (file.ruta.len > 0) {
                print("[{d}] {c} {s} {s}/{s}", .{ i + 1, statuses[i], file.fecha_archivo, file.ruta, file.nombre });
            } else {
                print("[{d}] {c} {s} {s}", .{ i + 1, statuses[i], file.fecha_archivo, file.nombre });
            }
        }
        print("", .{});

        var line_buf: [16]u8 = undefined;
        const line = readMenuLine(menu_prompt, 10, "f", &line_buf);

        if (line.len == 0) {
            // default: faltantes
            {
                var skip_names = std.array_list.Managed([]const u8).init(allocator);
                defer skip_names.deinit();
                for (eq_indices.items) |idx| try skip_names.append(files[idx].nombre);
                confirmBatch(client, allocator, config, skip_names.items) catch |err| {
                    debug("  [!] Error reportando skips batch: {s}", .{@errorName(err)});
                };
            }
            var falta_indices = std.array_list.Managed(usize).init(allocator);
            defer falta_indices.deinit();
            try falta_indices.appendSlice(new_indices.items);
            try falta_indices.appendSlice(old_indices.items);
            for (falta_indices.items, 1..) |idx, i| {
                printInline("[{d}/{d}] {s} ... ", .{ i, falta_indices.items.len, files[idx].nombre });
                processFile(client, allocator, config, &files[idx], &summary_lines, false) catch {
                    fail_count += 1;
                };
            }
            break;
        }

        const first = toUpper(line[0]);
        if (first == 'S') {
            return 0;
        }
        if (first == 'B') {
            return 2;
        }
        if (first == 'T') {
            for (indices, 1..) |idx, i| {
                printInline("[{d}/{d}] {s} ... ", .{ i, indices.len, files[idx].nombre });
                processFile(client, allocator, config, &files[idx], &summary_lines, true) catch {
                    fail_count += 1;
                };
            }
            break;
        }
        if (first == 'F') {
            {
                var skip_names = std.array_list.Managed([]const u8).init(allocator);
                defer skip_names.deinit();
                for (eq_indices.items) |idx| try skip_names.append(files[idx].nombre);
                confirmBatch(client, allocator, config, skip_names.items) catch |err| {
                    debug("  [!] Error reportando skips batch: {s}", .{@errorName(err)});
                };
            }
            var falta_indices = std.array_list.Managed(usize).init(allocator);
            defer falta_indices.deinit();
            try falta_indices.appendSlice(new_indices.items);
            try falta_indices.appendSlice(old_indices.items);
            for (falta_indices.items, 1..) |idx, i| {
                printInline("[{d}/{d}] {s} ... ", .{ i, falta_indices.items.len, files[idx].nombre });
                processFile(client, allocator, config, &files[idx], &summary_lines, false) catch {
                    fail_count += 1;
                };
            }
            break;
        }
        // Try to parse as number
        const num = std.fmt.parseInt(usize, line, 10) catch {
            print("Opcion invalida.", .{});
            // Return to menu
            continue;
        };
        if (num < 1 or num > indices.len) {
            print("Numero invalido (1-{d}).", .{indices.len});
            continue;
        }
        const idx = indices[num - 1];
        printInline("[1/1] {s} ... ", .{files[idx].nombre});
        if (processFile(client, allocator, config, &files[idx], &summary_lines, false)) {
            statuses[num - 1] = '=';
        } else |_| {
            statuses[num - 1] = 'E';
            fail_count += 1;
        }
        // Continue loop to show menu again
    }

    const elapsed = extern_fns.time(null) - t0;
    const elapsed_m = @divTrunc(elapsed, 60);
    const elapsed_s = @mod(elapsed, 60);

    print("", .{});
    print("=== Resumen ({s}) ===", .{group_name});
    for (summary_lines.items) |line| {
        print("{s}", .{line});
    }
    print("", .{});
    print("{} fallos en {d}m {d}s", .{ fail_count, elapsed_m, elapsed_s });

    if (fail_count > 0) return 1;
    return 0;
}

fn runSync(client: *std.http.Client, allocator: Allocator, config: *const Config) !u8 {
    print("=== Sincronizacion - Sucursal: {s} ===", .{config.sucursal_id});

    const files = fetchFiles(client, allocator, config) catch |err| {
        debug("Error fetching file list: {s}", .{@errorName(err)});
        return 1;
    };
    defer {
        for (files) |f| {
            allocator.free(f.nombre);
            allocator.free(f.ruta);
            allocator.free(f.flat);
            allocator.free(f.br);
            allocator.free(f.ultimo_cambio);
            allocator.free(f.fecha_archivo);
        }
        allocator.free(files);
    }

    debug("Archivos en lista: {d}", .{files.len});

    if (files.len == 0) {
        print("No hay archivos pendientes.", .{});
        return 0;
    }

    // Separate DBD and NOR files by ruta
    var dbd_indices = std.array_list.Managed(usize).init(allocator);
    defer dbd_indices.deinit();
    var nor_indices = std.array_list.Managed(usize).init(allocator);
    defer nor_indices.deinit();

    for (files, 0..) |file, idx| {
        if (isDbd(file.ruta)) {
            try dbd_indices.append(idx);
        } else if (isNor(file.ruta)) {
            try nor_indices.append(idx);
        }
    }

    const has_dbd = dbd_indices.items.len > 0;
    const has_nor = nor_indices.items.len > 0;

    if (!has_dbd and !has_nor) {
        print("No hay archivos pendientes.", .{});
        return 0;
    }

    if (has_nor) {
        const result = try processFiles(client, allocator, config, files, nor_indices.items, "NOR", has_dbd);
        if (result == 2 and has_dbd) {
            print("", .{});
            return downloadGroup(client, allocator, config, files, dbd_indices.items, "DBD");
        }
        return result;
    }

    // Only DBD files
    return downloadGroup(client, allocator, config, files, dbd_indices.items, "DBD");
}