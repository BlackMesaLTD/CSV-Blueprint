# Security

CSV Blueprint is usually run in pull-request CI on files the pull request changes. This page covers what it
protects against when those files are hostile, how to run it safely, and how to report a vulnerability.

## Reporting a vulnerability

Report privately through GitHub: **Security → Report a vulnerability** on this repository. Please don't open a
public issue. Include a minimal schema and CSV that show the problem, the command you ran and the version (`-V`).

## What the validator guarantees

CSV files, schemas and presets are treated as untrusted input.

- **No code execution from schemas.** YAML is parsed without PHP tags or objects. PHP schemas are executed, so they
  load only as top-level schemas with `--allow-php-schemas`, never as presets. The action never passes that flag.
- **No reads outside the working directory through schemas.** Presets must be YAML or JSON files inside the
  working directory, with no stream wrappers (`phar:`, `php:`, `data:`, ...). `is_file_exists` and `is_dir_exists`
  only look inside it and answer the same for every path outside it.
- **No symlinks.** CSV and schema paths that are, or pass through, a symlink are an error, not a silent skip.
- **Every matching file is checked.** Globs match dot-files and git-ignored files, so a file can't be hidden
  from the check while other tools still read it.
- **No forged CI commands.** Values, headers, file names and error messages are printed on one line with control
  characters made visible, and the `github` report escapes workflow-command syntax, so input can't start an output
  line with `::`.
- **Bounded work.** Preset cycles, chains deeper than 16, more than 256 preset files and schema files over 1 MiB
  are refused; each preset file is loaded once per schema.

## Running the action safely

```yaml
csv-check:
  runs-on: ubuntu-latest
  timeout-minutes: 15
  permissions:
    contents: read
  steps:
    - uses: actions/checkout@<SHA> # vX.Y.Z
      with:
        persist-credentials: false
    - uses: BlackMesaLTD/CSV-Blueprint@<release commit SHA> # <version>
      with:
        csv: data/**/*.csv
        schema: schemas/*.yml
        report: github
```

- **Pin a release commit.** Each release commit's `action.yml` names the image by digest
  (`ghcr.io/blackmesaltd/csv-blueprint:<version>@sha256:...`), so the pinned commit always runs exactly the image
  built for it. Releases made before the digest-pinning release workflow name the image by tag only; repin to a
  newer release to get the guarantee.
- **Verify the image** if you like: `gh attestation verify oci://ghcr.io/blackmesaltd/csv-blueprint@sha256:<digest>
  --repo BlackMesaLTD/CSV-Blueprint`.
- **Give the job read-only permissions** and don't persist checkout credentials: the check only reads files.

## Limits

- **Self-hosted runners.** The image runs as uid 1001, the runner user on GitHub-hosted runners. On a self-hosted
  runner the checked-out files must be readable by that user (the default umask 022 is fine), or the run fails with
  `File is not readable`.
- **Windows.** The symlink and path-containment checks assume POSIX paths (`/`). On Windows they're best-effort:
  run the validator on Linux or macOS for untrusted input. The action always runs on Linux.
- **Dot-directories in the directory part of a pattern** (`data/*/x.csv` against `data/.hidden/x.csv`) aren't
  matched, because PHP's `glob()` skips them there. Use a recursive pattern if that matters.
- **Large inputs.** CSV size isn't capped; set `timeout-minutes` on the job.
