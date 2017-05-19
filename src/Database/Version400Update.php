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
 * Runs the version 4.0.0 update.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Version400Update extends AbstractVersionUpdate
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

        return !isset($columns['scripts']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->connection->query('ALTER TABLE `tl_layout` ADD `scripts` text NULL');

        // Adjust the framework agnostic scripts
        $statement = $this->connection->query(
            "SELECT id, addJQuery, jquery, addMooTools, mootools FROM tl_layout WHERE framework!=''"
        );

        while (false !== ($layout = $statement->fetch(\PDO::FETCH_OBJ))) {
            $scripts = [];

            // Check if j_slider is enabled
            if ($layout->addJQuery) {
                $jquery = StringUtil::deserialize($layout->jquery);

                if (!empty($jquery) && is_array($jquery)) {
                    if (false !== ($key = array_search('j_slider', $jquery, true))) {
                        $scripts[] = 'js_slider';
                        unset($jquery[$key]);

                        $stmt = $this->connection->prepare('UPDATE tl_layout SET jquery=:jquery WHERE id=:id');
                        $stmt->execute([':jquery' => serialize(array_values($jquery)), ':id' => $layout->id]);
                    }
                }
            }

            // Check if moo_slider is enabled
            if ($layout->addMooTools) {
                $mootools = StringUtil::deserialize($layout->mootools);

                if (!empty($mootools) && is_array($mootools)) {
                    if (false !== ($key = array_search('moo_slider', $mootools, true))) {
                        $scripts[] = 'js_slider';
                        unset($mootools[$key]);

                        $stmt = $this->connection->prepare('UPDATE tl_layout SET mootools=:mootools WHERE id=:id');
                        $stmt->execute([':mootools' => serialize(array_values($mootools)), ':id' => $layout->id]);
                    }
                }
            }

            // Enable the js_slider template
            if (!empty($scripts)) {
                $stmt = $this->connection->prepare('UPDATE tl_layout SET scripts=:scripts WHERE id=:id');
                $stmt->execute([':scripts' => serialize(array_values($scripts)), ':id' => $layout->id]);
            }
        }

        // Replace moo_slimbox with moo_mediabox
        $statement = $this->connection->query("SELECT id, mootools FROM tl_layout WHERE framework!=''");

        while (false !== ($layout = $statement->fetch(\PDO::FETCH_OBJ))) {
            $mootools = StringUtil::deserialize($layout->mootools);

            if (!empty($mootools) && is_array($mootools)) {
                if (false !== ($key = array_search('moo_slimbox', $mootools, true))) {
                    $mootools[] = 'moo_mediabox';
                    unset($mootools[$key]);

                    $stmt = $this->connection->prepare('UPDATE tl_layout SET mootools=:mootools WHERE id=:id');
                    $stmt->execute([':mootools' => serialize(array_values($mootools)), ':id' => $layout->id]);
                }
            }
        }

        // Adjust the list of framework style sheets
        $statement = $this->connection->query("SELECT id, framework FROM tl_layout WHERE framework!=''");

        while (false !== ($layout = $statement->fetch(\PDO::FETCH_OBJ))) {
            $framework = StringUtil::deserialize($layout->framework);

            if (!empty($framework) && is_array($framework)) {
                if (false !== ($key = array_search('tinymce.css', $framework, true))) {
                    unset($framework[$key]);

                    $stmt = $this->connection->prepare('UPDATE tl_layout SET framework=:framework WHERE id=:id');
                    $stmt->execute([':framework' => serialize(array_values($framework)), ':id' => $layout->id]);
                }
            }
        }

        // Adjust the module types
        $this->connection->query("UPDATE tl_module SET type='articlelist' WHERE type='articleList'");
        $this->connection->query("UPDATE tl_module SET type='rssReader' WHERE type='rss_reader'");
        $this->connection->query("UPDATE tl_form_field SET type='explanation' WHERE type='headline'");
    }
}
