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

use Contao\StringUtil;

class Version460Update extends AbstractVersionUpdate
{
    /**
     * {@inheritdoc}
     */
    public function shouldBeRun(): bool
    {
        $schemaManager = $this->connection->getSchemaManager();

        if (!$schemaManager->tablesExist(['tl_content'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_content');

        return !isset($columns['playeroptions']);
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
		// Migrate old ce-access extension structure (see contao/core-bundle#1560)
		$this->migrateCeAccess();

        // Adjust the search module settings (see contao/core-bundle#1462)
        $this->connection->query("
            UPDATE
                tl_module
            SET
                pages = CONCAT('a:1:{i:0;i:', rootPage, ';}'),
                rootPage = 0
            WHERE
                type = 'search' AND rootPage != 0
        ");

        // Activate the "overwriteLink" option (see contao/core-bundle#1459)
        $this->connection->query("
            ALTER TABLE
                tl_content
            ADD
                overwriteLink CHAR(1) DEFAULT '' NOT NULL
        ");

        $this->connection->query("
            UPDATE
                tl_content
            SET
                overwriteLink = '1'
            WHERE
                linkTitle != '' OR titleText != ''
        ");

        // Revert the incorrect version 2.8 update changes
        $this->connection->query('
            UPDATE
                tl_member
            SET
                currentLogin = 0
            WHERE
                currentLogin > 0 AND currentLogin = dateAdded
        ');

        // Remove all activation tokens older than one day to prevent accidental
        // deletion of existing member accounts
        $stmt = $this->connection->prepare("
            UPDATE
                tl_member
            SET
                activation = ''
            WHERE
                activation != '' AND dateAdded < :dateAdded
        ");

        $stmt->execute([':dateAdded' => strtotime('-1 day')]);

        // Update the video element settings (see contao/core-bundle#1348)
        $this->connection->query('
            ALTER TABLE
                tl_content
            ADD
                playerOptions text NULL
        ');

        $this->connection->query('
            ALTER TABLE
                tl_content
            ADD
                vimeoOptions text NULL
        ');

        $statement = $this->connection->query("
            SELECT
                id, type, youtubeOptions
            FROM
                tl_content
            WHERE
                autoplay = '1'
        ");

        while (false !== ($element = $statement->fetch(\PDO::FETCH_OBJ))) {
            switch ($element->type) {
                case 'player':
                    $stmt = $this->connection->prepare('
                        UPDATE
                            tl_content
                        SET
                            playerOptions = :options
                        WHERE
                            id = :id
                    ');

                    $stmt->execute([':options' => serialize(['player_autoplay']), ':id' => $element->id]);
                    break;

                case 'youtube':
                    /** @var array $options */
                    $options = StringUtil::deserialize($element->youtubeOptions);
                    $options[] = 'youtube_autoplay';

                    $stmt = $this->connection->prepare('
                        UPDATE
                            tl_content
                        SET
                            youtubeOptions = :options
                        WHERE
                            id = :id
                    ');

                    $stmt->execute([':options' => serialize($options), ':id' => $element->id]);
                    break;

                case 'vimeo':
                    $stmt = $this->connection->prepare('
                        UPDATE
                            tl_content
                        SET
                            vimeoOptions = :options
                        WHERE
                            id = :id
                    ');

                    $stmt->execute([':options' => serialize(['vimeo_autoplay']), ':id' => $element->id]);
                    break;
            }
        }

        $this->connection->query("
            ALTER TABLE
                tl_content
            ADD
                playerStart int(10) unsigned NOT NULL default '0'
        ");

        $this->connection->query('UPDATE tl_content SET playerStart = youtubeStart');

        $this->connection->query("
            ALTER TABLE
                tl_content
            ADD
                playerStop int(10) unsigned NOT NULL default '0'
        ");

        $this->connection->query('UPDATE tl_content SET playerStop = youtubeStop');
    }

	/**
	 * Replacement for runonce.php migration of ce-access extension (terminal42/contao-ce-access) which has been merged
	 * into contao/core-bundle in version 4.6.0.
	 */
	private function migrateCeAccess(): void
	{
		// v1.* -> v2.0
		$contentElements = array();

		foreach ($GLOBALS['TL_CTE'] as $k => $v) {
			$contentElements[$k] = array();
			foreach ($v as $kk => $vv) {
				$contentElements[$k][] = $kk;
			}
		}

		$this->ceAccessInvertElements('tl_user', $contentElements);
		$this->ceAccessInvertElements('tl_user_group', $contentElements);

		$modules = array();

		// v2.0 -> v2.1
		foreach ($GLOBALS['BE_MOD'] as $moduleConfigs) {
			foreach ($moduleConfigs as $moduleName => $moduleConfig) {
				// Skip modules without tl_content table
				if (!\in_array('tl_content', (array) $moduleConfig['tables'])) {
					continue;
				}

				$modules[] = $moduleName;
			}
		}

		$this->ceAccessGroupElements('tl_user', $modules);
		$this->ceAccessGroupElements('tl_user_group', $modules);
	}

	/**
	 * Convert negative-selection of column 'contentelements' in tl_user_group and tl_user to additive selection in the
	 * column 'elements'
	 *
	 * @param string $table
	 * @param array  $contentElements
	 */
	private function ceAccessInvertElements(string $table, array $contentElements): void
	{
		$columns = $this->connection->getSchemaManager()->listTableColumns($table);

		if (!isset($columns['contentelements']) || isset($columns['elements'])) {
			return;
		}

		// Add the new field to the database table
		$this->connection
			->query("ALTER TABLE $table ADD COLUMN elements blob NULL");

		$records = $this->connection
			->query("SELECT id, contentelements FROM $table WHERE contentelements IS NOT NULL AND contentelements != ''")
			->fetchAll(\PDO::FETCH_OBJ);

		foreach ($records as $record) {
			$elements = StringUtil::deserialize($record->contentelements);
			if (empty($elements) || !\is_array($elements)) {
				continue;
			}

			$elements = array_diff($contentElements, $elements);

			$this->connection->update($table, ['elements' => serialize($elements)], ['id' => $record->id]);
		}
	}

	/**
	 * Group records from the old format "text" to new "article.text"
	 *
	 * @param string $table
	 * @param array  $modules
	 */
	private function ceAccessGroupElements(string $table, array $modules): void
	{
		$records = $this->connection
			->query("SELECT id, elements FROM $table")
			->fetchAll(\PDO::FETCH_OBJ);

		foreach ($records as $record) {
			$elements = deserialize($record->elements, true);

			// The format is already correct
			if (empty($elements) || strpos($elements, '.') !== false) {
				continue;
			}

			// Update the elements
			foreach ($elements as $key => $element) {
				foreach ($modules as $module) {
					$elements[] = $module . '.' . $element;
				}
				unset($elements[$key]);
			}

			$this->connection->update($table, ['elements' => serialize($elements)], ['id' => $record->id]);
		}
	}
}
