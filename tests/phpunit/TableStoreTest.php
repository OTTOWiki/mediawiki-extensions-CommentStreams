<?php

namespace MediaWiki\Extension\CommentStreams\Tests;

use MediaWiki\Extension\CommentStreams\Comment;
use MediaWiki\Extension\CommentStreams\Reply;
use MediaWiki\Extension\CommentStreams\Store\TableStore;
use Wikimedia\Timestamp\TimestampException;

/**
 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore
 * @group Database
 */
class TableStoreTest extends \MediaWikiIntegrationTestCase {

	/** @var array */
	protected array $pageData;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->overrideConfigValue( 'CommentStreamsStoreModel', 'table' );
		$this->pageData = $this->insertPage( 'DummyPage', 'Dummy content' );
	}

	/**
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::insertComment
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::insertReply
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getComment
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getReply
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getReplies
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getNumReplies
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getAssociatedComments
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getWikitext
	 * @return void
	 * @throws TimestampException
	 */
	public function testCreation() {
		$store = $this->getServiceContainer()->getService( 'CommentStreamsStore' );
		$this->assertInstanceOf( TableStore::class, $store );

		$comment = $store->insertComment(
			$this->getTestSysop()->getUser(), 'Foo', $this->pageData['id'], 'Bar', null
		);
		$this->assertInstanceOf( Comment::class, $comment );
		$this->assertGreaterThan( 0, $comment->getId() );

		$comment = $store->getComment( $comment->getId() );
		$this->assertInstanceOf( Comment::class, $comment );
		$this->assertSame( $this->getTestSysop()->getUser()->getName(), $comment->getAuthor()->getName() );
		$this->assertSame( $this->pageData['title']->getNamespace(), $comment->getAssociatedPage()->getNamespace() );
		$this->assertSame( $this->pageData['title']->getDBkey(), $comment->getAssociatedPage()->getDBkey() );
		$this->assertSame( 'Bar', $comment->getTitle() );
		$this->assertSame( 'Foo', $store->getWikitext( $comment ) );
		$this->assertNull( $comment->getBlockName() );

		$associated = $store->getAssociatedComments( $this->pageData['title'] );
		$this->assertCount( 1, $associated );
		$this->assertSame( $comment->getId(), $associated[0]->getId() );

		$reply = $store->insertReply( $this->getTestSysop()->getUser(), 'Foo', $comment );
		$this->assertInstanceOf( Reply::class, $reply );
		$this->assertSame( $comment->getId(), $reply->getParent()->getId() );
		$this->assertSame( $this->getTestSysop()->getUser()->getName(), $reply->getAuthor()->getName() );
		$this->assertSame( 'Foo', $store->getWikitext( $reply ) );

		$reply = $store->getReply( $reply->getId() );
		$this->assertInstanceOf( Reply::class, $reply );

		$replies = $store->getReplies( $comment );
		$this->assertCount( 1, $replies );
		$this->assertSame( $reply->getId(), $replies[0]->getId() );
		$this->assertSame( 1, $store->getNumReplies( $comment ) );
	}

	/**
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::updateComment
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::updateReply
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getHistory
	 * @return void
	 */
	public function testEditing() {
		$store = $this->getServiceContainer()->getService( 'CommentStreamsStore' );
		$comment = $store->insertComment(
			$this->getTestSysop()->getUser(), 'Foo', $this->pageData['id'], 'Bar', null
		);
		$res = $store->updateComment( $comment, 'Dummy', 'Test', $this->getTestSysop()->getUser() );
		$this->assertTrue( $res );

		$comment = $store->getComment( $comment->getId() );
		$this->assertSame( 'Dummy', $comment->getTitle() );
		$this->assertSame( 'Test', $store->getWikitext( $comment ) );
		$this->assertSame( $this->getTestSysop()->getUser()->getName(), $comment->getLastEditor()->getName() );

		$history = $store->getHistory( $comment );
		$this->assertCount( 2, $history );
		$texts = array_column( $history, 'text' );
		$this->assertContains( 'Foo', $texts );
		$this->assertContains( 'Test', $texts );

		$reply = $store->insertReply( $this->getTestSysop()->getUser(), 'Foo', $comment );
		$res = $store->updateReply( $reply, 'Test', $this->getTestSysop()->getUser() );
		$this->assertTrue( $res );

		$reply = $store->getReply( $reply->getId() );
		$this->assertSame( 'Test', $store->getWikitext( $reply ) );
	}

	/**
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::vote
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getVote
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getNumUpVotes
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getNumDownVotes
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::watch
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::unwatch
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::isWatching
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::getWatchers
	 * @return void
	 */
	public function testVotesAndWatchlist() {
		$store = $this->getServiceContainer()->getService( 'CommentStreamsStore' );
		$user = $this->getTestUser()->getUser();
		$comment = $store->insertComment(
			$this->getTestSysop()->getUser(), 'Foo', $this->pageData['id'], 'Bar', null
		);

		$this->assertSame( 0, $store->getVote( $comment, $user ) );
		$this->assertTrue( $store->vote( $comment, 1, $user ) );
		$this->assertSame( 1, $store->getVote( $comment, $user ) );
		$this->assertSame( 1, $store->getNumUpVotes( $comment ) );
		$this->assertTrue( $store->vote( $comment, -1, $user ) );
		$this->assertSame( 1, $store->getNumDownVotes( $comment ) );

		$this->assertTrue( $store->watch( $comment, $user ) );
		$this->assertTrue( $store->isWatching( $comment, $user, DB_PRIMARY ) );
		$watchers = $store->getWatchers( $comment );
		$this->assertArrayHasKey( $user->getId(), $watchers );
		$this->assertTrue( $store->unwatch( $comment, $user ) );
		$this->assertFalse( $store->isWatching( $comment, $user, DB_PRIMARY ) );
	}

	/**
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::deleteComment
	 * @covers \MediaWiki\Extension\CommentStreams\Store\TableStore::deleteReply
	 * @return void
	 */
	public function testDeletion() {
		$store = $this->getServiceContainer()->getService( 'CommentStreamsStore' );
		$comment = $store->insertComment(
			$this->getTestSysop()->getUser(), 'Foo', $this->pageData['id'], 'Bar', null
		);
		$reply = $store->insertReply( $this->getTestSysop()->getUser(), 'Foo', $comment );
		$store->insertReply( $this->getTestSysop()->getUser(), 'Bar', $comment );
		$this->assertSame( 2, $store->getNumReplies( $comment ) );

		$this->assertTrue( $store->deleteReply( $reply, $this->getTestSysop()->getUser() ) );
		$this->assertSame( 1, $store->getNumReplies( $comment ) );
		$this->assertNull( $store->getReply( $reply->getId() ) );

		$this->assertTrue( $store->deleteComment( $comment, $this->getTestSysop()->getUser() ) );
		$this->assertNull( $store->getComment( $comment->getId() ) );
		$this->assertSame( '', $store->getWikitext( $comment ) );
	}
}
