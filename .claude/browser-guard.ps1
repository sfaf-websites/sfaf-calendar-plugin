# Run one browser check inside a Windows job object, so that it cannot take more
# memory than the ceiling and so that no Chrome it started outlives it.
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File .claude/browser-guard.ps1 `
#         -CeilingMB 1500 -ReserveMB 400 -WaitSeconds 120 -Command 'php .claude/x.php --run'
#
# WHY A JOB AND NOT A WATCHER. One page in headless Chrome is about fourteen
# processes, most of them without --headless on their command line, and listing
# the process table takes half a second. A watcher would miss the processes it
# could not name and the spikes between its samples. A job holds every process
# the check starts, Chrome's children included, because none of them may break
# away; the system refuses any allocation past the job's limit, so the ceiling is
# never exceeded rather than noticed afterwards; and closing the job ends every
# process still in it.
#
# WHAT IT PRINTS FIRST is one guard line, then the check's own output, so that
# run-all's one-line summary (the LAST line) is still the check's.
#
# Exit: the check's own code; 3 when the ceiling was reached; 4 when there was
# not enough free memory to start within WaitSeconds; 5 when a process in the
# job would not end; 6 when the check ran past TimeoutSeconds.
param(
    [int]$CeilingMB = 1500,
    [int]$ReserveMB = 400,
    [int]$WaitSeconds = 120,
    [int]$GraceSeconds = 20,
    [int]$TimeoutSeconds = 900,
    [Parameter(Mandatory = $true)][string]$Command
)
[Console]::OutputEncoding = [Text.Encoding]::UTF8

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class UcJob {
    [StructLayout(LayoutKind.Sequential)] struct Basic {
        public long PerProcessUserTimeLimit; public long PerJobUserTimeLimit; public uint LimitFlags;
        public UIntPtr MinimumWorkingSetSize; public UIntPtr MaximumWorkingSetSize; public uint ActiveProcessLimit;
        public UIntPtr Affinity; public uint PriorityClass; public uint SchedulingClass; }
    [StructLayout(LayoutKind.Sequential)] struct Io { public ulong R, W, O, RB, WB, OB; }
    [StructLayout(LayoutKind.Sequential)] struct Ext {
        public Basic B; public Io I; public UIntPtr ProcessMemoryLimit; public UIntPtr JobMemoryLimit;
        public UIntPtr PeakProcessMemoryUsed; public UIntPtr PeakJobMemoryUsed; }
    [StructLayout(LayoutKind.Sequential)] struct Acct {
        public long TotalUserTime, TotalKernelTime, ThisPeriodUser, ThisPeriodKernel;
        public uint TotalPageFaultCount, TotalProcesses, ActiveProcesses, TotalTerminatedProcesses; }
    [DllImport("kernel32.dll", SetLastError = true)] static extern IntPtr CreateJobObject(IntPtr a, string n);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool SetInformationJobObject(IntPtr j, int c, ref Ext i, uint l);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool QueryInformationJobObject(IntPtr j, int c, ref Ext i, uint l, IntPtr r);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool QueryInformationJobObject(IntPtr j, int c, ref Acct i, uint l, IntPtr r);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool AssignProcessToJobObject(IntPtr j, IntPtr p);
    [DllImport("kernel32.dll", SetLastError = true)] static extern bool TerminateJobObject(IntPtr j, uint code);
    const uint LIMIT_JOB_MEMORY = 0x200, KILL_ON_JOB_CLOSE = 0x2000;
    public static IntPtr Create(long ceilingBytes) {
        IntPtr j = CreateJobObject(IntPtr.Zero, null);
        if (j == IntPtr.Zero) throw new Exception("CreateJobObject failed: " + Marshal.GetLastWin32Error());
        Ext e = new Ext();
        e.B.LimitFlags = LIMIT_JOB_MEMORY | KILL_ON_JOB_CLOSE;
        e.JobMemoryLimit = new UIntPtr((ulong)ceilingBytes);
        if (!SetInformationJobObject(j, 9, ref e, (uint)Marshal.SizeOf(typeof(Ext))))
            throw new Exception("SetInformationJobObject failed: " + Marshal.GetLastWin32Error());
        return j;
    }
    public static void Assign(IntPtr j, IntPtr process) {
        if (!AssignProcessToJobObject(j, process)) throw new Exception("AssignProcessToJobObject failed: " + Marshal.GetLastWin32Error());
    }
    public static long PeakBytes(IntPtr j) {
        Ext e = new Ext(); QueryInformationJobObject(j, 9, ref e, (uint)Marshal.SizeOf(typeof(Ext)), IntPtr.Zero);
        return (long)e.PeakJobMemoryUsed.ToUInt64();
    }
    public static uint Active(IntPtr j) {
        Acct a = new Acct(); QueryInformationJobObject(j, 1, ref a, (uint)Marshal.SizeOf(typeof(Acct)), IntPtr.Zero);
        return a.ActiveProcesses;
    }
    public static uint Total(IntPtr j) {
        Acct a = new Acct(); QueryInformationJobObject(j, 1, ref a, (uint)Marshal.SizeOf(typeof(Acct)), IntPtr.Zero);
        return a.TotalProcesses;
    }
    public static void Kill(IntPtr j) { TerminateJobObject(j, 1); }
}
'@

function Free-MB { [int]((Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory / 1024) }

# 1. Room to start. The ceiling is what the check may take; the reserve is what
# stays for everything else on the machine, Claude Code included.
$need = $CeilingMB + $ReserveMB
$waited = 0
while ( ( $free = Free-MB ) -lt $need ) {
    if ( $waited -ge $WaitSeconds ) {
        "browser guard: NOT STARTED, $free MB free after ${WaitSeconds}s and the check needs $need MB ($CeilingMB ceiling + $ReserveMB reserve)."
        exit 4
    }
    Start-Sleep -Seconds 5; $waited += 5
}

# 2. The check, in the job. cmd /c runs it exactly as written, and cmd is in the
# job from its first instruction, before it can start anything.
$out = [IO.Path]::GetTempFileName()
$job = [UcJob]::Create( [long]$CeilingMB * 1MB )
$psi = New-Object Diagnostics.ProcessStartInfo
$psi.FileName = $env:ComSpec
$psi.Arguments = '/d /s /c "' + $Command + ' > "' + $out + '" 2>&1"'
$psi.UseShellExecute = $false
$psi.CreateNoWindow = $true
$psi.WorkingDirectory = (Get-Location).Path
$p = [Diagnostics.Process]::Start( $psi )
[UcJob]::Assign( $job, $p.Handle )

# A CHROME REFUSED MEMORY DOES NOT EXIT. It waits for a renderer it could not
# start, and shell_exec waits for Chrome, so a check at the ceiling hangs rather
# than fails. Once the ceiling is reached the check has GraceSeconds to finish;
# and no check runs past TimeoutSeconds whatever it is doing.
$sw = [Diagnostics.Stopwatch]::StartNew()
$hitAt = $null
$ended = ''
while ( -not $p.WaitForExit( 1000 ) ) {
    if ( $null -eq $hitAt -and [UcJob]::PeakBytes( $job ) -ge ( [long]$CeilingMB * 1MB - 1MB ) ) { $hitAt = $sw.Elapsed.TotalSeconds }
    if ( $null -ne $hitAt -and $sw.Elapsed.TotalSeconds - $hitAt -ge $GraceSeconds ) { $ended = 'ceiling'; break }
    if ( $sw.Elapsed.TotalSeconds -ge $TimeoutSeconds ) { $ended = 'timeout'; break }
}
if ( '' -ne $ended ) {
    [UcJob]::Kill( $job )
    $p.WaitForExit( 10000 ) | Out-Null
}
$code = if ( '' -ne $ended ) { 1 } else { $p.ExitCode }

# 3. Chrome closed. Anything the check left running is ended here, and the
# guard does not return until the job is empty.
$left = [UcJob]::Active( $job )
if ( $left -gt 0 ) { [UcJob]::Kill( $job ) }
for ( $i = 0; $i -lt 100 -and [UcJob]::Active( $job ) -gt 0; $i++ ) { Start-Sleep -Milliseconds 100 }
$still = [UcJob]::Active( $job )
$peak = [int]( [UcJob]::PeakBytes( $job ) / 1MB )
$total = [UcJob]::Total( $job )

$note = "browser guard: peak $peak MB of a $CeilingMB MB ceiling, $total processes"
if ( $left -gt 0 -and '' -eq $ended ) { $note += ", $left left running and ended" }
if ( $still -gt 0 ) { $note += ", $still STILL RUNNING" }
if ( 'timeout' -eq $ended ) {
    $note = "browser guard: ENDED after ${TimeoutSeconds}s still running, at a peak of $peak MB. $total processes."
    $code = 6
} elseif ( $peak -ge $CeilingMB - 1 ) {
    $how = if ( 'ceiling' -eq $ended ) { " It was still running ${GraceSeconds}s later and was ended." } else { '' }
    $note = "browser guard: the check reached the $CeilingMB MB ceiling and was refused more memory.$how Whatever failed below failed for memory. $total processes."
    $code = 3
}
if ( $still -gt 0 -and 0 -eq $code ) { $code = 5 }
$note
Get-Content -LiteralPath $out -Encoding UTF8
Remove-Item -LiteralPath $out -ErrorAction SilentlyContinue
exit $code
