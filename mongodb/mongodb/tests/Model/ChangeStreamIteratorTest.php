<?php

namespace MongoDB\Tests\Model;

use MongoDB\Collection;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Exception\InvalidArgumentException;
use MongoDB\Model\ChangeStreamIterator;
use MongoDB\Operation\Find;
use MongoDB\Operation\CreateCollection;
use MongoDB\Operation\DropCollection;
use MongoDB\Tests\CommandObserver;
use MongoDB\Tests\FunctionalTestCase;

class ChangeStreamIteratorTest extends FunctionalTestCase
{
    private $collection;

    public function setUp()
    {
        parent::setUp();

        $operation = new DropCollection($this->getDatabaseName(), $this->getCollectionName());
        $operation->execute($this->getPrimaryServer());

        $operation = new CreateCollection($this->getDatabaseName(), $this->getCollectionName(), ['capped' => true, 'size' => 8192]);
        $operation->execute($this->getPrimaryServer());

        $this->collection = new Collection($this->manager, $this->getDatabaseName(), $this->getCollectionName());
    }

    /**
     * @dataProvider provideInvalidIntegerValues
     */
    public function testFirstBatchArgumentTypeCheck($firstBatchSize)
    {
        $this->expectException(InvalidArgumentException::class);
        new ChangeStreamIterator($this->collection->find(), $firstBatchSize, null, null);
    }

    public function provideInvalidIntegerValues()
    {
        return $this->wrapValuesForDataProvider($this->getInvalidIntegerValues());
    }

    public function testInitialResumeToken()
    {
        $iterator = new ChangeStreamIterator($this->collection->find(), 0, null, null);
        $this->assertNull($iterator->getResumeToken());

        $iterator = new ChangeStreamIterator($this->collection->find(), 0, ['resumeToken' => 1], null);
        $this->assertSameDocument(['resumeToken' => 1], $iterator->getResumeToken());

        $iterator = new ChangeStreamIterator($this->collection->find(), 0, (object) ['resumeToken' => 2], null);
        $this->assertSameDocument((object) ['resumeToken' => 2], $iterator->getResumeToken());
    }

    /**
     * @dataProvider provideInvalidDocumentValues
     */
    public function testInitialResumeTokenArgumentTypeCheck($initialResumeToken)
    {
        $this->expectException(InvalidArgumentException::class);
        new ChangeStreamIterator($this->collection->find(), 0, $initialResumeToken, null);
    }

    /**
     * @dataProvider provideInvalidObjectValues
     */
    public function testPostBatchResumeTokenArgumentTypeCheck($postBatchResumeToken)
    {
        $this->expectException(InvalidArgumentException::class);
        new ChangeStreamIterator($this->collection->find(), 0, null, $postBatchResumeToken);
    }

    public function provideInvalidObjectValues()
    {
        return $this->wrapValuesForDataProvider(array_merge($this->getInvalidDocumentValues(), [[]]));
    }

    public function testPostBatchResumeTokenIsReturnedForLastElementInFirstBatch()
    {
        $this->collection->insertOne(['_id' => ['resumeToken' => 1], 'x' => 1]);
        $this->collection->insertOne(['_id' => ['resumeToken' => 2], 'x' => 2]);
        $postBatchResumeToken = (object) ['resumeToken' => 'pb'];

        $cursor = $this->collection->find([], ['cursorType' => Find::TAILABLE]);
        $iterator = new ChangeStreamIterator($cursor, 2, null, $postBatchResumeToken);

        $this->assertNoCommandExecuted(function() use ($iterator) { $iterator->rewind(); });
        $this->assertTrue($iterator->valid());
        $this->assertSameDocument(['resumeToken' => 1], $iterator->getResumeToken());
        $this->assertSameDocument(['_id' => ['resumeToken' => 1], 'x' => 1], $iterator->current());

        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertSameDocument($postBatchResumeToken, $iterator->getResumeToken());
        $this->assertSameDocument(['_id' => ['resumeToken' => 2], 'x' => 2], $iterator->current());
    }

    public function testRewindIsNopWhenFirstBatchIsEmpty()
    {
        $this->collection->insertOne(['_id' => ['resumeToken' => 1], 'x' => 1]);

        $cursor = $this->collection->find(['x' => ['$gt' => 1]], ['cursorType' => Find::TAILABLE]);
        $iterator = new ChangeStreamIterator($cursor, 0, null, null);

        $this->assertNoCommandExecuted(function() use ($iterator) { $iterator->rewind(); });
        $this->assertFalse($iterator->valid());

        $this->collection->insertOne(['_id' => ['resumeToken' => 2], 'x' => 2]);

        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertSameDocument(['_id' => ['resumeToken' => 2], 'x' => 2], $iterator->current());

        $this->expectException(LogicException::class);
        $iterator->rewind();
    }

    public function testRewindAdvancesWhenFirstBatchIsNotEmpty()
    {
        $this->collection->insertOne(['_id' => ['resumeToken' => 1], 'x' => 1]);

        $cursor = $this->collection->find([], ['cursorType' => Find::TAILABLE]);
        $iterator = new ChangeStreamIterator($cursor, 1, null, null);

        $this->assertNoCommandExecuted(function() use ($iterator) { $iterator->rewind(); });
        $this->assertTrue($iterator->valid());
        $this->assertSameDocument(['_id' => ['resumeToken' => 1], 'x' => 1], $iterator->current());

        $this->collection->insertOne(['_id' => ['resumeToken' => 2], 'x' => 2]);

        $iterator->next();
        $this->assertTrue($iterator->valid());
        $this->assertSameDocument(['_id' => ['resumeToken' => 2], 'x' => 2], $iterator->current());

        $this->expectException(LogicException::class);
        $iterator->rewind();
    }

    private function assertNoCommandExecuted(callable $callable)
    {
        $commands = [];

        (new CommandObserver)->observe(
            $callable,
            function(array $event) use (&$commands) {
                $this->fail(sprintf('"%s" command was executed', $event['started']->getCommandName()));
            }
        );

        $this->assertEmpty($commands);
    }
}
