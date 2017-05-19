<?php

/*
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2017 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\InstallationBundle\Database;

use Contao\StringUtil;

/**
 * Runs the version 3.3.0 update.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Version330Update extends AbstractVersionUpdate
{
    /**
     * {@inheritdoc}
     */
    public function shouldBeRun()
    {
        $schemaManager = $this->connection->getSchemaManager();

        if (!$schemaManager->tablesExist(['tl_layout'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_layout');

        return !isset($columns['viewport']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $statement = $this->connection->query("SELECT id, framework FROM tl_layout WHERE framework!=''");

        while (false !== ($layout = $statement->fetch(\PDO::FETCH_OBJ))) {
            $framework = '';
            $tmp = StringUtil::deserialize($layout->framework);

            if (!empty($tmp) && is_array($tmp)) {
                if (false !== ($key = array_search('layout.css', $tmp, true))) {
                    array_insert($tmp, $key + 1, 'responsive.css');
                }

                $framework = serialize(array_values(array_unique($tmp)));
            }

            $stmt = $this->connection->prepare('UPDATE tl_layout SET framework=:framework WHERE id=:id');
            $stmt->execute([':framework' => $framework, ':id' => $layout->id]);
        }

        // Add the "viewport" field (triggers the version 3.3 update)
        $this->connection->query("ALTER TABLE `tl_layout` ADD `viewport` varchar(255) NOT NULL default ''");
    }
}
