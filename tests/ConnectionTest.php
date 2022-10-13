<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mysql\Tests;

use PDO;
use Yiisoft\Cache\CacheKeyNormalizer;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\TestSupport\TestConnectionTrait;
use Yiisoft\Db\Transaction\TransactionInterface;

/**
 * @group mysql
 */
final class ConnectionTest extends TestCase
{
    use TestConnectionTrait;

    public function testConnection(): void
    {
        $this->assertIsObject($this->getConnection());
    }

    public function testInitConnection(): void
    {
        $db = $this->getConnection();
        $db->setEmulatePrepare(true);
        $db->open();
        $this->assertTrue($db->getEmulatePrepare());
        $db->setEmulatePrepare(false);
        $this->assertFalse($db->getEmulatePrepare());
        $db->close();
    }

    public function testGetDriverName(): void
    {
        $db = $this->getConnection();
        $this->assertEquals('mysql', $db->getDriver()->getDriverName());
    }

    public function testQuoteValue(): void
    {
        $db = $this->getConnection();
        $quoter = $db->getQuoter();
        $this->assertEquals(123, $quoter->quoteValue(123));
        $this->assertEquals("'string'", $quoter->quoteValue('string'));
        $this->assertEquals("'It\\'s interesting'", $quoter->quoteValue("It's interesting"));
    }

    public function testQuoteTableName(): void
    {
        $db = $this->getConnection();
        $quoter = $db->getQuoter();
        $this->assertEquals('`table`', $quoter->quoteTableName('table'));
        $this->assertEquals('`table`', $quoter->quoteTableName('`table`'));
        $this->assertEquals('`schema`.`table`', $quoter->quoteTableName('schema.table'));
        $this->assertEquals('`schema`.`table`', $quoter->quoteTableName('schema.`table`'));
        $this->assertEquals('`schema`.`table`', $quoter->quoteTableName('`schema`.`table`'));
        $this->assertEquals('{{table}}', $quoter->quoteTableName('{{table}}'));
        $this->assertEquals('(table)', $quoter->quoteTableName('(table)'));
        $this->assertEquals('`table(0)`', $quoter->quoteTableName('table(0)'));
    }

    public function testQuoteColumnName(): void
    {
        $db = $this->getConnection();
        $quoter = $db->getQuoter();
        $this->assertEquals('`column`', $quoter->quoteColumnName('column'));
        $this->assertEquals('`column`', $quoter->quoteColumnName('`column`'));
        $this->assertEquals('[[column]]', $quoter->quoteColumnName('[[column]]'));
        $this->assertEquals('{{column}}', $quoter->quoteColumnName('{{column}}'));
        $this->assertEquals('(column)', $quoter->quoteColumnName('(column)'));

        $this->assertEquals('`column`', $quoter->quoteSql('[[column]]'));
        $this->assertEquals('`column`', $quoter->quoteSql('{{column}}'));
    }

    public function testQuoteFullColumnName(): void
    {
        $db = $this->getConnection();
        $quoter = $db->getQuoter();
        $this->assertEquals('`table`.`column`', $quoter->quoteColumnName('table.column'));
        $this->assertEquals('`table`.`column`', $quoter->quoteColumnName('table.`column`'));
        $this->assertEquals('`table`.`column`', $quoter->quoteColumnName('`table`.column'));
        $this->assertEquals('`table`.`column`', $quoter->quoteColumnName('`table`.`column`'));

        $this->assertEquals('[[table.column]]', $quoter->quoteColumnName('[[table.column]]'));
        $this->assertEquals('{{table}}.`column`', $quoter->quoteColumnName('{{table}}.column'));
        $this->assertEquals('{{table}}.`column`', $quoter->quoteColumnName('{{table}}.`column`'));
        $this->assertEquals('{{table}}.[[column]]', $quoter->quoteColumnName('{{table}}.[[column]]'));
        $this->assertEquals('{{%table}}.`column`', $quoter->quoteColumnName('{{%table}}.column'));
        $this->assertEquals('{{%table}}.`column`', $quoter->quoteColumnName('{{%table}}.`column`'));

        $this->assertEquals('`table`.`column`', $quoter->quoteSql('[[table.column]]'));
        $this->assertEquals('`table`.`column`', $quoter->quoteSql('{{table}}.[[column]]'));
        $this->assertEquals('`table`.`column`', $quoter->quoteSql('{{table}}.`column`'));
        $this->assertEquals('`table`.`column`', $quoter->quoteSql('{{%table}}.[[column]]'));
        $this->assertEquals('`table`.`column`', $quoter->quoteSql('{{%table}}.`column`'));
    }

    /**
     * Test whether slave connection is recovered when call `getSlavePdo()` after `close()`.
     *
     * {@see https://github.com/yiisoft/yii2/issues/14165}
     */
    public function testGetPdoAfterClose(): void
    {
        $this->markTestSkipped('Only for master/slave');

        $db = $this->getConnection();

        $db->setSlave('1', $this->getConnection());
        $this->assertNotNull($db->getSlavePdo(false));

        $db->close();

        $masterPdo = $db->getMasterPdo();
        $this->assertNotFalse($masterPdo);
        $this->assertNotNull($masterPdo);

        $slavePdo = $db->getSlavePdo(false);
        $this->assertNotFalse($slavePdo);
        $this->assertNotNull($slavePdo);
        $this->assertNotSame($masterPdo, $slavePdo);
    }

    public function testServerStatusCacheWorks(): void
    {
        $db = null;
        $this->markTestSkipped('Only for master/slave');

        $db = $this->getConnection();
        $cacheKeyNormalizer = new CacheKeyNormalizer();

        $db->setMaster('1', $this->getConnection());
        $db->setShuffleMasters(false);

        $cacheKey = $cacheKeyNormalizer->normalize(
            ['Yiisoft\Db\Connection\Connection::openFromPoolSequentially', $db->getDriver()->getDsn()]
        );
        $this->assertFalse($this->cache->psr()->has($cacheKey));

        $db->open();
        $this->assertFalse(
            $this->cache->psr()->has($cacheKey),
            'Connection was successful – cache must not contain information about this DSN'
        );

        $db->close();

        $cacheKey = $cacheKeyNormalizer->normalize(
            ['Yiisoft\Db\Connection\Connection::openFromPoolSequentially', 'host:invalid']
        );

        $db->setMaster('1', $this->getConnection(false, 'host:invalid'));
        $db->setShuffleMasters(true);

        try {
            $db->open();
        } catch (InvalidConfigException) {
        }

        $this->assertTrue(
            $this->cache->psr()->has($cacheKey),
            'Connection was not successful – cache must contain information about this DSN'
        );

        $db->close();
    }

    public function testServerStatusCacheCanBeDisabled(): void
    {
        $db = null;
        $this->markTestSkipped('Only for master/slave');

        $db = $this->getConnection();
        $cacheKeyNormalizer = new CacheKeyNormalizer();

        $db->setMaster('1', $this->getConnection());

        $this->schemaCache->setEnable(false);

        $db->setShuffleMasters(false);

        $cacheKey = $cacheKeyNormalizer->normalize(
            ['Yiisoft\Db\Connection\Connection::openFromPoolSequentially::', $db->getDriver()->getDsn()]
        );
        $this->assertFalse($this->cache->psr()->has($cacheKey));

        $db->open();
        $this->assertFalse($this->cache->psr()->has($cacheKey), 'Caching is disabled');

        $db->close();
        $cacheKey = $cacheKeyNormalizer->normalize(
            ['Yiisoft\Db\Connection\Connection::openFromPoolSequentially', 'host:invalid']
        );

        $db->setMaster('1', $this->getConnection(false, 'host:invalid'));

        try {
            $db->open();
        } catch (InvalidConfigException) {
        }

        $this->assertFalse($this->cache->psr()->has($cacheKey), 'Caching is disabled');

        $db->close();
    }

    public function testTransactionIsolation(): void
    {
        $db = $this->getConnection(true);

        $transaction = $db->beginTransaction(TransactionInterface::READ_UNCOMMITTED);
        $transaction->commit();

        $transaction = $db->beginTransaction(TransactionInterface::READ_COMMITTED);
        $transaction->commit();

        $transaction = $db->beginTransaction(TransactionInterface::REPEATABLE_READ);
        $transaction->commit();

        $transaction = $db->beginTransaction(TransactionInterface::SERIALIZABLE);
        $transaction->commit();

        /* should not be any exception so far */
        $this->assertTrue(true);
    }

    public function testTransactionShortcutCustom(): void
    {
        $db = $this->getConnection(true);

        $result = $db->transaction(static function (ConnectionInterface $db) {
            $db->createCommand()->insert('profile', ['description' => 'test transaction shortcut'])->execute();
            return true;
        }, TransactionInterface::READ_UNCOMMITTED);

        $this->assertTrue($result, 'transaction shortcut valid value should be returned from callback');

        $profilesCount = $db->createCommand(
            "SELECT COUNT(*) FROM profile WHERE description = 'test transaction shortcut';"
        )->queryScalar();

        $this->assertEquals(1, $profilesCount, 'profile should be inserted in transaction shortcut');
    }

    public function testSettingDefaultAttributes(): void
    {
        $db = $this->getConnection();
        $this->assertEquals(PDO::ERRMODE_EXCEPTION, $db->getActivePDO()->getAttribute(PDO::ATTR_ERRMODE));
        $db->close();

        $db->setEmulatePrepare(true);
        $db->open();
        $this->assertEquals(true, $db->getActivePDO()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $db->close();

        $db->setEmulatePrepare(false);
        $db->open();
        $this->assertEquals(false, $db->getActivePDO()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $db->close();
    }
}
