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

use MediaWiki\Content\TextContent;
use MediaWiki\Extension\CommentStreams\Store\TableStore;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\Maintenance\LoggedUpdateOutcome;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$IP ??= getenv( "MW_INSTALL_PATH" ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

/**
 * Migrate comments from the CommentStreams namespace storage model
 * (`namespace-page`) to the table storage model (`table`).
 *
 * The source data (cs_comments, cs_replies and the NS_COMMENTSTREAMS pages)
 * is left untouched unless --delete-source is given, so the migration can be
 * rolled back by switching $wgCommentStreamsStoreModel back.
 */
class MigrateToTableStorage extends LoggedUpdateMaintenance {

	private const TYPE_COMMENT = 0;
	private const TYPE_REPLY = 1;

	private ?TableStore $store = null;

	/** @var array<int,int> old page ID => new entity ID */
	private array $idMap = [];

	/** @var array<int,int> old comment page ID => associated page ID */
	private array $assocMap = [];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Migrate comments from the CommentStreams namespace to the table storage model'
		);
		$this->addOption( 'delete-source', 'Delete source pages after migration' );
		$this->addOption( 'quick', 'Skip countdown' );
		$this->addOption( 'dry-run', 'Only report what would be migrated' );
		$this->addOption(
			'associated-page',
			'Only migrate comments attached to this page title (for testing)',
			false,
			true
		);
	}

	/**
	 * @return string
	 */
	protected function getUpdateKey() {
		return 'comment-streams-migrate-to-table';
	}

	/**
	 * @return TableStore|null
	 */
	private function getTableStore(): ?TableStore {
		$store = $this->getServiceContainer()->getService( 'CommentStreamsStore' );
		if ( $store instanceof TableStore ) {
			return $store;
		}
		// Allow migrating while another model is still active: build the
		// table store directly from the extension attribute.
		$spec = ExtensionRegistry::getInstance()->getAttribute( 'CommentStreamsStore' )['table'] ?? null;
		if ( !$spec ) {
			return null;
		}
		$this->output(
			"Note: the configured store model is not 'table'; migrating into a TableStore instance directly.\n"
		);
		$object = $this->getServiceContainer()->getObjectFactory()->createObject( $spec );
		return $object instanceof TableStore ? $object : null;
	}

	/**
	 * @return LoggedUpdateOutcome
	 */
	public function doDBUpdates() {
		$this->store = $this->getTableStore();
		if ( !$this->store ) {
			$this->fatalError( 'Could not instantiate the table store' );
		}

		$dryRun = $this->hasOption( 'dry-run' );
		$filterTitle = null;
		if ( $this->hasOption( 'associated-page' ) ) {
			$filterTitle = Title::newFromText( $this->getOption( 'associated-page' ) );
			if ( !$filterTitle || !$filterTitle->exists() ) {
				$this->fatalError( 'Unknown associated page: ' . $this->getOption( 'associated-page' ) );
			}
		}

		$this->output( "Migrating comments to the table storage model\n" );
		if ( $dryRun ) {
			$this->output( "DRY RUN: nothing will be written\n" );
		}
		if ( $filterTitle ) {
			$this->output( "Filter: only page {$filterTitle->getPrefixedText()}\n" );
		}
		if ( !$this->hasOption( 'quick' ) && !$dryRun ) {
			$this->output( "Abort with CTRL+C if this is not intended... " );
			$this->countDown( 5 );
			$this->output( "\n" );
		}

		$comments = $this->getCommentRows( $filterTitle );
		$this->output( 'Found ' . count( $comments ) . " comments\n" );

		$migrated = 0;
		foreach ( $comments as $row ) {
			$oldId = (int)$row->cst_c_comment_page_id;
			$assocId = (int)$row->cst_c_assoc_page_id;
			$this->assocMap[$oldId] = $assocId;
			$title = Title::newFromID( $oldId );
			if ( !$title || !$title->exists() ) {
				$this->output( "  skip dangling comment metadata (page $oldId)\n" );
				continue;
			}
			$revisions = $this->getRevisions( $oldId, (string)$row->cst_c_comment_title );
			if ( !$revisions ) {
				$this->output( "  skip comment without revisions (page $oldId)\n" );
				continue;
			}
			if ( $dryRun ) {
				// Keep the ID map complete so that replies are reported too.
				$this->idMap[$oldId] = 0;
				$migrated++;
				continue;
			}
			$first = $revisions[0];
			$last = $revisions[count( $revisions ) - 1];
			$newId = $this->store->importEntity(
				self::TYPE_COMMENT,
				$assocId,
				null,
				(string)$row->cst_c_comment_title,
				$row->cst_c_block_name !== null ? (string)$row->cst_c_block_name : null,
				$first['timestamp'],
				$last['timestamp'],
				$first['actor'],
				count( $revisions ) > 1 ? $last['actor'] : null
			);
			if ( !$newId ) {
				$this->error( "  failed to import comment page $oldId" );
				continue;
			}
			$this->idMap[$oldId] = $newId;
			foreach ( $revisions as $revision ) {
				$this->store->importRevision( $newId, $revision['text'], $revision['actor'], $revision['timestamp'] );
			}
			$this->maybeDeleteSource( $title );
			$migrated++;
		}
		$this->output( "Comments migrated: $migrated\n" );

		$replies = $this->getReplyRows();
		$migratedReplies = 0;
		foreach ( $replies as $row ) {
			$oldReplyId = (int)$row->cst_r_reply_page_id;
			$oldCommentId = (int)$row->cst_r_comment_page_id;
			if ( !isset( $this->idMap[$oldCommentId] ) || !isset( $this->assocMap[$oldCommentId] ) ) {
				continue;
			}
			$title = Title::newFromID( $oldReplyId );
			if ( !$title || !$title->exists() ) {
				$this->output( "  skip dangling reply metadata (page $oldReplyId)\n" );
				continue;
			}
			$revisions = $this->getRevisions( $oldReplyId, '' );
			if ( !$revisions ) {
				continue;
			}
			if ( $dryRun ) {
				$this->idMap[$oldReplyId] = 0;
				$migratedReplies++;
				continue;
			}
			$first = $revisions[0];
			$last = $revisions[count( $revisions ) - 1];
			$newId = $this->store->importEntity(
				self::TYPE_REPLY,
				$this->assocMap[$oldCommentId],
				$this->idMap[$oldCommentId],
				null,
				null,
				$first['timestamp'],
				$last['timestamp'],
				$first['actor'],
				count( $revisions ) > 1 ? $last['actor'] : null
			);
			if ( !$newId ) {
				$this->error( "  failed to import reply page $oldReplyId" );
				continue;
			}
			$this->idMap[$oldReplyId] = $newId;
			foreach ( $revisions as $revision ) {
				$this->store->importRevision( $newId, $revision['text'], $revision['actor'], $revision['timestamp'] );
			}
			$this->maybeDeleteSource( $title );
			$migratedReplies++;
		}
		$this->output( "Replies migrated: $migratedReplies\n" );

		if ( !$dryRun ) {
			$this->remapVotesAndWatchlist();
		}

		$this->output( 'Done' );
		if ( $dryRun ) {
			return LoggedUpdateOutcome::SIMULATED;
		}
		return LoggedUpdateOutcome::COMPLETE;
	}

	/**
	 * @param Title|null $filterTitle
	 * @return array
	 */
	private function getCommentRows( ?Title $filterTitle ): array {
		$qb = $this->getReplicaDB()->newSelectQueryBuilder()
			->select( [ 'cst_c_comment_page_id', 'cst_c_assoc_page_id', 'cst_c_comment_title', 'cst_c_block_name' ] )
			->from( 'cs_comments' )
			->orderBy( 'cst_c_comment_page_id' );
		if ( $filterTitle ) {
			$qb->where( [ 'cst_c_assoc_page_id' => $filterTitle->getArticleID() ] );
		}
		return iterator_to_array( $qb->fetchResultSet() );
	}

	/**
	 * @return array
	 */
	private function getReplyRows(): array {
		return iterator_to_array(
			$this->getReplicaDB()->newSelectQueryBuilder()
				->select( [ 'cst_r_reply_page_id', 'cst_r_comment_page_id' ] )
				->from( 'cs_replies' )
				->orderBy( 'cst_r_reply_page_id' )
				->fetchResultSet()
		);
	}

	/**
	 * All revisions of a comment/reply page, oldest first.
	 *
	 * @param int $pageId
	 * @param string $commentTitle used to strip the DISPLAYTITLE annotation
	 * @return array[] list of [ 'text', 'actor', 'timestamp' ]
	 */
	private function getRevisions( int $pageId, string $commentTitle ): array {
		$revIds = $this->getReplicaDB()->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'revision' )
			->where( [ 'rev_page' => $pageId ] )
			->orderBy( [ 'rev_timestamp', 'rev_id' ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$revisionLookup = $this->getServiceContainer()->getRevisionLookup();
		$revisions = [];
		foreach ( $revIds as $revId ) {
			$revision = $revisionLookup->getRevisionById( (int)$revId );
			if ( !$revision ) {
				continue;
			}
			$content = $revision->getContent( SlotRecord::MAIN );
			if ( !( $content instanceof TextContent ) ) {
				continue;
			}
			$actor = $revision->getUser();
			if ( !$actor ) {
				continue;
			}
			$revisions[] = [
				'text' => $this->removeAnnotations( $content->getText(), $commentTitle ),
				'actor' => $actor,
				'timestamp' => $revision->getTimestamp()
			];
		}
		return $revisions;
	}

	/**
	 * Remap cs_votes / cs_watchlist rows from old comment page IDs to the new
	 * entity IDs. Rows without a mapping (dangling metadata) are reported.
	 *
	 * @return void
	 */
	private function remapVotesAndWatchlist(): void {
		$dbw = $this->getPrimaryDB();
		$unmapped = 0;
		foreach ( [ 'cs_votes' => 'cst_v_comment_id', 'cs_watchlist' => 'cst_wl_comment_id' ] as $table => $column ) {
			$oldIds = $dbw->newSelectQueryBuilder()
				->select( $column )
				->from( $table )
				->caller( __METHOD__ )
				->fetchFieldValues();
			foreach ( $oldIds as $oldId ) {
				$oldId = (int)$oldId;
				if ( !isset( $this->idMap[$oldId] ) ) {
					$unmapped++;
					continue;
				}
				$dbw->newUpdateQueryBuilder()
					->update( $table )
					->set( [ $column => $this->idMap[$oldId] ] )
					->where( [ $column => $oldId ] )
					->caller( __METHOD__ )
					->execute();
			}
		}
		if ( $unmapped ) {
			$this->output( "Rows without a matching comment (left untouched): $unmapped\n" );
		}
	}

	/**
	 * @param string $wikitext
	 * @param string $commentTitle
	 * @return string
	 */
	private function removeAnnotations( string $wikitext, string $commentTitle ): string {
		if ( !$commentTitle ) {
			return $wikitext;
		}
		$strip = <<<EOT
{{DISPLAYTITLE:
$commentTitle
}}
EOT;
		return str_replace( $strip, '', $wikitext );
	}

	/**
	 * @param Title $source
	 * @return void
	 */
	private function maybeDeleteSource( Title $source ) {
		if ( !$this->hasOption( 'delete-source' ) ) {
			return;
		}
		$deletePage = $this->getServiceContainer()->getDeletePageFactory()->newDeletePage(
			$source->toPageIdentity(),
			User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] )
		);
		$deletePage->deleteUnsafe( 'Migrated to table storage' );
	}
}

$maintClass = MigrateToTableStorage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
