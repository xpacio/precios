const std = @import("std");

pub fn build(b: *std.Build) void {
    const optimize = .ReleaseSmall;

    inline for (.{
        .{ .name = "zcli", .target = b.resolveTargetQuery(.{}) },
        .{ .name = "zcli", .target = b.resolveTargetQuery(.{ .cpu_arch = .x86_64, .os_tag = .windows, .os_version_min = .{ .windows = .win10 } }) },
    }) |cfg| {
        const mod = b.createModule(.{
            .root_source_file = b.path("src/main.zig"),
            .target = cfg.target,
            .optimize = optimize,
            .strip = true,
        });
        mod.link_libc = true;

        if (cfg.target.result.os.tag != .windows) {
            mod.linkSystemLibrary("brotlidec", .{ .preferred_link_mode = .static });
            mod.linkSystemLibrary("brotlicommon", .{ .preferred_link_mode = .static });
        }

        const exe = b.addExecutable(.{ .name = cfg.name, .root_module = mod });
        b.installArtifact(exe);
    }
}
