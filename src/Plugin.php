<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use LogicException;
use RuntimeException;

use function getcwd;
use function is_string;
use function realpath;
use function str_starts_with;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private ?SourceReferenceGenerator $generator = null;

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $workingDirectory = getcwd();
        $projectRoot = is_string($workingDirectory) ? realpath($workingDirectory) : false;

        if (!is_string($projectRoot)) {
            throw new RuntimeException('Unable to resolve the Composer project root.');
        }

        $this->generator = new SourceReferenceGenerator($projectRoot);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->generator = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->generator = null;
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        $generator = $this->generator ?? throw new LogicException('The source-reference plugin is not active.');
        $request = $event->getRequest();
        $packages = $event->getPackages();

        foreach ($packages as $package) {
            if (
                $package instanceof AliasPackage
                || !str_starts_with($package->getName(), 'super-kernel/')
                || 'path' !== $package->getDistType()
                || $request->isLockedPackage($package)
                || $request->isFixedPackage($package)
            ) {
                continue;
            }

            $distUrl = $package->getDistUrl();
            $distReference = $package->getDistReference();

            if (!is_string($distUrl) || '' === $distUrl || !is_string($distReference) || '' === $distReference) {
                continue;
            }

            $package->setDistReference($generator->generate(
                $package->getName(),
                $distUrl,
                $distReference,
                $package->getAutoload(),
            ));
        }

        $event->setPackages($packages);
    }
}
