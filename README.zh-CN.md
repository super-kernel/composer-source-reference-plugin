<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# Composer Source Reference Plugin

这个 Composer 插件在创建依赖池时，为本地 `super-kernel/*` 路径包生成确定的、随源码变化的 `dist.reference` 值。

## 契约

- 只处理未锁定、`dist.type=path` 且已有 reference 的 `super-kernel/*` 包。
- 生产环境的 `psr-0`、`psr-4`、`classmap` 和 `files` 输入，以排序后的包内相对路径和文件字节参与 reference 计算。
- `exclude-from-classmap` 会移除匹配的 PSR 和 classmap 文件。依赖包的 `autoload-dev` 会被忽略。
- 原始路径仓库的 reference 仍参与哈希，因此 Composer manifest 和仓库选项的变化都会体现出来。
- 绝对文件系统位置、时间戳和 inode 数据从不参与计算。
- 根包元数据保持不变。`composer update --no-plugins` 会明确绕过这一契约。

插件只在 Composer 内部运行。SuperKernel 运行时启动不会额外扫描源码目录。

## 引导与使用

Composer 创建依赖池时，插件必须已经安装。因此，为已有的根项目引入插件需要两次更新：第一次安装插件，第二次重新生成本地包的 reference。引导完成后，一次普通的 `composer update` 即可同时完成依赖解析和源码 reference 刷新。

`--no-plugins` 按定义会禁用源码 reference 刷新，依赖这些 reference 的项目不得用它重新生成根 lock 文件。
