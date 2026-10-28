# Composer Source Reference Plugin

This Composer plugin gives local `super-kernel/*` path packages deterministic source-sensitive
`dist.reference` values during dependency-pool creation.

## Contract

- Only non-locked `super-kernel/*` packages with `dist.type=path` and an existing reference are processed.
- Production `psr-0`, `psr-4`, `classmap`, and `files` inputs contribute sorted package-relative paths and
  file bytes to the reference.
- `exclude-from-classmap` removes matching PSR and classmap files. Dependency `autoload-dev` is ignored.
- The original path repository reference remains part of the hash, preserving Composer manifest and repository-option
  changes.
- Absolute filesystem locations, timestamps, and inode data never contribute to the result.
- Root package metadata is untouched. `composer update --no-plugins` explicitly bypasses this contract.

The plugin runs only inside Composer. SuperKernel runtime startup performs no additional source-directory scan.

## Bootstrap and use

The plugin must already be installed when Composer builds the dependency pool. Introducing it to an existing root
project therefore requires one update that installs the plugin followed by a second update that regenerates local
package references. Once bootstrapped, a normal `composer update` performs both dependency resolution and source
reference refresh in one command.

Passing `--no-plugins` disables source-reference refresh by definition and must not be used to regenerate the root lock
file of a project that relies on these references.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
