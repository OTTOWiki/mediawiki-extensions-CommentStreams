<?php
/*
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace MediaWiki\Extension\CommentStreams\Store;

use JsonSerializable;
use MediaWiki\Extension\CommentStreams\AbstractComment;
use MediaWiki\Extension\CommentStreams\Comment;
use MediaWiki\Extension\CommentStreams\HistoryHandler\JSHistoryHandler;
use MediaWiki\Extension\CommentStreams\ICommentStreamsStore;
use MediaWiki\Extension\CommentStreams\Reply;
use MediaWiki\Extension\CommentStreams\VoteHelper;
use MediaWiki\Extension\CommentStreams\WatchHelper;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\Utils\MWTimestamp;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Table based CommentStreams store.
 *
 * Comments and replies are stored as rows in `cs_entities`, their revision
 * history in `cs_entity_revisions`. No wiki pages are created, which keeps the
 * page/revision tables and the CommentStreams namespace clean, and decouples
 * comment writes from page oriented hooks (e.g. Extension:Moderation).
 *
 * @author OTTOWiki
 */
class TableStore implements ICommentStreamsStore {

	/** @var int */
	private const TYPE_COMMENT = 0;
	/** @var int */
	private const TYPE_REPLY = 1;

	/** @var array<int,object|false> per request entity row cache; false = known missing */
	private array $rowCache = [];

	private WatchHelper $watchHelper;
	private VoteHelper $voteHelper;

	/**
	 * @param ILoadBalancer $lb
	 * @param TitleFactory $titleFactory
	 * @param UserFactory $userFactory
	 * @param PermissionManager $permissionManager
	 * @param ActorNormalization $actorNormalization
	 * @param LoggerInterface $logger
	 * @param HookContainer $hookContainer
	 * @param WatchHelper|null $watchHelper
	 * @param VoteHelper|null $voteHelper
	 */
	public function __construct(
		private readonly ILoadBalancer $lb,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly PermissionManager $permissionManager,
		private readonly ActorNormalization $actorNormalization,
		private readonly LoggerInterface $logger,
		private readonly HookContainer $hookContainer,
		?WatchHelper $watchHelper = null,
		?VoteHelper $voteHelper = null
	) {
		$this->watchHelper = $watchHelper ?? new WatchHelper( $this->lb, $this->userFactory );
		$this->voteHelper = $voteHelper ?? new VoteHelper( $this->lb );
	}

	/**
	 * @param int $mode DB_PRIMARY or DB_REPLICA
	 * @return IDatabase
	 */
	private function getDBConnection( int $mode ): IDatabase {
		return $this->lb->getConnection( $mode );
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	private function getEntityRow( int $id ): ?object {
		if ( array_key_exists( $id, $this->rowCache ) ) {
			$cached = $this->rowCache[$id];
			return $cached === false ? null : $cached;
		}
		$row = $this->getDBConnection( DB_REPLICA )
			->newSelectQueryBuilder()
			->select( [
				'cse_id', 'cse_type', 'cse_assoc_page_id', 'cse_parent_id', 'cse_title',
				'cse_block_name', 'cse_author_actor', 'cse_last_editor_actor', 'cse_created',
				'cse_modified'
			] )
			->from( 'cs_entities' )
			->where( [ 'cse_id' => $id, 'cse_deleted' => 0 ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->rowCache[$id] = $row ?: false;
		return $row ?: null;
	}

	/**
	 * @param int $actorId
	 * @return User|null
	 */
	private function getActorUser( int $actorId ): ?User {
		if ( $actorId <= 0 ) {
			return null;
		}
		try {
			return $this->userFactory->newFromActorId( $actorId );
		} catch ( \Throwable $ex ) {
			$this->logger->error( 'Could not load actor {actor}: {error}', [
				'actor' => $actorId,
				'error' => $ex->getMessage()
			] );
			return null;
		}
	}

	/**
	 * @param object $row
	 * @return Comment|null
	 */
	private function newCommentFromRow( object $row ): ?Comment {
		$associatedPage = $this->titleFactory->newFromID( (int)$row->cse_assoc_page_id );
		if ( !$associatedPage ) {
			$this->logger->error( 'Could not find associated page for comment ID {id}', [
				'id' => $row->cse_id
			] );
			return null;
		}
		$author = $this->getActorUser( (int)$row->cse_author_actor );
		if ( !$author ) {
			return null;
		}
		$lastEditor = $row->cse_last_editor_actor !== null
			? $this->getActorUser( (int)$row->cse_last_editor_actor )
			: $author;
		if ( !$lastEditor ) {
			return null;
		}
		return new Comment(
			(int)$row->cse_id,
			(string)$row->cse_title,
			$row->cse_block_name !== null ? (string)$row->cse_block_name : null,
			$associatedPage,
			$author,
			$lastEditor,
			MWTimestamp::getInstance( $row->cse_created ),
			MWTimestamp::getInstance( $row->cse_modified )
		);
	}

	/**
	 * @param object $row
	 * @return Reply|null
	 */
	private function newReplyFromRow( object $row ): ?Reply {
		$parent = $this->getComment( (int)$row->cse_parent_id );
		if ( !$parent ) {
			$this->logger->error( 'Could not find parent comment for reply ID {id}', [
				'id' => $row->cse_id
			] );
			return null;
		}
		$author = $this->getActorUser( (int)$row->cse_author_actor );
		if ( !$author ) {
			return null;
		}
		$lastEditor = $row->cse_last_editor_actor !== null
			? $this->getActorUser( (int)$row->cse_last_editor_actor )
			: $author;
		if ( !$lastEditor ) {
			return null;
		}
		return new Reply(
			$parent,
			(int)$row->cse_id,
			$author,
			$lastEditor,
			MWTimestamp::getInstance( $row->cse_created ),
			MWTimestamp::getInstance( $row->cse_modified )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getComment( int $id ): ?Comment {
		$row = $this->getEntityRow( $id );
		if ( !$row || (int)$row->cse_type !== self::TYPE_COMMENT ) {
			return null;
		}
		return $this->newCommentFromRow( $row );
	}

	/**
	 * @inheritDoc
	 */
	public function getReply( int $id ): ?Reply {
		$row = $this->getEntityRow( $id );
		if ( !$row || (int)$row->cse_type !== self::TYPE_REPLY ) {
			return null;
		}
		return $this->newReplyFromRow( $row );
	}

	/**
	 * @inheritDoc
	 */
	public function userCan( string $action, User $user, AbstractComment $comment ): bool {
		$associatedPage = $comment->getAssociatedPage();
		if ( !$associatedPage ) {
			return false;
		}
		if ( in_array( $action, [ 'cs-comment', 'cs-moderator-edit', 'cs-moderator-delete' ], true ) ) {
			return $user->isAllowed( $action )
				&& $this->permissionManager->userCan( 'read', $user, $associatedPage );
		}
		return $this->permissionManager->userCan( $action, $user, $associatedPage );
	}

	/**
	 * @inheritDoc
	 */
	public function getAssociatedComments( PageIdentity $page ): array {
		$result = $this->getDBConnection( DB_REPLICA )
			->newSelectQueryBuilder()
			->select( 'cse_id' )
			->from( 'cs_entities' )
			->where( [
				'cse_assoc_page_id' => $page->getId(),
				'cse_type' => self::TYPE_COMMENT,
				'cse_deleted' => 0
			] )
			->orderBy( [ 'cse_created', 'cse_id' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$comments = [];
		foreach ( $result as $row ) {
			$comment = $this->getComment( (int)$row->cse_id );
			if ( $comment ) {
				$comments[] = $comment;
			}
		}
		return $comments;
	}

	/**
	 * @inheritDoc
	 */
	public function getReplies( Comment $parent ): array {
		$result = $this->getDBConnection( DB_REPLICA )
			->newSelectQueryBuilder()
			->select( 'cse_id' )
			->from( 'cs_entities' )
			->where( [
				'cse_parent_id' => $parent->getId(),
				'cse_type' => self::TYPE_REPLY,
				'cse_deleted' => 0
			] )
			->orderBy( [ 'cse_created', 'cse_id' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$replies = [];
		foreach ( $result as $row ) {
			$reply = $this->getReply( (int)$row->cse_id );
			if ( $reply ) {
				$replies[] = $reply;
			}
		}
		return $replies;
	}

	/**
	 * @inheritDoc
	 */
	public function getNumReplies( Comment $comment ): int {
		return (int)$this->getDBConnection( DB_REPLICA )
			->newSelectQueryBuilder()
			->select( 'COUNT(*) AS num' )
			->from( 'cs_entities' )
			->where( [
				'cse_parent_id' => $comment->getId(),
				'cse_type' => self::TYPE_REPLY,
				'cse_deleted' => 0
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Insert one entity row plus its first revision.
	 *
	 * @param UserIdentity $author
	 * @param int $type
	 * @param int $assocPageId
	 * @param int|null $parentId
	 * @param string|null $title
	 * @param string|null $blockName
	 * @param string $wikitext
	 * @param string|null $timestamp
	 * @return int|null new entity ID
	 */
	private function insertEntity(
		UserIdentity $author,
		int $type,
		int $assocPageId,
		?int $parentId,
		?string $title,
		?string $blockName,
		string $wikitext,
		?string $timestamp = null
	): ?int {
		$dbw = $this->getDBConnection( DB_PRIMARY );
		$now = $timestamp ?? wfTimestampNow();
		$actorId = $this->actorNormalization->acquireActorId( $author, $dbw );
		$dbw->startAtomic( __METHOD__ );
		try {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'cs_entities' )
				->row( [
					'cse_type' => $type,
					'cse_assoc_page_id' => $assocPageId,
					'cse_parent_id' => $parentId,
					'cse_title' => $title,
					'cse_block_name' => $blockName,
					'cse_author_actor' => $actorId,
					'cse_last_editor_actor' => null,
					'cse_created' => $now,
					'cse_modified' => $now,
					'cse_deleted' => 0
				] )
				->caller( __METHOD__ )
				->execute();
			$id = $dbw->insertId();
			$this->insertRevision( $id, $wikitext, $actorId, $now, $dbw );
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $ex ) {
			$dbw->cancelAtomic( __METHOD__ );
			$this->logger->error( 'Failed to insert comment entity: {error}', [
				'error' => $ex->getMessage()
			] );
			return null;
		}
		return $id;
	}

	/**
	 * @param int $entityId
	 * @param string $wikitext
	 * @param int $actorId
	 * @param string $timestamp
	 * @param IDatabase $dbw
	 * @return void
	 */
	private function insertRevision(
		int $entityId, string $wikitext, int $actorId, string $timestamp, IDatabase $dbw
	): void {
		$dbw->newInsertQueryBuilder()
			->insertInto( 'cs_entity_revisions' )
			->row( [
				'cser_entity_id' => $entityId,
				'cser_text' => $wikitext,
				'cser_actor' => $actorId,
				'cser_timestamp' => $timestamp,
				'cser_deleted' => 0
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @inheritDoc
	 */
	public function insertComment(
		User $user, string $wikitext, int $assocPageId, string $commentTitle, ?string $commentBlockName
	): ?Comment {
		$id = $this->insertEntity(
			$user, self::TYPE_COMMENT, $assocPageId, null, $commentTitle, $commentBlockName, $wikitext
		);
		if ( $id === null ) {
			return null;
		}
		$comment = $this->getComment( $id );
		if ( !$comment ) {
			return null;
		}
		$this->hookContainer->run(
			'CommentStreamsInsertEntity',
			[ $comment, $user, $comment->getAssociatedPage(), 'comment', $wikitext ]
		);
		$this->watch( $comment, $user );
		return $comment;
	}

	/**
	 * @inheritDoc
	 */
	public function insertReply( User $user, string $wikitext, Comment $parent ): ?Reply {
		$assocPage = $parent->getAssociatedPage();
		if ( !$assocPage ) {
			return null;
		}
		$id = $this->insertEntity(
			$user, self::TYPE_REPLY, $assocPage->getId(), $parent->getId(), null, null, $wikitext
		);
		if ( $id === null ) {
			return null;
		}
		$reply = $this->getReply( $id );
		if ( !$reply ) {
			return null;
		}
		$this->hookContainer->run(
			'CommentStreamsInsertEntity',
			[ $reply, $user, $assocPage, 'reply', $wikitext ]
		);
		$this->watch( $parent, $user );
		return $reply;
	}

	/**
	 * @inheritDoc
	 */
	public function updateComment( Comment $comment, string $commentTitle, string $wikitext, User $user ): bool {
		$oldText = $this->getWikitext( $comment );
		if ( !$this->updateEntity( $comment, $wikitext, $user, $commentTitle ) ) {
			return false;
		}
		$this->hookContainer->run(
			'CommentStreamsUpdateEntity',
			[ $comment, $user, $oldText, $wikitext ]
		);
		$this->watch( $comment, $user );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function updateReply( Reply $reply, string $wikitext, User $user ): bool {
		$oldText = $this->getWikitext( $reply );
		if ( !$this->updateEntity( $reply, $wikitext, $user, null ) ) {
			return false;
		}
		$this->hookContainer->run(
			'CommentStreamsUpdateEntity',
			[ $reply, $user, $oldText, $wikitext ]
		);
		return true;
	}

	/**
	 * @param AbstractComment $entity
	 * @param string $wikitext
	 * @param User $user
	 * @param string|null $title new comment title, null for replies
	 * @return bool
	 */
	private function updateEntity( AbstractComment $entity, string $wikitext, User $user, ?string $title ): bool {
		$dbw = $this->getDBConnection( DB_PRIMARY );
		$now = wfTimestampNow();
		$actorId = $this->actorNormalization->acquireActorId( $user, $dbw );
		$id = $entity->getId();
		$dbw->startAtomic( __METHOD__ );
		try {
			$this->insertRevision( $id, $wikitext, $actorId, $now, $dbw );
			$set = [
				'cse_modified' => $now,
				'cse_last_editor_actor' => $actorId
			];
			if ( $title !== null ) {
				$set['cse_title'] = $title;
			}
			$dbw->newUpdateQueryBuilder()
				->update( 'cs_entities' )
				->set( $set )
				->where( [ 'cse_id' => $id ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $ex ) {
			$dbw->cancelAtomic( __METHOD__ );
			$this->logger->error( 'Failed to update comment entity {id}: {error}', [
				'id' => $id,
				'error' => $ex->getMessage()
			] );
			return false;
		}
		unset( $this->rowCache[$id] );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteComment( Comment $comment, Authority $actor ): bool {
		return $this->deleteEntity( $comment, $actor );
	}

	/**
	 * @inheritDoc
	 */
	public function deleteReply( Reply $reply, Authority $actor ): bool {
		return $this->deleteEntity( $reply, $actor );
	}

	/**
	 * Soft delete an entity: clear its revision texts, drop votes and watches
	 * and run the deletion hook.
	 *
	 * @param AbstractComment $entity
	 * @param Authority $actor
	 * @return bool
	 */
	private function deleteEntity( AbstractComment $entity, Authority $actor ): bool {
		$dbw = $this->getDBConnection( DB_PRIMARY );
		$id = $entity->getId();
		$dbw->startAtomic( __METHOD__ );
		try {
			$dbw->newUpdateQueryBuilder()
				->update( 'cs_entity_revisions' )
				->set( [ 'cser_deleted' => 1, 'cser_text' => '' ] )
				->where( [ 'cser_entity_id' => $id ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->newUpdateQueryBuilder()
				->update( 'cs_entities' )
				->set( [ 'cse_deleted' => 1 ] )
				->where( [ 'cse_id' => $id ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'cs_votes' )
				->where( [ 'cst_v_comment_id' => $id ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'cs_watchlist' )
				->where( [ 'cst_wl_comment_id' => $id ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $ex ) {
			$dbw->cancelAtomic( __METHOD__ );
			$this->logger->error( 'Failed to delete comment entity {id}: {error}', [
				'id' => $id,
				'error' => $ex->getMessage()
			] );
			return false;
		}
		$this->rowCache[$id] = false;
		$this->hookContainer->run( 'CommentStreamsDeleteEntity', [ $entity, $actor->getUser() ] );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function upsertCommentMetadata(
		int $pageId, int $assocPageId, string $commentTitle, ?string $blockName
	): bool {
		// Only used by the namespace-page import path.
		$this->logger->debug( 'upsertCommentMetadata is not supported by TableStore' );
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function upsertReplyMetadata( int $pageId, int $commentPageId ) {
		// Only used by the namespace-page import path.
		$this->logger->debug( 'upsertReplyMetadata is not supported by TableStore' );
	}

	/**
	 * @inheritDoc
	 */
	public function getVote( AbstractComment $comment, UserIdentity $user ): int {
		return $this->voteHelper->getVote( $comment, $user );
	}

	/**
	 * @inheritDoc
	 */
	public function getNumUpVotes( AbstractComment $comment ): int {
		return $this->voteHelper->getNumUpVotes( $comment );
	}

	/**
	 * @inheritDoc
	 */
	public function getNumDownVotes( AbstractComment $comment ): int {
		return $this->voteHelper->getNumDownVotes( $comment );
	}

	/**
	 * @inheritDoc
	 */
	public function vote( AbstractComment $comment, int $vote, UserIdentity $user ): bool {
		return $this->voteHelper->vote( $comment, $vote, $user );
	}

	/**
	 * @inheritDoc
	 */
	public function watch( AbstractComment $comment, UserIdentity $user ): bool {
		return $this->watchHelper->watch( $comment, $user );
	}

	/**
	 * @inheritDoc
	 */
	public function unwatch( AbstractComment $comment, UserIdentity $user ): bool {
		return $this->watchHelper->unwatch( $comment, $user );
	}

	/**
	 * @inheritDoc
	 */
	public function isWatching( AbstractComment $comment, UserIdentity $user, int $fromdb = DB_REPLICA ): bool {
		return $this->watchHelper->isWatching( $comment, $user, $fromdb );
	}

	/**
	 * @inheritDoc
	 */
	public function getWatchers( AbstractComment $comment ): array {
		return $this->watchHelper->getWatchers( $comment );
	}

	/**
	 * @inheritDoc
	 */
	public function getWikitext( AbstractComment $comment ): string {
		$row = $this->getDBConnection( DB_REPLICA )
			->newSelectQueryBuilder()
			->select( 'cser_text' )
			->from( 'cs_entity_revisions' )
			->where( [
				'cser_entity_id' => $comment->getId(),
				'cser_deleted' => 0
			] )
			->orderBy( [ 'cser_timestamp DESC', 'cser_id DESC' ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? (string)$row->cser_text : '';
	}

	/**
	 * History of an entity, newest first.
	 *
	 * @param AbstractComment $entity
	 * @return array[] list of [ 'timestamp', 'actor', 'text' ]
	 */
	public function getHistory( AbstractComment $entity ): array {
		$result = $this->getDBConnection( DB_REPLICA )
			->newSelectQueryBuilder()
			->select( [ 'cser_id', 'cser_text', 'cser_actor', 'cser_timestamp' ] )
			->from( 'cs_entity_revisions' )
			->where( [
				'cser_entity_id' => $entity->getId(),
				'cser_deleted' => 0
			] )
			->orderBy( [ 'cser_timestamp DESC', 'cser_id DESC' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$items = [];
		foreach ( $result as $row ) {
			$actor = $this->getActorUser( (int)$row->cser_actor );
			$items[(int)$row->cser_id] = [
				'timestamp' => $row->cser_timestamp,
				'actor' => $actor ? $actor->getName() : '',
				'text' => (string)$row->cser_text
			];
		}
		return $items;
	}

	/**
	 * @inheritDoc
	 */
	public function getHistoryHandler(): ?JsonSerializable {
		return new JSHistoryHandler( 'cs.talkPageStore.historyHandler.init', [
			'ext.commentStreams.talkPageStore.history'
		] );
	}

	/* ---------------------------------------------------------------------
	 * Migration helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Insert an entity with explicit author/timestamps, without running hooks.
	 * Used by maintenance/migrateToTableStorage.php.
	 *
	 * @param int $type self::TYPE_COMMENT or self::TYPE_REPLY
	 * @param int $assocPageId
	 * @param int|null $parentId
	 * @param string|null $title
	 * @param string|null $blockName
	 * @param string $created
	 * @param string $modified
	 * @param UserIdentity $author
	 * @param UserIdentity|null $lastEditor
	 * @return int|null
	 */
	public function importEntity(
		int $type, int $assocPageId, ?int $parentId, ?string $title, ?string $blockName,
		string $created, string $modified, UserIdentity $author, ?UserIdentity $lastEditor
	): ?int {
		$dbw = $this->getDBConnection( DB_PRIMARY );
		$authorActor = $this->actorNormalization->acquireActorId( $author, $dbw );
		$lastEditorActor = $lastEditor
			? $this->actorNormalization->acquireActorId( $lastEditor, $dbw )
			: null;
		$dbw->newInsertQueryBuilder()
			->insertInto( 'cs_entities' )
			->row( [
				'cse_type' => $type,
				'cse_assoc_page_id' => $assocPageId,
				'cse_parent_id' => $parentId,
				'cse_title' => $title,
				'cse_block_name' => $blockName,
				'cse_author_actor' => $authorActor,
				'cse_last_editor_actor' => $lastEditorActor,
				'cse_created' => $created,
				'cse_modified' => $modified,
				'cse_deleted' => 0
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->insertId();
	}

	/**
	 * Append a revision to an entity without running hooks.
	 *
	 * @param int $entityId
	 * @param string $wikitext
	 * @param UserIdentity $actor
	 * @param string $timestamp
	 * @return void
	 */
	public function importRevision( int $entityId, string $wikitext, UserIdentity $actor, string $timestamp ): void {
		$dbw = $this->getDBConnection( DB_PRIMARY );
		$actorId = $this->actorNormalization->acquireActorId( $actor, $dbw );
		$this->insertRevision( $entityId, $wikitext, $actorId, $timestamp, $dbw );
	}

	/**
	 * Set entity metadata directly. Used by the migration script.
	 *
	 * @param AbstractComment $entity
	 * @param array $data keys: created, modified, lastEditor
	 * @param Authority $actor
	 * @return void
	 */
	public function forceSetEntityData( AbstractComment $entity, array $data, Authority $actor ) {
		$dbw = $this->getDBConnection( DB_PRIMARY );
		$set = [];
		if ( isset( $data['created'] ) ) {
			$set['cse_created'] = $data['created'];
		}
		if ( isset( $data['modified'] ) ) {
			$set['cse_modified'] = $data['modified'];
		}
		if ( !empty( $data['lastEditor'] ) ) {
			$set['cse_last_editor_actor'] = $this->actorNormalization->acquireActorId(
				$data['lastEditor'], $dbw
			);
		}
		if ( !$set ) {
			return;
		}
		$dbw->newUpdateQueryBuilder()
			->update( 'cs_entities' )
			->set( $set )
			->where( [ 'cse_id' => $entity->getId() ] )
			->caller( __METHOD__ )
			->execute();
		unset( $this->rowCache[$entity->getId()] );
	}
}
