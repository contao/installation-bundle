<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationBundle\Database;

class Version460Update extends AbstractVersionUpdate
{
    /**
     * {@inheritdoc}
     */
    public function shouldBeRun(): bool
    {
        $schemaManager = $this->connection->getSchemaManager();

        $columns = $schemaManager->listTableColumns('tl_module');

        return !isset($columns['searchpages']);
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->connection->query("
            ALTER TABLE
                tl_module
            ADD
                searchPages blob NULL
        ");

        $this->connection->query("
            UPDATE
                tl_module
            SET
                searchPages = CONCAT('a:1:{i:0;i:', rootPage, ';}'),
                rootPage = 0
            WHERE
                type = 'search' AND rootPage != '0'
        ");
    }
}
