const std = @import("std");

pub fn build(b: *std.Build) void {
    const optimize = .ReleaseSmall;

    const target = b.resolveTargetQuery(.{
        .cpu_arch = .x86_64,
        .os_tag = .windows,
        .os_version_min = .{ .windows = .win10 },
    });

    const brotli_c = [_][]const u8{
        "brotli/c/dec/decode.c",
        "brotli/c/dec/bit_reader.c",
        "brotli/c/dec/huffman.c",
        "brotli/c/dec/state.c",
        "brotli/c/common/constants.c",
        "brotli/c/common/dictionary.c",
        "brotli/c/common/platform.c",
        "brotli/c/common/context.c",
        "brotli/c/common/shared_dictionary.c",
        "brotli/c/common/transform.c",
    };

    const mod = b.createModule(.{
        .root_source_file = b.path("src/main.zig"),
        .target = target,
        .optimize = optimize,
        .strip = true,
    });
    mod.link_libc = true;
    mod.addIncludePath(b.path("brotli/c/include"));

    for (&brotli_c) |cfile| {
        mod.addCSourceFile(.{ .file = b.path(cfile), .flags = &.{ "-Os", "-s", "-fdata-sections", "-ffunction-sections" } });
    }

    const exe = b.addExecutable(.{ .name = "zcli", .root_module = mod });
    exe.link_gc_sections = true;
    exe.link_eh_frame_hdr = false;
    b.installArtifact(exe);
}
