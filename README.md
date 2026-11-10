<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Composer Source Reference Plugin

`super-kernel/composer-source-reference-plugin` is a Composer plugin that gives `super-kernel/*` packages installed from local path repositories a lock reference derived from their production source, so that `composer update` records every change to that source in `composer.lock`.

## Installation

```bash
composer require super-kernel/composer-source-reference-plugin
```

The package needs Composer 2 with plugins enabled, and the project must permit it in `config.allow-plugins`. It only matters for `super-kernel/*` packages installed from `path` repositories; packages installed from Packagist keep their own references.

## What it provides

- A `dist.reference` of the form `sk-source-v1-<hash>` for each `super-kernel/*` path package that Composer resolves.
- A hash of the package's production autoload sources, `psr-0`, `psr-4`, `classmap`, and `files`, with their package-relative paths and bytes.
- The same reference for the same source, whatever the checkout location, file times, or directory order.
- A failed update, instead of a stale reference, when a source cannot be read safely.

It does not:

- change the root package, packages from other vendors, or packages from other repository types;
- include `autoload-dev`, tests, documentation, or other files outside the production autoload rules;
- run when Composer runs with `--no-plugins`;
- scan anything when the application starts.

## Quick start

Install a local `super-kernel/*` package through a path repository, here a copy of `super-kernel/filesystem` next to the project, and permit the plugin. A path package takes its version from its directory, such as `dev-main`, so require it with `*`. Save the project's manifest as `composer.json`:

```json
{
    "name": "acme/billing",
    "type": "project",
    "repositories": [
        {
            "type": "path",
            "url": "../packages/filesystem"
        }
    ],
    "require": {
        "super-kernel/composer-source-reference-plugin": "^1.0.0-alpha.2",
        "super-kernel/filesystem": "*"
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "super-kernel/composer-source-reference-plugin": true
        }
    }
}
```

Update twice, the first time to install the plugin and the second to let it compute the reference:

```bash
composer update
composer update
```

`composer.lock` then records the path package with a `dist.reference` of `sk-source-v1-` followed by 64 hexadecimal digits.

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `config.allow-plugins` | Configuration key | Must permit `super-kernel/composer-source-reference-plugin`, or Composer does not run it. |
| `SuperKernel\ComposerSourceReferencePlugin\Plugin` | Class | The plugin Composer activates; it computes the references while Composer builds its dependency pool. |

## Behavior to know

- The plugin must be installed before Composer builds the dependency pool, so introducing it takes one `composer update` to install it and a second to compute the references; afterwards one `composer update` does both.
- Only packages that the update resolves are processed: locked or fixed packages, aliases, and path packages without a reference keep theirs.
- Changing, adding, or deleting a production autoload file changes the reference at the next update, and so changes `composer.lock`; files that `exclude-from-classmap` excludes do not count.
- `composer update --no-plugins` writes Composer's own references instead; do not use it to update the lock of a project that relies on these references.
- An unsafe or unreadable autoload path fails the update with a `RuntimeException`, such as `Autoload paths must not contain ".." segments.`
- Run `skernel build` after the update: a changed `composer.lock` makes the project's build metadata out of date.

## Documentation

The developer documentation describes which packages and files count, bootstrapping the plugin, how the reference reaches the build, and every error message: [SuperKernel Composer Source Reference Plugin](https://github.com/super-kernel/.github/blob/main/docs/components/composer-source-reference-plugin.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
