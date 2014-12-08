<?php

namespace Alchemy\Phrasea\Plugin\Management;

use Alchemy\Phrasea\Exception\RuntimeException;
use Alchemy\Phrasea\Plugin\Schema\Manifest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Manages plugins assets
 */
class AssetsManager
{
    private $fs;
    private $pluginsDirectory;
    private $rootPath;

    public function __construct(Filesystem $fs, $pluginsDirectory, $rootPath)
    {
        $this->fs = $fs;
        $this->pluginsDirectory = $pluginsDirectory;
        $this->rootPath = $rootPath;
    }

    /**
     * Updates plugins assets so that they are available online.
     * Copy plugin configuration files (initial conf, samples...) where they can be edited
     *
     * @param Manifest $manifest
     *
     * @throws RuntimeException
     */
    public function update(Manifest $manifest)
    {
        try {
            $this->fs->mirror(
                $this->pluginsDirectory . DIRECTORY_SEPARATOR . $manifest->getName() . DIRECTORY_SEPARATOR . 'public',
                $this->rootPath . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $manifest->getName()
            );
        } catch (IOException $e) {
            throw new RuntimeException(
                sprintf('Unable to copy assets for plugin %s', $manifest->getName()), $e->getCode(), $e
            );
        }
        try {
            // copy the "config" dir only if it exists ( = backward compatibility with plugins without "config" dir)
            $src = $this->pluginsDirectory . DIRECTORY_SEPARATOR . $manifest->getName() . DIRECTORY_SEPARATOR . 'config';
            if($this->fs->exists($src)) {
                $this->fs->mirror(
                    $src,
                    $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $manifest->getName()
                );
            }
        } catch (IOException $e) {
            throw new RuntimeException(
                sprintf('Unable to copy config for plugin %s', $manifest->getName()), $e->getCode(), $e
            );
        }
    }

    /**
     * Removes assets for the plugin named with the given name
     * nb : "config" files are NOT removed when plugin is removed ( = allow update without loosing the conf)
     * @param string $name
     *
     * @throws RuntimeException
     */
    public function remove($name)
    {
        try {
            $this->fs->remove($this->rootPath . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $name);
        } catch (IOException $e) {
            throw new RuntimeException(
                sprintf('Unable to remove assets for plugin %s', $name), $e->getCode(), $e
            );
        }
    }

    /**
     * Twig function to generate asset URL.
     *
     * @param string $name
     * @param string $asset
     *
     * @return string
     */
    public static function twigPluginAsset($name, $asset)
    {
        return sprintf('/plugins/%s/%s', $name, ltrim($asset, '/'));
    }
}
