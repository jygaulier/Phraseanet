<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic\Indexer;

use Alchemy\Phrasea\Core\PhraseaTokens as Flag;
use collection;
use databox;
use Doctrine\DBAL\Connection;
use PDO;

class RecordQueuer
{
    public static function queueRecordsFromDatabox(databox $databox)
    {
        $connection = $databox->get_connection();

        // Set TO_INDEX flag on all record of this databox
        $sql = 'UPDATE record SET jeton = (jeton | :flag)';
        $stmt = $connection->prepare($sql);
        $stmt->bindValue(':flag', Flag::TO_INDEX, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function queueRecordsFromCollection(collection $collection)
    {
        $connection = $collection->get_connection();

        // Set TO_INDEX flag on all records from this collection
        $sql = "UPDATE record SET jeton = (jeton | :flag) WHERE coll_id = :coll_id";

        $stmt = $connection->prepare($sql);
        $stmt->bindValue(':flag', Flag::TO_INDEX, PDO::PARAM_INT);
        $stmt->bindValue(':coll_id', $collection->get_coll_id(), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array   $records
     * @param databox $databox
     *
     * nb: changing the jeton may affect a fetcher if his "where" clause (delegate) depends on jeton.
     * in this case the client of the fetcher must set a "postFetch" callback and restart the fetcher
     */
    public static function didStartIndexingRecords(array $records, databox $databox)
    {
        $connection = $databox->get_connection();

        self::executeFlagQuery($connection, Flag::TO_INDEX, Flag::INDEXING, $records);
    }

    /**
     * @param array $records
     * @param $databox
     *
     * nb: changing the jeton may affect a fetcher if his "where" clause (delegate) depends on jeton.
     * in this case the client of the fetcher must set a "postFetch" callback and restart the fetcher
     */
    public static function didFinishIndexingRecords(array $records, databox $databox)
    {
        $connection = $databox->get_connection();

        self::executeFlagQuery($connection, Flag::INDEXING, 0, $records);
    }

    private static function executeFlagQuery(Connection $connection, $flag_and, $flag_or, array $records)
    {
        $sql = "UPDATE record SET jeton = ((jeton & ~:flag_and) | :flag_or) WHERE record_id IN (:record_ids)";

        return $connection->executeQuery($sql, array(
            ':flag_and'   => $flag_and,
            ':flag_or'    => $flag_or,
            ':record_ids' => self::arrayPluck($records, 'record_id')
        ), array(
            ':flag_and'   => PDO::PARAM_INT,
            ':flag_or'    => PDO::PARAM_INT,
            ':record_ids' => Connection::PARAM_INT_ARRAY
        ));
    }

    private static function arrayPluck(array $array, $key)
    {
        $values = array();
        foreach ($array as $item) {
            if (isset($item[$key])) {
                $values[] = $item[$key];
            }
        }

        return $values;
    }
}
