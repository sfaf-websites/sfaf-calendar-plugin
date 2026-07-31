#!/usr/bin/env bash
#
# PreToolUse guard: force a permission prompt before irreversible shell actions.
#
# WHY THIS EXISTS AS A SCRIPT AND NOT ONLY AS PERMISSION RULES
# -----------------------------------------------------------------------------
# The permissions.ask list in settings.json is the first line and works on any
# machine. But its Bash patterns are matched as globs against the command
# string, and a glob that is anchored at the front cannot reliably catch an
# argument that moves:
#
#     git push --force              <- a leading pattern catches this
#     git push origin --force       <- the flag has moved; a leading pattern misses it
#     git -C ../other push -f       <- so has the subcommand
#
# A rule that silently matches nothing looks identical to a rule that is
# working. This script sees the whole command string and applies a real regex,
# so the spellings above are all caught. Both layers are kept: the rules cover
# the common cases without any dependency on a shell, and this covers the rest.
#
# WHAT IT PROTECTS, AND WHY IT IS WORDED THIS CAREFULLY
# -----------------------------------------------------------------------------
# Earlier in this project a working transcript containing a live credential was
# force-pushed to GitHub. What made that recoverable was not a backup and not a
# clever fix: it was that somebody was asked first and stopped to look. Every
# entry here buys back that pause. Please do not widen it casually, and in
# particular do not "simplify" it by deleting a case that has never fired --
# never firing is the intended steady state.
#
# It asks. It does not block. Answering yes is one keystroke, which is the
# whole point: the cost of a false positive is a second, and the cost of a
# false negative is a credential in a public repository.
#
# CONTRACT
# -----------------------------------------------------------------------------
# stdin : the PreToolUse hook payload, as JSON
# stdout: either nothing (no opinion, normal permission flow continues) or a
#         hookSpecificOutput asking for confirmation
# exit  : always 0. A guard that fails closed would block routine work; a guard
#         that errors loudly on every command would be turned off within a day.

set -u

payload=$(cat 2>/dev/null || true)

# Extract the command without requiring jq: this has to keep working on a
# machine that does not have it. Falls back to jq when available, because the
# hand-rolled extraction cannot handle every escape.
cmd=""
if command -v jq >/dev/null 2>&1; then
  cmd=$(printf '%s' "$payload" | jq -r '.tool_input.command // empty' 2>/dev/null || true)
fi
if [ -z "$cmd" ]; then
  cmd=$(printf '%s' "$payload" | sed -n 's/.*"command"[[:space:]]*:[[:space:]]*"\(.*\)".*/\1/p' | head -1)
fi
[ -z "$cmd" ] && exit 0

reason=""

note() {
  # First match wins. The reason names the specific thing spotted, because
  # "this looks dangerous" gives the reader nothing to check.
  [ -z "$reason" ] && reason="$1"
}

# --- Force push, in any spelling ---------------------------------------------
# Matches the flag wherever it sits, including after a remote or a -C path.
if printf '%s' "$cmd" | grep -Eqi '(^|[;&|[:space:]])git([[:space:]]+-[^[:space:]]+)*[[:space:]]+([^;&|]*[[:space:]])?push([[:space:]]|$)'; then
  if printf '%s' "$cmd" | grep -Eqi '(--force([^-]|$)|--force-with-lease|--force-if-includes|[[:space:]]-[A-Za-z]*f([[:space:]]|$))'; then
    note "This is a force push. It overwrites history on the remote, and anything already pushed under the old history stops being reachable by its usual name."
  fi
  # A leading + on a refspec is a force push wearing a different hat.
  if printf '%s' "$cmd" | grep -Eq 'push[^;&|]*[[:space:]]\+[A-Za-z0-9_./*-]+:'; then
    note "This push uses a + refspec, which is a force push written the other way round."
  fi
fi

# --- History rewriting --------------------------------------------------------
if printf '%s' "$cmd" | grep -Eqi '(filter-branch|filter-repo|(^|[[:space:]])bfg([[:space:]]|$))'; then
  note "This rewrites repository history. Every commit downstream of the rewrite gets a new hash, and clones that already have the old ones will fight it."
fi

# --- Hard reset ---------------------------------------------------------------
if printf '%s' "$cmd" | grep -Eqi 'git([[:space:]]+-[^[:space:]]+)*[[:space:]]+([^;&|]*[[:space:]])?reset[^;&|]*--hard'; then
  note "A hard reset throws away uncommitted work in the working tree, and it does so without a copy anywhere."
fi

# Other ways to lose local work outright.
if printf '%s' "$cmd" | grep -Eqi 'git[^;&|]*checkout[^;&|]*[[:space:]]--[[:space:]]|git[^;&|]*clean[^;&|]*-[A-Za-z]*[dfx]'; then
  note "This discards local changes or untracked files with no copy kept."
fi

# --- Recursive delete ---------------------------------------------------------
# SCOPED ON PURPOSE. The build genuinely does "rm -rf" against staging and temp
# directories many times a session, and a guard that prompts on every one of
# those is a guard that gets switched off. So: recursive deletes are waved
# through when every target is clearly inside a temp or scratch area, and
# stopped everywhere else -- which includes anywhere in this repository.
recursive=""
if printf '%s' "$cmd" | grep -Eq '(^|[;&|[:space:]])rm[[:space:]]+(-[A-Za-z]*[rR][A-Za-z]*[[:space:]]|-[A-Za-z]*[rR]$)'; then
  recursive="1"
fi
if printf '%s' "$cmd" | grep -Eqi 'Remove-Item[^;&|]*-Recurse|(^|[[:space:]])rmdir[[:space:]]+/[sS]'; then
  recursive="1"
fi

if [ -n "$recursive" ]; then
  # FAIL SAFE, NOT FAIL OPEN. An earlier version of this asked only when a
  # target contained a slash, which meant "rm -rf includes" -- a bare directory
  # name, in this repository -- sailed straight through. So the test is
  # inverted: a target is waved through only when it can be SEEN to be under a
  # temp or scratch root, and anything else, including a bare name and
  # including a variable whose contents are unknowable from here, is stopped.
  unsafe=""
  for word in $cmd; do
    case "$word" in
      # Flags, the command words themselves, and shell operators are not targets.
      -*|rm|Remove-Item|rmdir|del|'&&'|'||'|';'|'|'|'>'|'>>') continue ;;
    esac
    case "$word" in
      # Verifiably a scratch location: literal, and rooted somewhere disposable.
      /tmp|/tmp/*|*/[Tt]emp|*/[Tt]emp/*|*scratchpad*|*/AppData/Local/Temp/*) continue ;;
      # Everything else is a target we cannot vouch for.
      *) unsafe="1" ;;
    esac
  done
  if [ -n "$unsafe" ]; then
    note "This deletes a directory and everything inside it. Recursive deletes against anything outside a temp or scratch directory are worth a second look."
  fi
fi

# --- Reading a credential file through the shell ------------------------------
# The Read tool is covered by the ask rules in settings.json. This is the other
# door: cat/type/Get-Content reach the same bytes and answer to no such rule.
if printf '%s' "$cmd" | grep -Eqi '(cat|type|less|more|head|tail|Get-Content|strings|grep|rg|sed|awk)[^;&|]*(\.env([^A-Za-z0-9_.-]|$)|\.env\.[A-Za-z]|credentials?\.(json|ya?ml|txt|ini)|\.pem([^A-Za-z0-9]|$)|id_rsa|id_ed25519|\.npmrc|\.netrc|secrets?\.(json|ya?ml|env))'; then
  note "This reads a file that usually holds credentials. Anything read here can end up in the transcript, and a transcript is a file that gets committed by accident."
fi

[ -z "$reason" ] && exit 0

# Escape for JSON: backslashes, then quotes, then newlines.
esc=$(printf '%s' "$reason" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' ')

printf '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"ask","permissionDecisionReason":"%s"}}' "$esc"
exit 0
