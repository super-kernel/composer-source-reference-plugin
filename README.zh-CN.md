<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Composer Source Reference Plugin

`super-kernel/composer-source-reference-plugin` 是一个 Composer 插件，它为从本地路径仓库安装的 `super-kernel/*` 包提供一个由其生产源码得出的锁定引用，使 `composer update` 把这些源码的每次变化都记录到 `composer.lock` 中。

## 安装

```bash
composer require super-kernel/composer-source-reference-plugin
```

本包需要启用了插件的 Composer 2，并且项目必须在 `config.allow-plugins` 中允许它。它只对从 `path` 仓库安装的 `super-kernel/*` 包起作用；从 Packagist 安装的包保持它们自己的引用。

## 提供的能力

- 为 Composer 解析的每个 `super-kernel/*` 路径包提供形如 `sk-source-v1-<hash>` 的 `dist.reference`。
- 对包的生产自动加载来源（`psr-0`、`psr-4`、`classmap` 和 `files`）连同其相对于包的路径和字节计算的哈希。
- 相同的源码得到相同的引用，与检出位置、文件时间或目录顺序无关。
- 当某个来源无法被安全读取时，更新失败，而不是保留过期的引用。

它不会：

- 修改根包、其他供应商的包或来自其他仓库类型的包；
- 包含 `autoload-dev`、测试、文档或生产自动加载规则之外的其他文件；
- 在 Composer 以 `--no-plugins` 运行时运行；
- 在应用启动时扫描任何内容。

## 快速开始

通过路径仓库安装一个本地 `super-kernel/*` 包（这里是位于项目旁边的一份 `super-kernel/filesystem` 副本），并允许该插件。路径包从其目录获取版本（例如 `dev-main`），因此用 `*` 依赖它。将项目的清单保存为 `composer.json`：

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

更新两次，第一次安装插件，第二次让它计算引用：

```bash
composer update
composer update
```

随后 `composer.lock` 记录该路径包，其 `dist.reference` 为 `sk-source-v1-` 加上 64 个十六进制数字。

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `config.allow-plugins` | Composer 键 | 必须允许 `super-kernel/composer-source-reference-plugin`，否则 Composer 不会运行它。 |
| `SuperKernel\ComposerSourceReferencePlugin\Plugin` | 类 | Composer 激活的插件；它在 Composer 构建依赖池时计算引用。 |

## 需要了解的行为

- 插件必须在 Composer 构建依赖池之前已经安装，因此引入它需要一次 `composer update` 来安装、再一次来计算引用；之后一次 `composer update` 同时完成两者。
- 只处理本次更新解析的包：被锁定或固定的包、别名以及没有引用的路径包保持原有引用。
- 修改、添加或删除生产自动加载文件会在下次更新时改变引用，从而改变 `composer.lock`；被 `exclude-from-classmap` 排除的文件不计入。
- `composer update --no-plugins` 会写入 Composer 自己的引用；不要用它更新依赖这些引用的项目的锁文件。
- 不安全或无法读取的自动加载路径会使更新以 `RuntimeException` 失败，例如 `Autoload paths must not contain ".." segments.`
- 更新之后请运行 `skernel build`：变化了的 `composer.lock` 会使项目的构建元数据过期。

## 文档

开发者文档介绍了哪些包和文件计入、如何引导安装插件、引用如何到达构建，以及每条错误消息：[SuperKernel Composer Source Reference Plugin](https://github.com/super-kernel/.github/blob/main/docs/components/composer-source-reference-plugin.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
