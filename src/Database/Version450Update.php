<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2017 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\InstallationBundle\Database;

/**
 * Runs the version 4.5.0 update.
 *
 * @author David Molineus <https://github.com/dmolineus>
 */
class Version450Update extends AbstractVersionUpdate
{
    /**
     * {@inheritdoc}
     */
    public function shouldBeRun()
    {
        $schemaManager = $this->connection->getSchemaManager();

        if (!$schemaManager->tablesExist(['tl_form_field'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_form_field');

        return isset($columns['fsType']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->connection->query("UPDATE tl_form_field SET type='fieldsetStart' WHERE type='fieldset' AND fsType='fsStart'");
        $this->connection->query("UPDATE tl_form_field SET type='fieldsetStop' WHERE type='fieldset' AND fsType='fsStop'");
    }
}
