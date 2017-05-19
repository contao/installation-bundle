<?php

/*
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2017 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\InstallationBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Adds the bundle services to the container.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ContaoInstallationExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );

        $loader->load('listener.yml');
        $loader->load('services.yml');
    }

    /**
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container)
    {
        $rootDir = $container->getParameter('kernel.root_dir');

        if (file_exists($rootDir.'/config/parameters.yml') || !file_exists($rootDir.'/config/parameters.yml.dist')) {
            return;
        }

        $loader = new YamlFileLoader(
            $container,
            new FileLocator($rootDir.'/config')
        );

        $loader->load('parameters.yml.dist');
    }
}
