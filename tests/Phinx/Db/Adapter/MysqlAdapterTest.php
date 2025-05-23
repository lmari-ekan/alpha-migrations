<?php
declare(strict_types=1);

namespace Test\Phinx\Db\Adapter;

use PDOException;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Util\Literal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class MysqlAdapterTest extends TestCase
{
    /**
     * @var \Phinx\Db\Adapter\MysqlAdapter
     */
    private $adapter;

    protected function setUp(): void
    {
        if (!defined('MYSQL_DB_CONFIG')) {
            $this->markTestSkipped('Mysql tests disabled.');
        }

        $this->adapter = new MysqlAdapter(MYSQL_DB_CONFIG, new ArrayInput([]), new NullOutput());

        // ensure the database is empty for each test
        $this->adapter->dropDatabase(MYSQL_DB_CONFIG['name']);
        $this->adapter->createDatabase(MYSQL_DB_CONFIG['name']);

        // leave the adapter in a disconnected state for each test
        $this->adapter->disconnect();
    }

    protected function tearDown(): void
    {
        unset($this->adapter);
    }

    private function usingMysql8(): bool
    {
        return version_compare($this->adapter->getAttribute(\PDO::ATTR_SERVER_VERSION), '8.0.0', '>=');
    }

    public function testConnection()
    {
        $this->assertInstanceOf('PDO', $this->adapter->getConnection());
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $this->adapter->getConnection()->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testConnectionWithFetchMode()
    {
        $options = $this->adapter->getOptions();
        $options['fetch_mode'] = 'assoc';
        $this->adapter->setOptions($options);
        $this->assertInstanceOf('PDO', $this->adapter->getConnection());
        $this->assertSame(\PDO::FETCH_ASSOC, $this->adapter->getConnection()->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function testConnectionWithoutPort()
    {
        $options = $this->adapter->getOptions();
        unset($options['port']);
        $this->adapter->setOptions($options);
        $this->assertInstanceOf('PDO', $this->adapter->getConnection());
    }

    public function testConnectionWithInvalidCredentials()
    {
        $options = ['user' => 'invalid', 'pass' => 'invalid'] + MYSQL_DB_CONFIG;

        try {
            $adapter = new MysqlAdapter($options, new ArrayInput([]), new NullOutput());
            $adapter->connect();
            $this->fail('Expected the adapter to throw an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(
                'InvalidArgumentException',
                $e,
                'Expected exception of type InvalidArgumentException, got ' . get_class($e)
            );
            $this->assertStringContainsString('There was a problem connecting to the database', $e->getMessage());
        }
    }

    public function testConnectionWithSocketConnection()
    {
        if (!getenv('MYSQL_UNIX_SOCKET')) {
            $this->markTestSkipped('MySQL socket connection skipped.');
        }

        $options = ['unix_socket' => getenv('MYSQL_UNIX_SOCKET')] + MYSQL_DB_CONFIG;
        $adapter = new MysqlAdapter(MYSQL_DB_CONFIG, new ArrayInput([]), new NullOutput());
        $adapter->connect();

        $this->assertInstanceOf('\PDO', $this->adapter->getConnection());
    }

    public function testCreatingTheSchemaTableOnConnect()
    {
        $this->adapter->connect();
        $this->assertTrue($this->adapter->hasTable($this->adapter->getSchemaTableName()));
        $this->adapter->dropTable($this->adapter->getSchemaTableName());
        $this->assertFalse($this->adapter->hasTable($this->adapter->getSchemaTableName()));
        $this->adapter->disconnect();
        $this->adapter->connect();
        $this->assertTrue($this->adapter->hasTable($this->adapter->getSchemaTableName()));
    }

    public function testSchemaTableIsCreatedWithPrimaryKey()
    {
        $this->adapter->connect();
        $table = new \Phinx\Db\Table($this->adapter->getSchemaTableName(), [], $this->adapter);
        $this->assertTrue($this->adapter->hasIndex($this->adapter->getSchemaTableName(), ['version']));
    }

    public function testQuoteTableName()
    {
        $this->assertEquals('`test_table`', $this->adapter->quoteTableName('test_table'));
    }

    public function testQuoteColumnName()
    {
        $this->assertEquals('`test_column`', $this->adapter->quoteColumnName('test_column'));
    }

    public function testHasTableUnderstandsSchemaNotation()
    {
        $this->assertTrue($this->adapter->hasTable('performance_schema.threads'), 'Failed asserting hasTable understands tables in another schema.');
        $this->assertFalse($this->adapter->hasTable('performance_schema.unknown_table'));
        $this->assertFalse($this->adapter->hasTable('unknown_schema.phinxlog'));
    }

    public function testHasTableRespectsDotInTableName()
    {
        $sql = "CREATE TABLE `discouraged.naming.convention`
                (id INT(11) NOT NULL)
                ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci";
        $this->adapter->execute($sql);
        $this->assertTrue($this->adapter->hasTable('discouraged.naming.convention'));
    }

    public function testCreateTable()
    {
        $table = new \Phinx\Db\Table('ntable', [], $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('email', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'email'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));
    }

    public function testCreateTableWithComment()
    {
        $tableComment = 'Table comment';
        $table = new \Phinx\Db\Table('ntable', ['comment' => $tableComment], $this->adapter);
        $table->addColumn('realname', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='ntable'",
            MYSQL_DB_CONFIG['name']
        ));
        $comment = $rows[0];

        $this->assertEquals($tableComment, $comment['TABLE_COMMENT'], 'Dont set table comment correctly');
    }

    public function testCreateTableWithForeignKeys()
    {
        $tag_table = new \Phinx\Db\Table('ntable_tag', [], $this->adapter);
        $tag_table->addColumn('realname', 'string')
                  ->save();

        $table = new \Phinx\Db\Table('ntable', [], $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('tag_id', 'integer')
              ->addForeignKey('tag_id', 'ntable_tag', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
              ->save();

        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA='%s' AND REFERENCED_TABLE_NAME='ntable_tag'",
            MYSQL_DB_CONFIG['name']
        ));
        $foreignKey = $rows[0];

        $this->assertEquals($foreignKey['TABLE_NAME'], 'ntable');
        $this->assertEquals($foreignKey['COLUMN_NAME'], 'tag_id');
        $this->assertEquals($foreignKey['REFERENCED_TABLE_NAME'], 'ntable_tag');
        $this->assertEquals($foreignKey['REFERENCED_COLUMN_NAME'], 'id');
    }

    public function testCreateTableCustomIdColumn()
    {
        $table = new \Phinx\Db\Table('ntable', ['id' => 'custom_id'], $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('email', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'custom_id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'email'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));
    }

    public function testCreateTableWithNoPrimaryKey()
    {
        $options = [
            'id' => false,
        ];
        $table = new \Phinx\Db\Table('atable', $options, $this->adapter);
        $table->addColumn('user_id', 'integer')
              ->save();
        $this->assertFalse($this->adapter->hasColumn('atable', 'id'));
    }

    public function testCreateTableWithConflictingPrimaryKeys()
    {
        $options = [
            'primary_key' => 'user_id',
        ];
        $table = new \Phinx\Db\Table('atable', $options, $this->adapter);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot enable an auto incrementing ID field and a primary key');
        $table->addColumn('user_id', 'integer')->save();
    }

    public function testCreateTableWithPrimaryKeySetToImplicitId()
    {
        $options = [
            'primary_key' => 'id',
        ];
        $table = new \Phinx\Db\Table('ztable', $options, $this->adapter);
        $table->addColumn('user_id', 'integer')->save();
        $this->assertTrue($this->adapter->hasColumn('ztable', 'id'));
        $this->assertTrue($this->adapter->hasIndex('ztable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ztable', 'user_id'));
    }

    public function testCreateTableWithPrimaryKeyArraySetToImplicitId()
    {
        $options = [
            'primary_key' => ['id'],
        ];
        $table = new \Phinx\Db\Table('ztable', $options, $this->adapter);
        $table->addColumn('user_id', 'integer')->save();
        $this->assertTrue($this->adapter->hasColumn('ztable', 'id'));
        $this->assertTrue($this->adapter->hasIndex('ztable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ztable', 'user_id'));
    }

    public function testCreateTableWithMultiplePrimaryKeyArraySetToImplicitId()
    {
        $options = [
            'primary_key' => ['id', 'user_id'],
        ];
        $table = new \Phinx\Db\Table('ztable', $options, $this->adapter);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot enable an auto incrementing ID field and a primary key');
        $table->addColumn('user_id', 'integer')->save();
    }

    public function testCreateTableWithMultiplePrimaryKeys()
    {
        $options = [
            'id' => false,
            'primary_key' => ['user_id', 'tag_id'],
        ];
        $table = new \Phinx\Db\Table('table1', $options, $this->adapter);
        $table->addColumn('user_id', 'integer')
              ->addColumn('tag_id', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', ['user_id', 'tag_id']));
        $this->assertTrue($this->adapter->hasIndex('table1', ['USER_ID', 'tag_id']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['tag_id', 'user_id']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['tag_id', 'user_email']));
    }

    /**
     * @return void
     */
    public function testCreateTableWithPrimaryKeyAsUuid()
    {
        $options = [
            'id' => false,
            'primary_key' => 'id',
        ];
        $table = new \Phinx\Db\Table('ztable', $options, $this->adapter);
        $table->addColumn('id', 'uuid')->save();
        $table->addColumn('user_id', 'integer')->save();
        $this->assertTrue($this->adapter->hasColumn('ztable', 'id'));
        $this->assertTrue($this->adapter->hasIndex('ztable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ztable', 'user_id'));
    }

    /**
     * @return void
     */
    public function testCreateTableWithPrimaryKeyAsBinaryUuid()
    {
        $options = [
            'id' => false,
            'primary_key' => 'id',
        ];
        $table = new \Phinx\Db\Table('ztable', $options, $this->adapter);
        $table->addColumn('id', 'binaryuuid')->save();
        $table->addColumn('user_id', 'integer')->save();
        $this->assertTrue($this->adapter->hasColumn('ztable', 'id'));
        $this->assertTrue($this->adapter->hasIndex('ztable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ztable', 'user_id'));
    }

    public function testCreateTableWithMultipleIndexes()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addColumn('name', 'string')
              ->addIndex('email')
              ->addIndex('name')
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', ['email']));
        $this->assertTrue($this->adapter->hasIndex('table1', ['name']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['email', 'user_email']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['email', 'user_name']));
    }

    public function testCreateTableWithUniqueIndexes()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', ['unique' => true])
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', ['email']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['email', 'user_email']));
    }

    public function testCreateTableWithFullTextIndex()
    {
        $table = new \Phinx\Db\Table('table1', ['engine' => 'MyISAM'], $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', ['type' => 'fulltext'])
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', ['email']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['email', 'user_email']));
    }

    public function testCreateTableWithNamedIndex()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', ['name' => 'myemailindex'])
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', ['email']));
        $this->assertFalse($this->adapter->hasIndex('table1', ['email', 'user_email']));
        $this->assertTrue($this->adapter->hasIndexByName('table1', 'myemailindex'));
    }

    public function testCreateTableWithMultiplePKsAndUniqueIndexes()
    {
        $this->markTestIncomplete();
    }

    public function testCreateTableWithMyISAMEngine()
    {
        $table = new \Phinx\Db\Table('ntable', ['engine' => 'MyISAM'], $this->adapter);
        $table->addColumn('realname', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $row = $this->adapter->fetchRow(sprintf("SHOW TABLE STATUS WHERE Name = '%s'", 'ntable'));
        $this->assertEquals('MyISAM', $row['Engine']);
    }

    public function testCreateTableAndInheritDefaultCollation()
    {
        $options = MYSQL_DB_CONFIG + [
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
        ];
        $adapter = new MysqlAdapter($options, new ArrayInput([]), new NullOutput());

        // Ensure the database is empty and the adapter is in a disconnected state
        $adapter->dropDatabase($options['name']);
        $adapter->createDatabase($options['name']);
        $adapter->disconnect();

        $table = new \Phinx\Db\Table('table_with_default_collation', [], $adapter);
        $table->addColumn('name', 'string')
              ->save();
        $this->assertTrue($adapter->hasTable('table_with_default_collation'));
        $row = $adapter->fetchRow(sprintf("SHOW TABLE STATUS WHERE Name = '%s'", 'table_with_default_collation'));
        $this->assertEquals('utf8_unicode_ci', $row['Collation']);
    }

    public function testCreateTableWithLatin1Collate()
    {
        $table = new \Phinx\Db\Table('latin1_table', ['collation' => 'latin1_general_ci'], $this->adapter);
        $table->addColumn('name', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasTable('latin1_table'));
        $row = $this->adapter->fetchRow(sprintf("SHOW TABLE STATUS WHERE Name = '%s'", 'latin1_table'));
        $this->assertEquals('latin1_general_ci', $row['Collation']);
    }

    public function testCreateTableWithUnsignedPK()
    {
        $table = new \Phinx\Db\Table('ntable', ['signed' => false], $this->adapter);
        $table->addColumn('realname', 'string')
            ->addColumn('email', 'integer')
            ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'email'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));
        $column_definitions = $this->adapter->getColumns('ntable');
        foreach ($column_definitions as $column_definition) {
            if ($column_definition->getName() === 'id') {
                $this->assertFalse($column_definition->getSigned());
            }
        }
    }

    public function testCreateTableWithUnsignedNamedPK()
    {
        $table = new \Phinx\Db\Table('ntable', ['id' => 'named_id', 'signed' => false], $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('email', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'named_id'));
        $column_definitions = $this->adapter->getColumns('ntable');
        foreach ($column_definitions as $column_definition) {
            if ($column_definition->getName() === 'named_id') {
                $this->assertFalse($column_definition->getSigned());
            }
        }
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'email'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));
    }

    public function testCreateTableWithLimitPK()
    {
        $table = new \Phinx\Db\Table('ntable', ['id' => 'id', 'limit' => 4], $this->adapter);
        $table->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $column_definitions = $this->adapter->getColumns('ntable');
        $this->assertSame($this->usingMysql8() ? null : 4, $column_definitions[0]->getLimit());
    }

    public function testCreateTableWithSchema()
    {
        $table = new \Phinx\Db\Table(MYSQL_DB_CONFIG['name'] . '.ntable', [], $this->adapter);
        $table->addColumn('realname', 'string')
            ->addColumn('email', 'integer')
            ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
    }

    public function testAddPrimarykey()
    {
        $table = new \Phinx\Db\Table('table1', ['id' => false], $this->adapter);
        $table
            ->addColumn('column1', 'integer')
            ->save();

        $table
            ->changePrimaryKey('column1')
            ->save();

        $this->assertTrue($this->adapter->hasPrimaryKey('table1', ['column1']));
    }

    public function testChangePrimaryKey()
    {
        $table = new \Phinx\Db\Table('table1', ['id' => false, 'primary_key' => 'column1'], $this->adapter);
        $table
            ->addColumn('column1', 'integer')
            ->addColumn('column2', 'integer')
            ->addColumn('column3', 'integer')
            ->save();

        $table
            ->changePrimaryKey(['column2', 'column3'])
            ->save();

        $this->assertFalse($this->adapter->hasPrimaryKey('table1', ['column1']));
        $this->assertTrue($this->adapter->hasPrimaryKey('table1', ['column2', 'column3']));
    }

    public function testDropPrimaryKey()
    {
        $table = new \Phinx\Db\Table('table1', ['id' => false, 'primary_key' => 'column1'], $this->adapter);
        $table
            ->addColumn('column1', 'integer')
            ->save();

        $table
            ->changePrimaryKey(null)
            ->save();

        $this->assertFalse($this->adapter->hasPrimaryKey('table1', ['column1']));
    }

    public function testAddComment()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();

        $table
            ->changeComment('comment1')
            ->save();

        $rows = $this->adapter->fetchAll(
            sprintf(
                "SELECT TABLE_COMMENT
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA='%s'
                        AND TABLE_NAME='%s'",
                MYSQL_DB_CONFIG['name'],
                'table1'
            )
        );
        $this->assertEquals('comment1', $rows[0]['TABLE_COMMENT']);
    }

    public function testChangeComment()
    {
        $table = new \Phinx\Db\Table('table1', ['comment' => 'comment1'], $this->adapter);
        $table->save();

        $table
            ->changeComment('comment2')
            ->save();

        $rows = $this->adapter->fetchAll(
            sprintf(
                "SELECT TABLE_COMMENT
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA='%s'
                        AND TABLE_NAME='%s'",
                MYSQL_DB_CONFIG['name'],
                'table1'
            )
        );
        $this->assertEquals('comment2', $rows[0]['TABLE_COMMENT']);
    }

    public function testDropComment()
    {
        $table = new \Phinx\Db\Table('table1', ['comment' => 'comment1'], $this->adapter);
        $table->save();

        $table
            ->changeComment(null)
            ->save();

        $rows = $this->adapter->fetchAll(
            sprintf(
                "SELECT TABLE_COMMENT
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA='%s'
                        AND TABLE_NAME='%s'",
                MYSQL_DB_CONFIG['name'],
                'table1'
            )
        );
        $this->assertEquals('', $rows[0]['TABLE_COMMENT']);
    }

    public function testRenameTable()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertTrue($this->adapter->hasTable('table1'));
        $this->assertFalse($this->adapter->hasTable('table2'));

        $table->rename('table2')->save();
        $this->assertFalse($this->adapter->hasTable('table1'));
        $this->assertTrue($this->adapter->hasTable('table2'));
    }

    public function testAddColumn()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('email'));
        $table->addColumn('email', 'string')
              ->save();
        $this->assertTrue($table->hasColumn('email'));
        $table->addColumn('realname', 'string', ['after' => 'id'])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('realname', $rows[1]['Field']);
    }

    public function testAddColumnWithDefaultValue()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_zero', 'string', ['default' => 'test'])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('test', $rows[1]['Default']);
    }

    public function testAddColumnWithDefaultZero()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_zero', 'integer', ['default' => 0])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertNotNull($rows[1]['Default']);
        $this->assertEquals('0', $rows[1]['Default']);
    }

    public function testAddColumnWithDefaultEmptyString()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_empty', 'string', ['default' => ''])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('', $rows[1]['Default']);
    }

    public function testAddColumnWithDefaultBoolean()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_true', 'boolean', ['default' => true])
              ->addColumn('default_false', 'boolean', ['default' => false])
              ->addColumn('default_null', 'boolean', ['default' => null, 'null' => true])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('1', $rows[1]['Default']);
        $this->assertEquals('0', $rows[2]['Default']);
        $this->assertNull($rows[3]['Default']);
    }

    public function testAddColumnWithDefaultLiteral()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $table->addColumn('default_ts', 'timestamp', ['default' => Literal::from('CURRENT_TIMESTAMP')])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        // MariaDB returns current_timestamp()
        $this->assertTrue($rows[1]['Default'] === 'CURRENT_TIMESTAMP' || $rows[1]['Default'] === 'current_timestamp()');
    }

    public function testAddColumnFirst()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $table->addColumn('new_id', 'integer', ['after' => MysqlAdapter::FIRST])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertSame('new_id', $rows[0]['Field']);
    }

    public function integerDataProvider()
    {
        return [
            ['integer', [], 'int', '11', ''],
            ['integer', ['signed' => false], 'int', '11', ' unsigned'],
            ['integer', ['limit' => 8], 'int', '8', ''],
            ['smallinteger', [], 'smallint', '6', ''],
            ['smallinteger', ['signed' => false], 'smallint', '6', ' unsigned'],
            ['smallinteger', ['limit' => 3], 'smallint', '3', ''],
            ['biginteger', [], 'bigint', '20', ''],
            ['biginteger', ['signed' => false], 'bigint', '20', ' unsigned'],
            ['biginteger', ['limit' => 12], 'bigint', '12', ''],
        ];
    }

    /**
     * @dataProvider integerDataProvider
     */
    public function testIntegerColumnTypes($phinx_type, $options, $sql_type, $width, $extra)
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('user_id', $phinx_type, $options)
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');

        $type = $sql_type;
        if (!$this->usingMysql8()) {
            $type .= '(' . $width . ')';
        }
        $type .= $extra;
        $this->assertEquals($type, $rows[1]['Type']);
    }

    public function testAddDoubleColumnWithDefaultSigned()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('foo', 'double')
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('double', $rows[1]['Type']);
    }

    public function testAddDoubleColumnWithSignedEqualsFalse()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('foo', 'double', ['signed' => false])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('double unsigned', $rows[1]['Type']);
    }

    public function testAddBooleanColumnWithSignedEqualsFalse()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('test_boolean'));
        $table->addColumn('test_boolean', 'boolean', ['signed' => false])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');

        $type = $this->usingMysql8() ? 'tinyint' : 'tinyint(1)';
        $this->assertEquals($type . ' unsigned', $rows[1]['Type']);
    }

    public function testAddStringColumnWithSignedEqualsFalse()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('user_id', 'string', ['signed' => false])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('varchar(255)', $rows[1]['Type']);
    }

    public function testAddStringColumnWithCustomCollation()
    {
        $table = new \Phinx\Db\Table('table_custom_collation', ['collation' => 'utf8_general_ci'], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('string_collation_default'));
        $this->assertFalse($table->hasColumn('string_collation_custom'));
        $table->addColumn('string_collation_default', 'string', [])->save();
        $table->addColumn('string_collation_custom', 'string', ['collation' => 'utf8mb4_unicode_ci'])->save();
        $rows = $this->adapter->fetchAll('SHOW FULL COLUMNS FROM table_custom_collation');
        $this->assertEquals('utf8_general_ci', $rows[1]['Collation']);
        $this->assertEquals('utf8mb4_unicode_ci', $rows[2]['Collation']);
    }

    public function testRenameColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $this->assertFalse($this->adapter->hasColumn('t', 'column2'));

        $table->renameColumn('column1', 'column2')->save();
        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
        $this->assertTrue($this->adapter->hasColumn('t', 'column2'));
    }

    public function testRenameColumnPreserveComment()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string', ['comment' => 'comment1'])
              ->save();

        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $this->assertFalse($this->adapter->hasColumn('t', 'column2'));
        $columns = $this->adapter->fetchAll('SHOW FULL COLUMNS FROM t');
        $this->assertEquals('comment1', $columns[1]['Comment']);

        $table->renameColumn('column1', 'column2')->save();

        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
        $this->assertTrue($this->adapter->hasColumn('t', 'column2'));
        $columns = $this->adapter->fetchAll('SHOW FULL COLUMNS FROM t');
        $this->assertEquals('comment1', $columns[1]['Comment']);
    }

    public function testRenamingANonExistentColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();

        try {
            $table->renameColumn('column2', 'column1')->save();
            $this->fail('Expected the adapter to throw an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(
                'InvalidArgumentException',
                $e,
                'Expected exception of type InvalidArgumentException, got ' . get_class($e)
            );
            $this->assertEquals('The specified column doesn\'t exist: column2', $e->getMessage());
        }
    }

    public function testChangeColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $table->changeColumn('column1', 'string')->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));

        $newColumn2 = new \Phinx\Db\Table\Column();
        $newColumn2->setName('column2')
                   ->setType('string');
        $table->changeColumn('column1', $newColumn2)->save();
        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
        $this->assertTrue($this->adapter->hasColumn('t', 'column2'));
    }

    public function testChangeColumnDefaultValue()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string', ['default' => 'test'])
              ->save();
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setDefault('test1')
                   ->setType('string');
        $table->changeColumn('column1', $newColumn1)->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM t');
        $this->assertNotNull($rows[1]['Default']);
        $this->assertEquals('test1', $rows[1]['Default']);
    }

    public function testChangeColumnDefaultToZero()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer')
              ->save();
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setDefault(0)
                   ->setType('integer');
        $table->changeColumn('column1', $newColumn1)->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM t');
        $this->assertNotNull($rows[1]['Default']);
        $this->assertEquals('0', $rows[1]['Default']);
    }

    public function testChangeColumnDefaultToNull()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string', ['default' => 'test'])
              ->save();
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setDefault(null)
                   ->setType('string');
        $table->changeColumn('column1', $newColumn1)->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM t');
        $this->assertNull($rows[1]['Default']);
    }

    public function testLongTextColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'text', ['limit' => MysqlAdapter::TEXT_LONG])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('longtext', $sqlType['name']);
    }

    public function testMediumTextColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('mediumtext', $sqlType['name']);
    }

    public function testTinyTextColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'text', ['limit' => MysqlAdapter::TEXT_TINY])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('tinytext', $sqlType['name']);
    }

    public function binaryToBlobAutomaticConversionData()
    {
        return [
          [null, 'binary', 255],
          [64, 'binary', 64],
          [MysqlAdapter::BLOB_REGULAR - 20, 'blob', MysqlAdapter::BLOB_REGULAR],
          [MysqlAdapter::BLOB_REGULAR, 'blob', MysqlAdapter::BLOB_REGULAR],
          [MysqlAdapter::BLOB_REGULAR + 20, 'mediumblob', MysqlAdapter::BLOB_MEDIUM],
          [MysqlAdapter::BLOB_MEDIUM, 'mediumblob', MysqlAdapter::BLOB_MEDIUM],
          [MysqlAdapter::BLOB_MEDIUM + 20, 'longblob', MysqlAdapter::BLOB_LONG],
          [MysqlAdapter::BLOB_LONG, 'longblob', MysqlAdapter::BLOB_LONG],
          [MysqlAdapter::BLOB_LONG + 20, 'longblob', MysqlAdapter::BLOB_LONG],
        ];
    }

    /** @dataProvider binaryToBlobAutomaticConversionData */
    public function testBinaryToBlobAutomaticConversion(?int $limit = null, string $expectedType, int $expectedLimit)
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'binary', ['limit' => $limit])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertSame($expectedType, $sqlType['name']);
        $this->assertSame($expectedLimit, $columns[1]->getLimit());
    }

    public function varbinaryToBlobAutomaticConversionData()
    {
        return [
          [null, 'varbinary', 255],
          [64, 'varbinary', 64],
          [MysqlAdapter::BLOB_REGULAR - 20, 'blob', MysqlAdapter::BLOB_REGULAR],
          [MysqlAdapter::BLOB_REGULAR, 'blob', MysqlAdapter::BLOB_REGULAR],
          [MysqlAdapter::BLOB_REGULAR + 20, 'mediumblob', MysqlAdapter::BLOB_MEDIUM],
          [MysqlAdapter::BLOB_MEDIUM, 'mediumblob', MysqlAdapter::BLOB_MEDIUM],
          [MysqlAdapter::BLOB_MEDIUM + 20, 'longblob', MysqlAdapter::BLOB_LONG],
          [MysqlAdapter::BLOB_LONG, 'longblob', MysqlAdapter::BLOB_LONG],
          [MysqlAdapter::BLOB_LONG + 20, 'longblob', MysqlAdapter::BLOB_LONG],
        ];
    }

    /** @dataProvider varbinaryToBlobAutomaticConversionData */
    public function testVarbinaryToBlobAutomaticConversion(?int $limit = null, string $expectedType, int $expectedLimit)
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'varbinary', ['limit' => $limit])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertSame($expectedType, $sqlType['name']);
        $this->assertSame($expectedLimit, $columns[1]->getLimit());
    }

    public function blobColumnsData()
    {
        return [
          // Tiny blobs
          ['tinyblob', 'tinyblob', null, MysqlAdapter::BLOB_TINY],
          ['tinyblob', 'tinyblob', MysqlAdapter::BLOB_TINY, MysqlAdapter::BLOB_TINY],
          ['tinyblob', 'blob', MysqlAdapter::BLOB_TINY + 20, MysqlAdapter::BLOB_REGULAR],
          ['tinyblob', 'mediumblob', MysqlAdapter::BLOB_MEDIUM, MysqlAdapter::BLOB_MEDIUM],
          ['tinyblob', 'longblob', MysqlAdapter::BLOB_LONG, MysqlAdapter::BLOB_LONG],
          // Regular blobs
          ['blob', 'tinyblob', MysqlAdapter::BLOB_TINY, MysqlAdapter::BLOB_TINY],
          ['blob', 'blob', null, MysqlAdapter::BLOB_REGULAR],
          ['blob', 'blob', MysqlAdapter::BLOB_REGULAR, MysqlAdapter::BLOB_REGULAR],
          ['blob', 'mediumblob', MysqlAdapter::BLOB_MEDIUM, MysqlAdapter::BLOB_MEDIUM],
          ['blob', 'longblob', MysqlAdapter::BLOB_LONG, MysqlAdapter::BLOB_LONG],
          // medium blobs
          ['mediumblob', 'tinyblob', MysqlAdapter::BLOB_TINY, MysqlAdapter::BLOB_TINY],
          ['mediumblob', 'blob', MysqlAdapter::BLOB_REGULAR, MysqlAdapter::BLOB_REGULAR],
          ['mediumblob', 'mediumblob', null, MysqlAdapter::BLOB_MEDIUM],
          ['mediumblob', 'mediumblob', MysqlAdapter::BLOB_MEDIUM, MysqlAdapter::BLOB_MEDIUM],
          ['mediumblob', 'longblob', MysqlAdapter::BLOB_LONG, MysqlAdapter::BLOB_LONG],
          // long blobs
          ['longblob', 'tinyblob', MysqlAdapter::BLOB_TINY, MysqlAdapter::BLOB_TINY],
          ['longblob', 'blob', MysqlAdapter::BLOB_REGULAR, MysqlAdapter::BLOB_REGULAR],
          ['longblob', 'mediumblob', MysqlAdapter::BLOB_MEDIUM, MysqlAdapter::BLOB_MEDIUM],
          ['longblob', 'longblob', null, MysqlAdapter::BLOB_LONG],
          ['longblob', 'longblob', MysqlAdapter::BLOB_LONG, MysqlAdapter::BLOB_LONG],
        ];
    }

    /** @dataProvider blobColumnsData */
    public function testblobColumns(string $type, string $expectedType, ?int $limit = null, int $expectedLimit)
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', $type, ['limit' => $limit])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertSame($expectedType, $sqlType['name']);
        $this->assertSame($expectedLimit, $columns[1]->getLimit());
    }

    public function testBigIntegerColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer', ['limit' => MysqlAdapter::INT_BIG])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('bigint', $sqlType['name']);
    }

    public function testMediumIntegerColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer', ['limit' => MysqlAdapter::INT_MEDIUM])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('mediumint', $sqlType['name']);
    }

    public function testSmallIntegerColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer', ['limit' => MysqlAdapter::INT_SMALL])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('smallint', $sqlType['name']);
    }

    public function testTinyIntegerColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer', ['limit' => MysqlAdapter::INT_TINY])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals('tinyint', $sqlType['name']);
    }

    public function testIntegerColumnLimit()
    {
        $limit = 8;
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'integer', ['limit' => $limit])
              ->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals($this->usingMysql8() ? 11 : $limit, $sqlType['limit']);
    }

    public function testDatetimeColumn()
    {
        $this->adapter->connect();
        if (version_compare($this->adapter->getAttribute(\PDO::ATTR_SERVER_VERSION), '5.6.4') === -1) {
            $this->markTestSkipped('Cannot test datetime limit on versions less than 5.6.4');
        }
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'datetime')->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertNull($sqlType['limit']);
    }

    public function testDatetimeColumnLimit()
    {
        $this->adapter->connect();
        if (version_compare($this->adapter->getAttribute(\PDO::ATTR_SERVER_VERSION), '5.6.4') === -1) {
            $this->markTestSkipped('Cannot test datetime limit on versions less than 5.6.4');
        }
        $limit = 6;
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'datetime', ['limit' => $limit])->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals($limit, $sqlType['limit']);
    }

    public function testTimeColumnLimit()
    {
        $this->adapter->connect();
        if (version_compare($this->adapter->getAttribute(\PDO::ATTR_SERVER_VERSION), '5.6.4') === -1) {
            $this->markTestSkipped('Cannot test datetime limit on versions less than 5.6.4');
        }
        $limit = 3;
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'time', ['limit' => $limit])->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals($limit, $sqlType['limit']);
    }

    public function testTimestampColumnLimit()
    {
        $this->adapter->connect();
        if (version_compare($this->adapter->getAttribute(\PDO::ATTR_SERVER_VERSION), '5.6.4') === -1) {
            $this->markTestSkipped('Cannot test datetime limit on versions less than 5.6.4');
        }
        $limit = 1;
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'timestamp', ['limit' => $limit])->save();
        $columns = $table->getColumns();
        $sqlType = $this->adapter->getSqlType($columns[1]->getType(), $columns[1]->getLimit());
        $this->assertEquals($limit, $sqlType['limit']);
    }

    public function testTimestampInvalidLimit()
    {
        $this->adapter->connect();
        if (version_compare($this->adapter->getAttribute(\PDO::ATTR_SERVER_VERSION), '5.6.4') === -1) {
            $this->markTestSkipped('Cannot test datetime limit on versions less than 5.6.4');
        }
        $table = new \Phinx\Db\Table('t', [], $this->adapter);

        $this->expectException(PDOException::class);

        $table->addColumn('column1', 'timestamp', ['limit' => 7])->save();
    }

    public function testDropColumn()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));

        $table->removeColumn('column1')->save();
        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
    }

    public function columnsProvider()
    {
        return [
            ['column1', 'string', []],
            ['column2', 'smallinteger', []],
            ['column3', 'integer', []],
            ['column4', 'biginteger', []],
            ['column5', 'text', []],
            ['column6', 'float', []],
            ['column7', 'decimal', []],
            ['decimal_precision_scale', 'decimal', ['precision' => 10, 'scale' => 2]],
            ['decimal_limit', 'decimal', ['limit' => 10]],
            ['decimal_precision', 'decimal', ['precision' => 10]],
            ['column8', 'datetime', []],
            ['column9', 'time', []],
            ['column10', 'timestamp', []],
            ['column11', 'date', []],
            ['column12', 'binary', []],
            ['column13', 'boolean', []],
            ['column14', 'string', ['limit' => 10]],
            ['column16', 'geometry', []],
            ['column17', 'point', []],
            ['column18', 'linestring', []],
            ['column19', 'polygon', []],
            ['column20', 'uuid', []],
            ['column21', 'set', ['values' => ['one', 'two']]],
            ['column22', 'enum', ['values' => ['three', 'four']]],
            ['enum_quotes', 'enum', ['values' => [
                "'", '\'\n', '\\', ',', '', "\\\n", '\\n', "\n", "\r", "\r\n", '/', ',,', "\t",
            ]]],
            ['column23', 'bit', []],
        ];
    }

    /**
     * @dataProvider columnsProvider
     */
    public function testGetColumns($colName, $type, $options)
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn($colName, $type, $options)->save();

        $columns = $this->adapter->getColumns('t');
        $this->assertCount(2, $columns);
        $this->assertEquals($colName, $columns[1]->getName());
        $this->assertEquals($type, $columns[1]->getType());

        if (isset($options['limit'])) {
            $this->assertEquals($options['limit'], $columns[1]->getLimit());
        }

        if (isset($options['values'])) {
            $this->assertEquals($options['values'], $columns[1]->getValues());
        }

        if (isset($options['precision'])) {
            $this->assertEquals($options['precision'], $columns[1]->getPrecision());
        }

        if (isset($options['scale'])) {
            $this->assertEquals($options['scale'], $columns[1]->getScale());
        }
    }

    public function testGetColumnsInteger()
    {
        $colName = 'column15';
        $type = 'integer';
        $options = ['limit' => 10];
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn($colName, $type, $options)->save();

        $columns = $this->adapter->getColumns('t');
        $this->assertCount(2, $columns);
        $this->assertEquals($colName, $columns[1]->getName());
        $this->assertEquals($type, $columns[1]->getType());

        $this->assertEquals($this->usingMysql8() ? null : 10, $columns[1]->getLimit());
    }

    public function testDescribeTable()
    {
        $table = new \Phinx\Db\Table('t', [], $this->adapter);
        $table->addColumn('column1', 'string');
        $table->save();

        $described = $this->adapter->describeTable('t');

        $this->assertContains($described['TABLE_TYPE'], ['VIEW', 'BASE TABLE']);
        $this->assertEquals($described['TABLE_NAME'], 't');
        $this->assertEquals($described['TABLE_SCHEMA'], MYSQL_DB_CONFIG['name']);
        $this->assertEquals($described['TABLE_ROWS'], 0);
    }

    public function testGetColumnsReservedTableName()
    {
        $table = new \Phinx\Db\Table('group', [], $this->adapter);
        $table->addColumn('column1', 'string')->save();
        $columns = $this->adapter->getColumns('group');
        $this->assertCount(2, $columns);
    }

    public function testAddIndex()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->save();
        $this->assertFalse($table->hasIndex('email'));
        $table->addIndex('email')
              ->save();
        $this->assertTrue($table->hasIndex('email'));
    }

    public function testAddIndexWithSort()
    {
        $this->adapter->connect();
        if (!$this->usingMysql8()) {
            $this->markTestSkipped('Cannot test index order on mysql versions less than 8');
        }
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addColumn('username', 'string')
              ->save();
        $this->assertFalse($table->hasIndexByName('table1_email_username'));
        $table->addIndex(['email', 'username'], ['name' => 'table1_email_username', 'order' => ['email' => 'DESC', 'username' => 'ASC']])
              ->save();
        $this->assertTrue($table->hasIndexByName('table1_email_username'));
        $rows = $this->adapter->fetchAll("SHOW INDEXES FROM table1 WHERE Key_name = 'table1_email_username' AND Column_name = 'email'");
        $emailOrder = $rows[0]['Collation'];
        $this->assertEquals($emailOrder, 'D');

        $rows = $this->adapter->fetchAll("SHOW INDEXES FROM table1 WHERE Key_name = 'table1_email_username' AND Column_name = 'username'");
        $emailOrder = $rows[0]['Collation'];
        $this->assertEquals($emailOrder, 'A');
    }

    public function testAddMultipleFulltextIndex()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addColumn('username', 'string')
              ->addColumn('bio', 'text')
              ->save();
        $this->assertFalse($table->hasIndex('email'));
        $this->assertFalse($table->hasIndex('username'));
        $this->assertFalse($table->hasIndex('address'));
        $table->addIndex('email')
              ->addIndex('username', ['type' => 'fulltext'])
              ->addIndex('bio', ['type' => 'fulltext'])
              ->addIndex(['email', 'bio'], ['type' => 'fulltext'])
              ->save();
        $this->assertTrue($table->hasIndex('email'));
        $this->assertTrue($table->hasIndex('username'));
        $this->assertTrue($table->hasIndex('bio'));
        $this->assertTrue($table->hasIndex(['email', 'bio']));
    }

    public function testAddIndexWithLimit()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
            ->save();
        $this->assertFalse($table->hasIndex('email'));
        $table->addIndex('email', ['limit' => 50])
            ->save();
        $this->assertTrue($table->hasIndex('email'));
        $index_data = $this->adapter->query(sprintf(
            'SELECT SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = "%s" AND TABLE_NAME = "table1" AND INDEX_NAME = "email"',
            MYSQL_DB_CONFIG['name']
        ))->fetch(\PDO::FETCH_ASSOC);
        $expected_limit = $index_data['SUB_PART'];
        $this->assertEquals($expected_limit, 50);
    }

    public function testAddMultiIndexesWithLimitSpecifier()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addColumn('username', 'string')
              ->save();
        $this->assertFalse($table->hasIndex(['email', 'username']));
        $table->addIndex(['email', 'username'], ['limit' => [ 'email' => 3, 'username' => 2 ]])
              ->save();
        $this->assertTrue($table->hasIndex(['email', 'username']));
        $index_data = $this->adapter->query(sprintf(
            'SELECT SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = "%s" AND TABLE_NAME = "table1" AND INDEX_NAME = "email" AND COLUMN_NAME = "email"',
            MYSQL_DB_CONFIG['name']
        ))->fetch(\PDO::FETCH_ASSOC);
        $expected_limit = $index_data['SUB_PART'];
        $this->assertEquals($expected_limit, 3);
        $index_data = $this->adapter->query(sprintf(
            'SELECT SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = "%s" AND TABLE_NAME = "table1" AND INDEX_NAME = "email" AND COLUMN_NAME = "username"',
            MYSQL_DB_CONFIG['name']
        ))->fetch(\PDO::FETCH_ASSOC);
        $expected_limit = $index_data['SUB_PART'];
        $this->assertEquals($expected_limit, 2);
    }

    public function testAddSingleIndexesWithLimitSpecifier()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
            ->addColumn('username', 'string')
            ->save();
        $this->assertFalse($table->hasIndex('email'));
        $table->addIndex('email', ['limit' => [ 'email' => 3, 2 ]])
            ->save();
        $this->assertTrue($table->hasIndex('email'));
        $index_data = $this->adapter->query(sprintf(
            'SELECT SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = "%s" AND TABLE_NAME = "table1" AND INDEX_NAME = "email" AND COLUMN_NAME = "email"',
            MYSQL_DB_CONFIG['name']
        ))->fetch(\PDO::FETCH_ASSOC);
        $expected_limit = $index_data['SUB_PART'];
        $this->assertEquals($expected_limit, 3);
    }

    public function testDropIndex()
    {
        // single column index
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email')
              ->save();
        $this->assertTrue($table->hasIndex('email'));
        $table->removeIndex(['email'])->save();
        $this->assertFalse($table->hasIndex('email'));

        // multiple column index
        $table2 = new \Phinx\Db\Table('table2', [], $this->adapter);
        $table2->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(['fname', 'lname'])
               ->save();
        $this->assertTrue($table2->hasIndex(['fname', 'lname']));
        $table2->removeIndex(['fname', 'lname'])->save();
        $this->assertFalse($table2->hasIndex(['fname', 'lname']));

        // index with name specified, but dropping it by column name
        $table3 = new \Phinx\Db\Table('table3', [], $this->adapter);
        $table3->addColumn('email', 'string')
              ->addIndex('email', ['name' => 'someindexname'])
              ->save();
        $this->assertTrue($table3->hasIndex('email'));
        $table3->removeIndex(['email'])->save();
        $this->assertFalse($table3->hasIndex('email'));

        // multiple column index with name specified
        $table4 = new \Phinx\Db\Table('table4', [], $this->adapter);
        $table4->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(['fname', 'lname'], ['name' => 'multiname'])
               ->save();
        $this->assertTrue($table4->hasIndex(['fname', 'lname']));
        $table4->removeIndex(['fname', 'lname'])->save();
        $this->assertFalse($table4->hasIndex(['fname', 'lname']));

        // don't drop multiple column index when dropping single column
        $table2 = new \Phinx\Db\Table('table5', [], $this->adapter);
        $table2->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(['fname', 'lname'])
               ->save();
        $this->assertTrue($table2->hasIndex(['fname', 'lname']));

        try {
            $table2->removeIndex(['fname'])->save();
        } catch (\InvalidArgumentException $e) {
        }
        $this->assertTrue($table2->hasIndex(['fname', 'lname']));

        // don't drop multiple column index with name specified when dropping
        // single column
        $table4 = new \Phinx\Db\Table('table6', [], $this->adapter);
        $table4->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(['fname', 'lname'], ['name' => 'multiname'])
               ->save();
        $this->assertTrue($table4->hasIndex(['fname', 'lname']));

        try {
            $table4->removeIndex(['fname'])->save();
        } catch (\InvalidArgumentException $e) {
        }

        $this->assertTrue($table4->hasIndex(['fname', 'lname']));
    }

    public function testDropIndexByName()
    {
        // single column index
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', ['name' => 'myemailindex'])
              ->save();
        $this->assertTrue($table->hasIndex('email'));
        $table->removeIndexByName('myemailindex')->save();
        $this->assertFalse($table->hasIndex('email'));

        // multiple column index
        $table2 = new \Phinx\Db\Table('table2', [], $this->adapter);
        $table2->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(['fname', 'lname'], ['name' => 'twocolumnindex'])
               ->save();
        $this->assertTrue($table2->hasIndex(['fname', 'lname']));
        $table2->removeIndexByName('twocolumnindex')->save();
        $this->assertFalse($table2->hasIndex(['fname', 'lname']));
    }

    public function testAddForeignKey()
    {
        $refTable = new \Phinx\Db\Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), ['ref_table_id']));
    }

    public function testAddForeignKeyForTableWithUnsignedPK()
    {
        $refTable = new \Phinx\Db\Table('ref_table', ['signed' => false], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer', ['signed' => false])
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), ['ref_table_id']));
    }

    public function testDropForeignKey()
    {
        $refTable = new \Phinx\Db\Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $table->dropForeignKey(['ref_table_id'])->save();
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), ['ref_table_id']));
    }

    public function testDropForeignKeyForTableWithUnsignedPK()
    {
        $refTable = new \Phinx\Db\Table('ref_table', ['signed' => false], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer', ['signed' => false])
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $table->dropForeignKey(['ref_table_id'])->save();
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), ['ref_table_id']));
    }

    public function testDropForeignKeyAsString()
    {
        $refTable = new \Phinx\Db\Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $table->dropForeignKey('ref_table_id')->save();
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), ['ref_table_id']));
    }

    public function testHasForeignKeyAsString()
    {
        $refTable = new \Phinx\Db\Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), 'ref_table_id'));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), 'ref_table_id2'));
    }

    public function testHasForeignKeyWithConstraint()
    {
        $refTable = new \Phinx\Db\Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKeyWithName('my_constraint', ['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), ['ref_table_id'], 'my_constraint'));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), ['ref_table_id'], 'my_constraint2'));
    }

    public function testHasForeignKeyWithConstraintForTableWithUnsignedPK()
    {
        $refTable = new \Phinx\Db\Table('ref_table', ['signed' => false], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer', ['signed' => false])
            ->addForeignKeyWithName('my_constraint', ['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), ['ref_table_id'], 'my_constraint'));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), ['ref_table_id'], 'my_constraint2'));
    }

    public function testsHasForeignKeyWithSchemaDotTableName()
    {
        $refTable = new \Phinx\Db\Table('ref_table', [], $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', [], $this->adapter);
        $table
            ->addColumn('ref_table_id', 'integer')
            ->addForeignKey(['ref_table_id'], 'ref_table', ['id'])
            ->save();

        $this->assertTrue($this->adapter->hasForeignKey(MYSQL_DB_CONFIG['name'] . '.' . $table->getName(), ['ref_table_id']));
        $this->assertFalse($this->adapter->hasForeignKey(MYSQL_DB_CONFIG['name'] . '.' . $table->getName(), ['ref_table_id2']));
    }

    public function testHasDatabase()
    {
        $this->assertFalse($this->adapter->hasDatabase('fake_database_name'));
        $this->assertTrue($this->adapter->hasDatabase(MYSQL_DB_CONFIG['name']));
    }

    public function testDropDatabase()
    {
        $this->assertFalse($this->adapter->hasDatabase('phinx_temp_database'));
        $this->adapter->createDatabase('phinx_temp_database');
        $this->assertTrue($this->adapter->hasDatabase('phinx_temp_database'));
        $this->adapter->dropDatabase('phinx_temp_database');
    }

    public function testAddColumnWithComment()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('column1', 'string', ['comment' => $comment = 'Comments from "column1"'])
              ->save();

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT COLUMN_NAME, COLUMN_COMMENT
            FROM information_schema.columns
            WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='table1'
            ORDER BY ORDINAL_POSITION",
            MYSQL_DB_CONFIG['name']
        ));
        $columnWithComment = $rows[1];

        $this->assertSame('column1', $columnWithComment['COLUMN_NAME'], "Didn't set column name correctly");
        $this->assertEquals($comment, $columnWithComment['COLUMN_COMMENT'], "Didn't set column comment correctly");
    }

    public function testAddGeoSpatialColumns()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('geo_geom'));
        $table->addColumn('geo_geom', 'geometry')
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('geometry', $rows[1]['Type']);
    }

    public function testAddSetColumn()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('set_column'));
        $table->addColumn('set_column', 'set', ['values' => ['one', 'two']])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals("set('one','two')", $rows[1]['Type']);
    }

    public function testAddEnumColumn()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('enum_column'));
        $table->addColumn('enum_column', 'enum', ['values' => ['one', 'two']])
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals("enum('one','two')", $rows[1]['Type']);
    }

    public function testEnumColumnValuesFilledUpFromSchema()
    {
        // Creating column with values
        (new \Phinx\Db\Table('table1', [], $this->adapter))
            ->addColumn('enum_column', 'enum', ['values' => ['one', 'two']])
            ->save();

        // Reading them back
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $columns = $table->getColumns();
        $enumColumn = end($columns);
        $this->assertEquals(AdapterInterface::PHINX_TYPE_ENUM, $enumColumn->getType());
        $this->assertEquals(['one', 'two'], $enumColumn->getValues());
    }

    public function testEnumColumnWithNullValue()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('enum_column', 'enum', ['values' => ['one', 'two', null]]);

        $this->expectException(PDOException::class);
        $table->save();
    }

    public function testHasColumn()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();

        $this->assertFalse($table->hasColumn('column2'));
        $this->assertTrue($table->hasColumn('column1'));
    }

    public function testHasColumnReservedName()
    {
        $tableQuoted = new \Phinx\Db\Table('group', [], $this->adapter);
        $tableQuoted->addColumn('value', 'string')
                    ->save();

        $this->assertFalse($tableQuoted->hasColumn('column2'));
        $this->assertTrue($tableQuoted->hasColumn('value'));
    }

    public function testBulkInsertData()
    {
        $data = [
            [
                'column1' => 'value1',
                'column2' => 1,
            ],
            [
                'column1' => 'value2',
                'column2' => 2,
            ],
            [
                'column1' => 'value3',
                'column2' => 3,
            ],
        ];
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('column1', 'string')
            ->addColumn('column2', 'integer')
            ->addColumn('column3', 'string', ['default' => 'test'])
            ->insert($data)
            ->save();

        $rows = $this->adapter->fetchAll('SELECT * FROM table1');
        $this->assertEquals('value1', $rows[0]['column1']);
        $this->assertEquals('value2', $rows[1]['column1']);
        $this->assertEquals('value3', $rows[2]['column1']);
        $this->assertEquals(1, $rows[0]['column2']);
        $this->assertEquals(2, $rows[1]['column2']);
        $this->assertEquals(3, $rows[2]['column2']);
        $this->assertEquals('test', $rows[0]['column3']);
        $this->assertEquals('test', $rows[2]['column3']);
    }

    public function testInsertData()
    {
        $data = [
            [
                'column1' => 'value1',
                'column2' => 1,
            ],
            [
                'column1' => 'value2',
                'column2' => 2,
            ],
            [
                'column1' => 'value3',
                'column2' => 3,
                'column3' => 'foo',
            ],
        ];
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('column1', 'string')
            ->addColumn('column2', 'integer')
            ->addColumn('column3', 'string', ['default' => 'test'])
            ->insert($data)
            ->save();

        $rows = $this->adapter->fetchAll('SELECT * FROM table1');
        $this->assertEquals('value1', $rows[0]['column1']);
        $this->assertEquals('value2', $rows[1]['column1']);
        $this->assertEquals('value3', $rows[2]['column1']);
        $this->assertEquals(1, $rows[0]['column2']);
        $this->assertEquals(2, $rows[1]['column2']);
        $this->assertEquals(3, $rows[2]['column2']);
        $this->assertEquals('test', $rows[0]['column3']);
        $this->assertEquals('foo', $rows[2]['column3']);
    }

    public function testDumpCreateTable()
    {
        $inputDefinition = new InputDefinition([new InputOption('dry-run')]);
        $this->adapter->setInput(new ArrayInput(['--dry-run' => true], $inputDefinition));

        $consoleOutput = new BufferedOutput();
        $this->adapter->setOutput($consoleOutput);

        $table = new \Phinx\Db\Table('table1', [], $this->adapter);

        $table->addColumn('column1', 'string')
            ->addColumn('column2', 'integer')
            ->addColumn('column3', 'string', ['default' => 'test'])
            ->save();

        $expectedOutput = <<<'OUTPUT'
CREATE TABLE `table1` (`id` INT(11) NOT NULL AUTO_INCREMENT, `column1` VARCHAR(255) NOT NULL, `column2` INT(11) NOT NULL, `column3` VARCHAR(255) NOT NULL DEFAULT 'test', PRIMARY KEY (`id`)) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci;
OUTPUT;
        $actualOutput = $consoleOutput->fetch();
        $this->assertStringContainsString($expectedOutput, $actualOutput, 'Passing the --dry-run option does not dump create table query to the output');
    }

    /**
     * Creates the table "table1".
     * Then sets phinx to dry run mode and inserts a record.
     * Asserts that phinx outputs the insert statement and doesn't insert a record.
     */
    public function testDumpInsert()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('string_col', 'string')
            ->addColumn('int_col', 'integer')
            ->save();

        $inputDefinition = new InputDefinition([new InputOption('dry-run')]);
        $this->adapter->setInput(new ArrayInput(['--dry-run' => true], $inputDefinition));

        $consoleOutput = new BufferedOutput();
        $this->adapter->setOutput($consoleOutput);

        $this->adapter->insert($table->getTable(), [
            'string_col' => 'test data',
        ]);

        $this->adapter->insert($table->getTable(), [
            'string_col' => null,
        ]);

        $this->adapter->insert($table->getTable(), [
            'int_col' => 23,
        ]);

        $expectedOutput = <<<'OUTPUT'
INSERT INTO `table1` (`string_col`) VALUES ('test data');
INSERT INTO `table1` (`string_col`) VALUES (null);
INSERT INTO `table1` (`int_col`) VALUES (23);
OUTPUT;
        $actualOutput = $consoleOutput->fetch();

        // Add this to be LF - CR/LF systems independent
        $expectedOutput = preg_replace('~\R~u', '', $expectedOutput);
        $actualOutput = preg_replace('~\R~u', '', $actualOutput);

        $this->assertStringContainsString($expectedOutput, trim($actualOutput), 'Passing the --dry-run option doesn\'t dump the insert to the output');

        $countQuery = $this->adapter->query('SELECT COUNT(*) FROM table1');
        $this->assertTrue($countQuery->execute());
        $res = $countQuery->fetchAll();
        $this->assertEquals(0, $res[0]['COUNT(*)']);
    }

    /**
     * Creates the table "table1".
     * Then sets phinx to dry run mode and inserts some records.
     * Asserts that phinx outputs the insert statement and doesn't insert any record.
     */
    public function testDumpBulkinsert()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('string_col', 'string')
            ->addColumn('int_col', 'integer')
            ->save();

        $inputDefinition = new InputDefinition([new InputOption('dry-run')]);
        $this->adapter->setInput(new ArrayInput(['--dry-run' => true], $inputDefinition));

        $consoleOutput = new BufferedOutput();
        $this->adapter->setOutput($consoleOutput);

        $this->adapter->bulkinsert($table->getTable(), [
            [
                'string_col' => 'test_data1',
                'int_col' => 23,
            ],
            [
                'string_col' => null,
                'int_col' => 42,
            ],
        ]);

        $expectedOutput = <<<'OUTPUT'
INSERT INTO `table1` (`string_col`, `int_col`) VALUES ('test_data1', 23), (null, 42);
OUTPUT;
        $actualOutput = $consoleOutput->fetch();
        $this->assertStringContainsString($expectedOutput, $actualOutput, 'Passing the --dry-run option doesn\'t dump the bulkinsert to the output');

        $countQuery = $this->adapter->query('SELECT COUNT(*) FROM table1');
        $this->assertTrue($countQuery->execute());
        $res = $countQuery->fetchAll();
        $this->assertEquals(0, $res[0]['COUNT(*)']);
    }

    public function testDumpCreateTableAndThenInsert()
    {
        $inputDefinition = new InputDefinition([new InputOption('dry-run')]);
        $this->adapter->setInput(new ArrayInput(['--dry-run' => true], $inputDefinition));

        $consoleOutput = new BufferedOutput();
        $this->adapter->setOutput($consoleOutput);

        $table = new \Phinx\Db\Table('table1', ['id' => false, 'primary_key' => ['column1']], $this->adapter);

        $table->addColumn('column1', 'string')
            ->addColumn('column2', 'integer')
            ->save();

        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->insert([
            'column1' => 'id1',
            'column2' => 1,
        ])->save();

        $expectedOutput = <<<'OUTPUT'
CREATE TABLE `table1` (`column1` VARCHAR(255) NOT NULL, `column2` INT(11) NOT NULL, PRIMARY KEY (`column1`)) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci;
INSERT INTO `table1` (`column1`, `column2`) VALUES ('id1', 1);
OUTPUT;
        $actualOutput = $consoleOutput->fetch();
        // Add this to be LF - CR/LF systems independent
        $expectedOutput = preg_replace('~\R~u', '', $expectedOutput);
        $actualOutput = preg_replace('~\R~u', '', $actualOutput);
        $this->assertStringContainsString($expectedOutput, $actualOutput, 'Passing the --dry-run option does not dump create and then insert table queries to the output');
    }

    public function testDumpTransaction()
    {
        $inputDefinition = new InputDefinition([new InputOption('dry-run')]);
        $this->adapter->setInput(new ArrayInput(['--dry-run' => true], $inputDefinition));

        $consoleOutput = new BufferedOutput();
        $this->adapter->setOutput($consoleOutput);

        $this->adapter->beginTransaction();
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);

        $table->addColumn('column1', 'string')
            ->addColumn('column2', 'integer')
            ->addColumn('column3', 'string', ['default' => 'test'])
            ->save();
        $this->adapter->commitTransaction();
        $this->adapter->rollbackTransaction();

        $actualOutput = $consoleOutput->fetch();
        // Add this to be LF - CR/LF systems independent
        $actualOutput = preg_replace('~\R~u', '', $actualOutput);
        $this->assertStringStartsWith('START TRANSACTION;', $actualOutput, 'Passing the --dry-run doesn\'t dump the transaction to the output');
        $this->assertStringEndsWith('COMMIT;ROLLBACK;', $actualOutput, 'Passing the --dry-run doesn\'t dump the transaction to the output');
    }

    /**
     * Tests interaction with the query builder
     */
    public function testQueryBuilder()
    {
        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table->addColumn('string_col', 'string')
            ->addColumn('int_col', 'integer')
            ->save();

        $builder = $this->adapter->getQueryBuilder();
        $stm = $builder
            ->insert(['string_col', 'int_col'])
            ->into('table1')
            ->values(['string_col' => 'value1', 'int_col' => 1])
            ->values(['string_col' => 'value2', 'int_col' => 2])
            ->execute();

        $this->assertEquals(2, $stm->rowCount());

        $builder = $this->adapter->getQueryBuilder();
        $stm = $builder
            ->select('*')
            ->from('table1')
            ->where(['int_col >=' => 2])
            ->execute();

        $this->assertEquals(1, $stm->rowCount());
        $this->assertEquals(
            ['id' => 2, 'string_col' => 'value2', 'int_col' => '2'],
            $stm->fetch('assoc')
        );

        $builder = $this->adapter->getQueryBuilder();
        $stm = $builder
            ->delete('table1')
            ->where(['int_col <' => 2])
            ->execute();

        $this->assertEquals(1, $stm->rowCount());
    }

    public function testLiteralSupport()
    {
        $createQuery = <<<'INPUT'
CREATE TABLE `test` (`double_col` double NOT NULL)
INPUT;
        $this->adapter->execute($createQuery);
        $table = new \Phinx\Db\Table('test', [], $this->adapter);
        $columns = $table->getColumns();
        $this->assertCount(1, $columns);
        $this->assertEquals(Literal::from('double'), array_pop($columns)->getType());
    }

    public function geometryTypeProvider()
    {
        return [
            [MysqlAdapter::PHINX_TYPE_GEOMETRY, 'POINT(0 0)'],
            [MysqlAdapter::PHINX_TYPE_POINT, 'POINT(0 0)'],
            [MysqlAdapter::PHINX_TYPE_LINESTRING, 'LINESTRING(30 10,10 30,40 40)'],
            [MysqlAdapter::PHINX_TYPE_POLYGON, 'POLYGON((30 10,40 40,20 40,10 20,30 10))'],
        ];
    }

    /**
     * @dataProvider geometryTypeProvider
     * @param string $type
     * @param string $geom
     */
    public function testGeometrySridSupport($type, $geom)
    {
        $this->adapter->connect();
        if (!$this->usingMysql8()) {
            $this->markTestSkipped('Cannot test geometry srid on mysql versions less than 8');
        }

        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table
            ->addColumn('geom', $type, ['srid' => 4326])
            ->save();

        $this->adapter->execute("INSERT INTO table1 (`geom`) VALUES (ST_GeomFromText('{$geom}', 4326))");
        $rows = $this->adapter->fetchAll('SELECT ST_AsWKT(geom) as wkt, ST_SRID(geom) as srid FROM table1');
        $this->assertCount(1, $rows);
        $this->assertSame($geom, $rows[0]['wkt']);
        $this->assertSame(4326, (int)$rows[0]['srid']);
    }

    /**
     * @dataProvider geometryTypeProvider
     * @param string $type
     * @param string $geom
     */
    public function testGeometrySridThrowsInsertDifferentSrid($type, $geom)
    {
        $this->adapter->connect();
        if (!$this->usingMysql8()) {
            $this->markTestSkipped('Cannot test geometry srid on mysql versions less than 8');
        }

        $table = new \Phinx\Db\Table('table1', [], $this->adapter);
        $table
            ->addColumn('geom', $type, ['srid' => 4326])
            ->save();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("SQLSTATE[HY000]: General error: 3643 The SRID of the geometry does not match the SRID of the column 'geom'. The SRID of the geometry is 4322, but the SRID of the column is 4326. Consider changing the SRID of the geometry or the SRID property of the column.");
        $this->adapter->execute("INSERT INTO table1 (`geom`) VALUES (ST_GeomFromText('{$geom}', 4322))");
    }

    /**
     * Small check to verify if specific Mysql constants are handled in AdapterInterface
     *
     * @see https://github.com/cakephp/migrations/issues/359
     */
    public function testMysqlBlobsConstants()
    {
        $reflector = new \ReflectionClass(AdapterInterface::class);

        $validTypes = array_filter($reflector->getConstants(), function ($constant) {
            return substr($constant, 0, strlen('PHINX_TYPE_')) === 'PHINX_TYPE_';
        }, ARRAY_FILTER_USE_KEY);

        $this->assertTrue(in_array('tinyblob', $validTypes, true));
        $this->assertTrue(in_array('blob', $validTypes, true));
        $this->assertTrue(in_array('mediumblob', $validTypes, true));
        $this->assertTrue(in_array('longblob', $validTypes, true));
    }

    public function testCreateTableWithPrecisionCurrentTimestamp()
    {
        $this->adapter->connect();
        (new \Phinx\Db\Table('exampleCurrentTimestamp3', ['id' => false], $this->adapter))
            ->addColumn('timestamp_3', 'timestamp', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP(3)',
                'limit' => 3,
            ])
            ->create();

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='exampleCurrentTimestamp3'",
            MYSQL_DB_CONFIG['name']
        ));
        $colDef = $rows[0];
        $this->assertEqualsIgnoringCase('CURRENT_TIMESTAMP(3)', $colDef['COLUMN_DEFAULT']);
    }

    public function pdoAttributeProvider()
    {
        return [
            ['mysql_attr_invalid'],
            ['attr_invalid'],
        ];
    }

    /**
     * @dataProvider pdoAttributeProvider
     */
    public function testInvalidPdoAttribute($attribute)
    {
        $adapter = new MysqlAdapter(MYSQL_DB_CONFIG + [$attribute => true]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid PDO attribute: ' . $attribute . ' (\PDO::' . strtoupper($attribute) . ')');
        $adapter->connect();
    }
}
