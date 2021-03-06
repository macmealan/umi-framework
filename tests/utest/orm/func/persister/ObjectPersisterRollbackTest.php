<?php
/**
 * UMI.Framework (http://umi-framework.ru/)
 *
 * @link      http://github.com/Umisoft/framework for the canonical source repository
 * @copyright Copyright (c) 2007-2013 Umisoft ltd. (http://umisoft.ru/)
 * @license   http://umi-framework.ru/license/bsd-3 BSD-3 License
 */

namespace utest\orm\func\persister;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Types\Type;
use umi\dbal\driver\IDialect;
use umi\orm\collection\ICollectionFactory;
use umi\orm\object\IObject;
use utest\orm\ORMDbTestCase;

/**
 * Тесты отката коммита ObjectPersister
 */
class ObjectPersisterRollbackTest extends ORMDbTestCase
{

    protected $blog1Guid;
    protected $blog2Guid;

    /**
     * {@inheritdoc}
     */
    protected function getCollectionConfig()
    {
        return [
            self::METADATA_DIR . '/mock/collections',
            [
                self::SYSTEM_HIERARCHY       => [
                    'type' => ICollectionFactory::TYPE_COMMON_HIERARCHY
                ],
                self::BLOGS_BLOG             => [
                    'type'      => ICollectionFactory::TYPE_LINKED_HIERARCHIC,
                    'class'     => 'utest\orm\mock\collections\BlogsCollection',
                    'hierarchy' => self::SYSTEM_HIERARCHY
                ],
                self::BLOGS_POST             => [
                    'type'      => ICollectionFactory::TYPE_LINKED_HIERARCHIC,
                    'hierarchy' => self::SYSTEM_HIERARCHY
                ],
                self::USERS_USER             => [
                    'type' => ICollectionFactory::TYPE_SIMPLE
                ],
                self::USERS_GROUP            => [
                    'type' => ICollectionFactory::TYPE_SIMPLE
                ]
            ],
            true
        ];
    }

    protected function setUpFixtures()
    {
        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);

        $blog1 = $blogsCollection->add('blog1');
        $blog1->setValue('title', 'first_blog');
        $this->blog1Guid = $blog1->getGUID();

        $blog2 = $blogsCollection->add('blog2');
        $blog2->setValue('title', 'second_blog');
        $this->blog2Guid = $blog2->getGUID();

        $this->getObjectPersister()->commit();
    }

    public function testURIConflict()
    {
        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);
        $blogsCollection->add('blog1');

        $e = null;
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда произошел конфликт урлов при добавлении объекта'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'Doctrine\DBAL\DBALException', //Integrity constraint violation
            $parentE,
            'Ожидается родительское исключение БД, когда произошел конфликт урлов при добавлении объекта'
        );

    }

    public function testForeignKeyCheck()
    {
        /** @var IDialect|AbstractPlatform $dialect */
        $dialect = $this->connection->getDatabasePlatform();
        $del = $dialect->getTruncateTableSQL('umi_mock_blogs');

        $this->connection->exec($del);
        $this->connection->exec($dialect->getDisableForeignKeysSQL());
        $this->connection->insert(
            'umi_mock_users',
            ['id'=>10, 'type'=>'users_user.base', 'group_id'=>10, 'guid'=>'9ee6745f-f40d-46d8-8043-d959594628ce']
        );
        $this->connection->exec($dialect->getEnableForeignKeysSQL());

        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);
        $usersCollection = $this->getCollectionManager()->getCollection(self::USERS_USER);

        $user1 = $usersCollection->get('9ee6745f-f40d-46d8-8043-d959594628ce');
        $usersCollection->delete($user1);

        $blog1 = $blogsCollection->get($this->blog1Guid);
        $blogsCollection->delete($blog1);

        $e = null;
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $parentE,
            'Ожидается родительское исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $this->assertEquals(
            'Cannot delete object with id "1" and type "blogs_blog.base". Database row is not modified.',
            $parentE->getMessage(),
            'Произошло неожидаемое исключение'
        );

    }

    public function testDeleteRollback()
    {
        $this->getDbCluster()
            ->modifyInternal('DELETE FROM `umi_mock_blogs` WHERE `id` = 1');

        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);
        $usersCollection = $this->getCollectionManager()->getCollection(self::USERS_USER);

        $user1 = $usersCollection->add();
        $user1->setValue('login', 'first_user');

        $blog3 = $blogsCollection->add('blog3');
        $blog3->setValue('title', 'third_blog');
        $blog3->setValue('owner', $user1);
        $blog3Guid = $blog3->getGUID();

        $blog1 = $blogsCollection->get($this->blog1Guid);
        $blogsCollection->delete($blog1);

        $blog2 = $blogsCollection->get($this->blog2Guid);
        $blog2->setValue('title', 'modified_second_blog');

        $e = null;
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $parentE,
            'Ожидается родительское исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $this->assertEquals(
            'Cannot delete object with id "1" and type "blogs_blog.base". Database row is not modified.',
            $parentE->getMessage(),
            'Произошло неожидаемое исключение'
        );

        $this->assertTrue(
            $blog2->getIsUnloaded(),
            'Ожидается, что измененный объект был выгружен из менеджера объектов после роллбэка'
        );
        $this->assertTrue(
            $blog1->getIsUnloaded(),
            'Ожидается, что удаленный объект был выгружен из менеджера объектов после роллбэка'
        );
        $this->assertTrue(
            $blog3->getIsUnloaded(),
            'Ожидается, что добавленный объект был выгружен из менеджера объектов после роллбэка'
        );

        $e = null;
        try {
            $blogsCollection->get($blog3Guid);
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение при попытке получить объект, который был добавлен, а потом был удален при откате'
        );
        $this->assertEquals(
            'second_blog',
            $blogsCollection->get($this->blog2Guid)
                ->getValue('title'),
            'Ожидается, что измененное свойство при откате вернулось к прежнему значению'
        );

        $this->assertCount(
            2,
            $this->getCollectionManager()->getCollection(self::SYSTEM_HIERARCHY)
                ->select()
                ->fields(['id'])
                ->getResult()
                ->fetchAll(),
            'Ожидается, что в иерархической коллекции только 2 объектв'
        );
        $this->assertCount(
            0,
            $usersCollection->select()
                ->fields([IObject::FIELD_IDENTIFY])
                ->getResult()
                ->fetchAll(),
            'Ожидается, что в коллекции пользователей нет объектов'
        );

    }

    public function testUpdateRollback()
    {
        $this->getDbCluster()
            ->modifyInternal('DELETE FROM `umi_mock_blogs` WHERE `id` = 1');

        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);

        $blog1 = $blogsCollection->get($this->blog1Guid);
        $blog1->setValue('title', 'new_blog_title');

        $e = null;
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $parentE,
            'Ожидается родительское исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $this->assertEquals(
            'Cannot modify object with id "1" and type "blogs_blog.base". Database row is not modified.',
            $parentE->getMessage(),
            'Произошло неожидаемое исключение'
        );

    }

    public function testWrongVersionRollback()
    {
        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);

        $blog1 = $blogsCollection->get($this->blog1Guid);
        $blog1->setVersion('3');
        $blog1->setValue('title', 'new_blog_title');

        $e = null;
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $parentE,
            'Ожидается родительское исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $this->assertEquals(
            'Cannot modify object with id "1" and type "blogs_blog.base". Object is out of date.',
            $parentE->getMessage(),
            'Произошло неожидаемое исключение'
        );

    }

    public function testInsertRollback()
    {
        $this->markTestIncomplete(
            'SQLite LAST_INSERT_ROWID всегда возвращает коррекнтое число, нужен отдельный тест с MockConnection'
        );

        /** @var IDialect|AbstractPlatform $dialect */
        $dialect = $this->connection->getDatabasePlatform();

        $this->connection->exec($dialect->getDisableForeignKeysSQL());

        $sm = $this->connection->getSchemaManager();
        $usersTbl = $sm->listTableDetails('umi_mock_users');
        $usersTbl->dropPrimaryKey();
        $usersTbl->changeColumn(
            'id',
            [
                'type'          => Type::getType('bigint'),
                 'unsigned'      => true,
                 'default'       => null,
                 'notnull'       => false,
                 'autoincrement' => false
            ]
        );
        $comparator = new Comparator();
        $tableDiff = $comparator->diffTable($sm->listTableDetails('umi_mock_users'), $usersTbl);
        $sm->alterTable($tableDiff);

        $bTable = $sm->listTableDetails('umi_mock_blogs');
        $sm->dropConstraint($bTable->getForeignKey('FK_blog_owner'), 'umi_mock_blogs');


        $usersCollection = $this->getCollectionManager()->getCollection(self::USERS_USER);

        $user1 = $usersCollection->add();
        $user1->setValue('login', 'first_user');
        $user2 = $usersCollection->add();
        $user2->setValue('login', 'next_user');


        $e = null;
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }
        $this->connection->exec($dialect->getEnableForeignKeysSQL());

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $parentE,
            'Ожидается родительское исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $this->assertEquals(
            'Cannot persist object. Cannot get last inserted id for object.',
            $parentE->getMessage(),
            'Произошло неожидаемое исключение'
        );

    }

    public function testHierarchyUpdateRollback()
    {
        $sm = $this->connection->getSchemaManager();

        /** @var IDialect|AbstractPlatform $dialect */
        $dialect = $this->connection->getDatabasePlatform();
        $this->connection->exec($dialect->getDisableForeignKeysSQL());

        $hierTbl = $sm->listTableDetails('umi_mock_hierarchy');
        $hierTbl->dropPrimaryKey();
        $hierTbl->dropIndex('hierarchy_mpath');
        $hierTbl->dropIndex('hierarchy_uri');
        $hierTbl->changeColumn(
            'id',
            ['type' => Type::getType('bigint'), 'unsigned' => true,
             'default' => null, 'notnull'=>false, 'autoincrement'=>false]
        );

        $comparator = new Comparator();
        $tableDiff = $comparator->diffTable($sm->listTableDetails('umi_mock_hierarchy'), $hierTbl);
        $sm->alterTable($tableDiff);

        $this
            ->getDbCluster()
            ->insert('umi_mock_hierarchy')
            ->set('id', ':id')
            ->bindInt(':id', 3)
            ->execute();

        $blogsCollection = $this->getCollectionManager()->getCollection(self::BLOGS_BLOG);

        $blog3 = $blogsCollection->add('blog5');
        $blog3->getProperty(IObject::FIELD_IDENTIFY)
            ->setValue(3);
        $blog3->setValue('title', 'new_blog_title');

        $e = null;
        $this->connection->exec($dialect->getDisableForeignKeysSQL());
        try {
            $this->getObjectPersister()->commit();
        } catch (\Exception $e) {
        }

        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $e,
            'Ожидается исключение, когда не удается выполнить запросы на установку иерархических свойств'
        );
        $parentE = $e->getPrevious();
        $this->assertInstanceOf(
            'umi\orm\exception\RuntimeException',
            $parentE,
            'Ожидается родительское исключение, когда не удается выполнить запросы на изменения объектов'
        );
        $this->assertEquals(
            'Cannot set calculable properties for object with id "3" and type "blogs_blog.base".'
            . ' Database row is not modified.',
            $parentE->getMessage(),
            'Произошло неожидаемое исключение'
        );
    }
}
