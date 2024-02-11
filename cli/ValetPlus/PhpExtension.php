<?php

declare(strict_types=1);

namespace WeProvide\ValetPlus;

use Valet\Brew;
use Valet\CommandLine;
use Valet\Filesystem;

use function Valet\info;
use function Valet\output;

class PhpExtension
{
    /** @var string */
    protected const PHP_EXTENSIONS_BREW_TAP = 'shivammathur/extensions';

    /** @var string */
    public const XDEBUG_EXTENSION = 'xdebug';
    /** @var string */
    public const APCU_EXTENSION = 'apcu';
    /** @var string */
    public const MEMCACHE_EXTENSION = 'memcached';
    /** @var string */
    public const YAML_EXTENSION = 'yaml';
    /** @var string */
    public const DATASTRUCTURE_EXTENSION = 'ds';
    /** @var string */
    public const IMAGICK_EXTENSION = 'imagick';

    protected const PHP_EXTENSIONS = [
        self::XDEBUG_EXTENSION        => [
            'default'   => false,
            'ini_files' => [
                '20-xdebug'
            ]
        ],
        self::APCU_EXTENSION          => [
            'default'   => true,
            'ini_files' => [
                '20-apcu'
            ]
        ],
        self::MEMCACHE_EXTENSION      => [
            'default'         => false,
            'brew_dependency' => 'libmemcached',
            'ini_files'       => [
                '20-igbinary',
                '20-msgpack',
                '30-memcached'
            ]
        ],
        self::YAML_EXTENSION          => [
            'default'         => true,
            'brew_dependency' => 'libyaml',
            'ini_files'       => [
                '20-yaml'
            ]
        ],
        self::DATASTRUCTURE_EXTENSION => [
            'default'   => true,
            'ini_files' => [
                '20-ds.ini'
            ]
        ],
        self::IMAGICK_EXTENSION       => [
            'default'   => true,
            'ini_files' => [
                '20-imagick.ini'
            ]
        ],
    ];

    /** @var Brew */
    protected $brew;
    /** @var CommandLine */
    protected $cli;
    /** @var Filesystem */
    protected $files;

    /**
     * @param Brew $brew
     * @param CommandLine $cli
     * @param Filesystem $files
     */
    public function __construct(
        Brew $brew,
        CommandLine $cli,
        Filesystem $files
    ) {
        $this->brew  = $brew;
        $this->cli   = $cli;
        $this->files = $files;
    }

    /**
     * @param $phpVersion
     * @param bool $onlyDefaults
     */
    public function installExtensions($phpVersion, $onlyDefaults = true)
    {
        info("Installing extensions for PHP " . $phpVersion);
        foreach (static::PHP_EXTENSIONS as $extension => $settings) {
            if ($onlyDefaults && $this->isDefaultExtension($extension) === false) {
                continue;
            }

            if ($this->installExtension($extension, $phpVersion)) {
            }
        }
    }

    /**
     * Install a single extension if not already installed, default is true and has a version for this PHP version.
     * If version equals false the extension will not be installed for this PHP version.
     *
     * @param $extension
     *    The extension key name.
     * @return bool
     */
    public function installExtension($extension, $phpVersion)
    {
        if ($this->isInstalled($extension, $phpVersion)) {
            output("\t$extension is already installed, skipping...");

            return false;
        }

        return $this->install($extension, $phpVersion);
    }

    /**
     * @param $phpVersion
     * @param $phpIniConfigPath
     */
    public function uninstallExtensions($phpVersion, $phpIniConfigPath)
    {
        info("Uninstalling extensions for PHP " . $phpVersion);
        foreach (static::PHP_EXTENSIONS as $extension => $settings) {
            if ($this->uninstallExtension($extension, $phpVersion, $phpIniConfigPath)) {
            }
        }
    }

    /**
     * @param $extension
     * @param $phpVersion
     * @param $phpIniConfigPath
     * @return bool
     */
    public function uninstallExtension($extension, $phpVersion, $phpIniConfigPath)
    {
        if (!$this->isInstalled($extension, $phpVersion)) {
            output("\t$extension is already uninstalled, skipping...");

            return false;
        }

        return $this->uninstall($extension, $phpVersion, $phpIniConfigPath);
    }

    /**
     * @param $extension
     * @param $phpVersion
     * @return bool
     */
    public function isInstalled($extension, $phpVersion)
    {
        $formula = $this->getExtensionFormula($extension, $phpVersion);

        return $this->brew->installed($formula);
    }

    /**
     * Install a single extension.
     *
     * @param $extension
     *    The extension key name.
     * @param $phpVersion
     * @return bool
     */
    protected function install($extension, $phpVersion)
    {
        $installed = false;

        if ($this->hasBrewDependency($extension)) {
            $dependency = $this->getBrewDependency($extension);
            if (!$this->brew->installed($dependency)) {
                $this->brew->ensureInstalled(
                    $dependency,
                    [],
                    [static::PHP_EXTENSIONS_BREW_TAP]
                );
                $installed = true;
            }
        }

        if (!$this->brew->installed($this->getExtensionFormula($extension, $phpVersion))) {
            $this->brew->ensureInstalled(
                $this->getExtensionFormula($extension, $phpVersion, true),
                [],
                [static::PHP_EXTENSIONS_BREW_TAP]
            );
            $installed = true;
        }

        return $installed;
    }

    /**
     * @param $extension
     * @param $phpVersion
     * @param $phpIniPath
     * @return bool
     */
    protected function uninstall($extension, $phpVersion, $phpIniConfigPath)
    {
        $uninstalled = false;

        if ($this->brew->installed($this->getExtensionFormula($extension, $phpVersion))) {
            $this->removeIniDefinition($extension, $phpIniConfigPath);
            $this->brew->uninstallFormula(
                $this->getExtensionFormula($extension, $phpVersion)
            );
            $this->brew->uninstallFormula(
                $this->getExtensionFormula($extension, $phpVersion, true)
            );
            $uninstalled = true;
        }

        if ($this->hasBrewDependency($extension)) {
            $dependency = $this->getBrewDependency($extension);
            if ($this->brew->installed($dependency)) {
                $this->brew->uninstallFormula(
                    $dependency
                );
                $uninstalled = true;
            }
        }

        return $uninstalled;
    }

    /**
     * Whether this extension should be enabled by default. Is set by setting the
     * 'default' key to true within the extensions configuration.
     *
     * @param $extension
     *    The extension key name.
     * @return bool
     */
    protected function isDefaultExtension($extension)
    {
        if (
            array_key_exists('default', static::PHP_EXTENSIONS[$extension]) &&
            static::PHP_EXTENSIONS[$extension]['default'] === true
        ) {
            return true;
        } elseif (array_key_exists('default', static::PHP_EXTENSIONS[$extension]) === false) {
            return true;
        }

        return false;
    }

    /**
     * Formula example: shivammathur/extensions/xdebug@8.0
     *
     * @param $extension
     * @param $phpVersion
     * @param false $withTap
     * @return string
     */
    protected function getExtensionFormula($extension, $phpVersion, $withTap = false)
    {
        $formula = $extension . '@' . $phpVersion;
        if ($withTap) {
            $formula = static::PHP_EXTENSIONS_BREW_TAP . '/' . $formula;
        }

        return $formula;
    }

    /**
     * Check if the extension has any brew dependency
     *
     * @param mixed $extension
     * @return bool
     */
    protected function hasBrewDependency($extension)
    {
        return array_key_exists("brew_dependency", static::PHP_EXTENSIONS[$extension]);
    }

    /**
     * Get the brew dependency
     *
     * @param mixed $extension
     * @return mixed
     */
    protected function getBrewDependency($extension)
    {
        return static::PHP_EXTENSIONS[$extension]["brew_dependency"];
    }

    /**
     * @param $extension
     * @param $phpIniConfigPath
     */
    protected function removeIniDefinition($extension, $phpIniConfigPath)
    {
        $destDir  = dirname(dirname($phpIniConfigPath)) . '/conf.d/';
        $iniFiles = $this->getIniFiles($extension);
        foreach ($iniFiles as $iniFile) {
            $this->files->unlink($destDir . $iniFile . '.ini');
        }
    }

    /**
     * @param $extension
     * @return array
     */
    protected function getIniFiles($extension)
    {
        if (array_key_exists("ini_files", static::PHP_EXTENSIONS[$extension])) {
            return static::PHP_EXTENSIONS[$extension]['ini_files'];
        }

        return [
            $extension
        ];
    }
}
