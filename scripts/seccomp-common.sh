#!/usr/bin/env bash
# seccomp-common.sh — SOURCED. SECCOMP_PL is a perl program that installs the
# customer link filter, then execs its argv:
#   perl -e "$SECCOMP_PL" -- setpriv --reuid=<uid> --regid=<gid> ... -- cmd
# Run it AS ROOT and drop privileges inside the exec'd command: installed by
# root there is no no_new_privs, so setuid exim (sendmail, PHP mail()) still
# works. Used for everything that runs customer code outside FPM: cron jobs
# (cron-run.sh), supervised daemons (app-daemon-run.sh) and wp-cli on
# customer sites (fleet-scan.sh, fleet-update.sh). symlink-guard.sh covers FPM.
# seccomp-bpf: EPERM for symlink/symlinkat/io_uring_* on the native ABI and
# the compat ABI (i386 / arm32), and for every x32 syscall (x86_64 nr bit 30).
# Unknown architecture => EPERM for everything (fail closed).
read -r -d '' SECCOMP_PL <<'PERL' || true
use strict; use warnings; use POSIX ();
my $m = (POSIX::uname())[4];
my %A = (
  x86_64  => [157, [0xC000003E, 1, [88, 266, 425, 426, 427]], [0x40000003, 0, [83, 304, 425, 426, 427]]],
  aarch64 => [167, [0xC00000B7, 0, [36, 425, 426, 427]],      [0x40000028, 0, [83, 331, 425, 426, 427]]],
);
my $a = $A{$m} or die "seccomp: no seccomp table for $m\n";
my ($prctl, @secs) = @$a;
my (@p, %lab);
for my $i (0 .. $#secs) {
  my ($arch, $x32, $nrs) = @{$secs[$i]};
  push @p, [0x20, 0, 0, 4], [0x15, 0, "s" . ($i + 1), $arch], [0x20, 0, 0, 0];
  push @p, [0x35, "E", 0, 0x40000000] if $x32;
  push @p, [0x15, "E", 0, $_] for @$nrs;
  push @p, [0x06, 0, 0, 0x7fff0000];
  $lab{"s" . ($i + 1)} = scalar @p;
}
$lab{E} = scalar @p; push @p, [0x06, 0, 0, 0x00050001];
my $f = '';
for my $i (0 .. $#p) {
  my ($c, $t, $e, $k) = @{$p[$i]};
  for ($t, $e) { $_ = $lab{$_} - $i - 1 if /\D/ }
  $f .= pack("SCCL", $c, $t, $e, $k);
}
my $prog = pack("S x6 Q", scalar @p, unpack("Q", pack("p", $f)));
syscall($prctl, 22, 2, $prog) == 0 or die "seccomp: seccomp install failed: $!\n";
exec { $ARGV[0] } @ARGV or die "seccomp: exec failed: $!\n";
PERL
