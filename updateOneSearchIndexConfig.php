<?php
/**
 * Update the search configuration on the search backend.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if( $IP === false ) {
	$IP = __DIR__ . '/../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateOneSearchIndexConfig extends Maintenance {
	private $indexType, $rebuild, $closeOk;

	// Is the index currently closed?
	private $closed = false;

	private $reindexChunkSize = 1000;

	private $indexIdentifier;
	private $reindexAndRemoveOk;
	// How much should this script indent output?
	private $indent;
	private $returnCode = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of one search index." );
		$this->addOption( 'indexType', 'Index to update.  Either content or general.', true, true );
		$this->addOption( 'indent', 'String used to indent every line output in this script.', false,
			true);
		self::addSharedOptions( $this );
	}

	public static function addSharedOptions( $maintenance ) {
		$maintenance->addOption( 'rebuild', 'Blow away the identified index and rebuild it from ' .
			'scratch.' );
		$maintenance->addOption( 'forceOpen', "Open the index but do nothing else.  Use this if " .
			"you've stuck the index closed and need it to start working right now." );
		$maintenance->addOption( 'closeOk', "Allow the script to close the index if decides it has " .
			"to.  Note that it is never ok to close an index that you just created. Also note " .
			"that changing analysers might require a reindex for them to take effect so you might " .
			"be better off using --reindexAndRemoveOk and a new --indexIdentifier to rebuild the " .
			"entire index. Defaults to false." );
		$maintenance->addOption( 'forceReindex', "Perform a reindex right now." );
		$maintenance->addOption( 'indexIdentifier', "Set the identifier of the index to work on.  " .
			"You'll need this if you have an index in production serving queries and you have " .
			"to alter some portion of its configuration that cannot safely be done without " .
			"rebuilding it.  Once you specify a new indexIdentify for this wiki you'll have to " .
			"run this script with the same identifier each time.  Defaults to 'red'.", false, true);
		$maintenance->addOption( 'reindexAndRemoveOk', "If the alias is held by another index then " .
			"reindex all documents from that index (via the alias) to this one, swing the " .
			"alias to this index, and then remove other index.  You'll have to redo all updates ".
			"performed during this operation manually.  Defaults to false.");
	}

	public function execute() {
		$this->indexType = $this->getOption( 'indexType' );
		if ( $this->indexType !== CirrusSearch::CONTENT_INDEX_TYPE &&
				$this->indexType !== CirrusSearch::GENERAL_INDEX_TYPE ) {
			$this->error( 'indexType option must be ' . CirrusSearch::CONTENT_INDEX_TYPE . ' or ' .
				CirrusSearch::GENERAL_INDEX_TYPE, 1 );
		}
		$this->indent = $this->getOption( 'indent', '' );
		if ( $this->getOption( 'forceOpen', false ) ) {
			$this->getIndex()->open();
			return;
		}
		if ( $this->getOption( 'forceReindex', false ) ) {
			$this->reindex();
			return;
		}
		$this->rebuild = $this->getOption( 'rebuild', false );
		$this->closeOk = $this->getOption( 'closeOk', false );
		$this->indexIdentifier = $this->getOption( 'indexIdentifier', 'red' );
		$this->reindexAndRemoveOk = $this->getOption( 'reindexAndRemoveOk', false );

		$this->validateIndex();
		$this->validateAnalyzers();
		$this->validateMapping();
		$this->validateAlias();

		if ( $this->closed ) {
			$this->getIndex()->open();
		}
		if ( $this->returnCode ) {
			die( $this->returnCode );
		}
	}

	private function validateIndex() {
		if ( $this->rebuild ) {
			$this->output( $this->indent . "Rebuilding index..." );
			$this->createIndex( true );
			$this->output( "ok\n" );
			return;
		}
		if ( !$this->getIndex()->exists() ) {
			$this->output( $this->indent . "Creating index..." );
			$this->createIndex( false );
			$this->output( "ok\n" );
			return;
		}
		$this->output( $this->indent . "Index exists so validating...\n" );
		$settings = $this->getIndex()->getSettings()->get();

		$this->output( $this->indent . "\tValidating number of shards..." );
		$actualShardCount = $settings['index.number_of_shards'];
		if ( $actualShardCount == $this->getShardCount() ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualShardCount but should be " . $this->getShardCount() . "...cannot correct!\n" );
			$this->error(
				"Number of shards is incorrect and cannot be changed without a rebuild. You can solve this\n" .
				"problem by running this program again with either --rebuild or --reindexAndRemoveOk.  Make\n" .
				"sure you understand the consequences of either choice..  This script will now continue to\n" .
				"validate everything else." );
			$this->returnCode = 1;
		}

		$this->output( $this->indent . "\tValidating number of replicas..." );
		$actualReplicaCount = $settings['index.number_of_replicas'];
		if ( $actualReplicaCount == $this->getReplicaCount() ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualReplicaCount but should be " . $this->getReplicaCount() . '...' );
			$this->getIndex()->getSettings()->setNumberOfReplicas( $this->getReplicaCount() );
			$this->output( "corrected\n" );
		}
	}

	private function validateAnalyzers() {
		$this->output( $this->indent . "Validating analyzers..." );
		$settings = $this->getIndex()->getSettings()->get();
		$requiredAnalyzers = CirrusSearchAnalysisConfigBuilder::build();
		if ( $this->vaActualMatchRequired( 'index.analysis', $settings, $requiredAnalyzers ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
			$this->closeAndCorrect( function() use ($requiredAnalyzers) {
				$this->getIndex()->getSettings()->set( $requiredAnalyzers );
			} );
		}
	}
	private function vaActualMatchRequired( $prefix, $settings, $required ) {
		foreach( $required as $key => $value ) {
			$settingsKey = $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				if ( !$this->vaActualMatchRequired( $settingsKey, $settings, $value ) ) {
					return false;
				}
				continue;
			}
			// Note that I really mean !=, not !==.  Coercion is cool here.
			if ( !array_key_exists( $settingsKey, $settings ) || $settings[ $settingsKey ] != $value ) {
				return false;
			}
		}
		return true;
	}

	private function validateMapping() {
		$this->output( $this->indent . "Validating mappings...\n" );
		$actualMappings = $this->getIndex()->getMapping();
		$actualMappings = $actualMappings[ $this->getIndex()->getName() ];

		$this->output( $this->indent . "\tValidating mapping for page type..." );
		$requiredPageMappings = CirrusSearchMappingConfigBuilder::build();
		if ( array_key_exists( 'page', $actualMappings) &&
				$this->vmActualMatchRequired( $actualMappings[ 'page' ][ 'properties' ], $requiredPageMappings ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "different..." );
			// TODO Conflict resolution here might leave old portions of mappings
			$action = new \Elastica\Type\Mapping( $this->getPageType(), $requiredPageMappings );
			try {
				$action->send();
				$this->output( "corrected\n" );
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				$this->output( "failed!\n" );
				$message = $e->getMessage();
				$this->error( "Couldn't update mappings.  Here is elasticsearch's error message: $message\n" );
				$this->returnCode = 1;
			}
		}
	}

	private function vmActualMatchRequired( $actual, $required ) {
		foreach( $required as $key => $value ) {
			if ( !array_key_exists( $key, $actual ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( !is_array( $actual[ $key ] ) ) {
					return false;
				}
				if ( !$this->vmActualMatchRequired( $actual[ $key ], $value ) ) {
					return false;
				}
				continue;
			}
			// Note that I really mean !=, not !==.  Coercion is cool here.
			if ( $actual[ $key ] != $value ) {
				return false;
			}
		}
		return true;
	}

	private function validateAlias() {
		$this->output( $this->indent . "Validating aliases...\n" );
		// Validate the all alias first because the old index can be removed as a side effect of correcting
		// the specific alias.  This way the all alias is always pointing to at least one useful index.
		$this->validateAllAlias();
		$this->validateSpecificAlias();
	}

	/**
	 * Validate the alias that is just for this index's type.
	 */
	private function validateSpecificAlias() {
		$this->output( $this->indent . "\tValidating $this->indexType alias..." );
		$otherIndeciesWithAlias = array();
		foreach ( CirrusSearch::getClient()->getStatus()
				->getIndicesWithAlias( CirrusSearch::getIndexName( $this->indexType ) ) as $index ) {
			if( $index->getName() === CirrusSearch::getIndexName( $this->indexType, $this->indexIdentifier ) ) {
				$this->output( "ok\n" );
				return;
			} else {
				$otherIndeciesWithAlias[] = $index->getName();
			}
		}
		if ( !$otherIndeciesWithAlias ) {
			$this->output( "alias is free..." );
			$this->getIndex()->addAlias( CirrusSearch::getIndexName( $this->indexType ), false );
			$this->output( "corrected\n" );
			return;
		}
		if ( $this->reindexAndRemoveOk ) {
			$this->output( "is taken...\n" );
			$this->output( $this->indent . "\tReindexing...\n");
			// Muck with $this->indent because reindex is used to running at the top level.
			$saveIndent = $this->indent;
			$this->indent = $this->indent . "\t\t";
			$this->reindex();
			$this->indent = $saveIndent;
			$this->output( $this->indent . "\tSwapping alias...");
			$this->getIndex()->addAlias( CirrusSearch::getIndexName( $this->indexType ), true );
			$this->output( "done\n" );
			$this->output( $this->indent . "\tRemoving old index..." );
			foreach ( $otherIndeciesWithAlias as $otherIndex ) {
				CirrusSearch::getClient()->getIndex( $otherIndex )->delete();
			}
			$this->output( "done\n" );
			return;
		}
		$this->output( "cannot correct!\n" );
		$this->error(
			"The alias is held by another index which means it might be actively serving\n" .
			"queries.  You can solve this problem by running this program again with\n" .
			"--reindexAndRemoveOk.  Make sure you understand the consequences of either\n" .
			"choice." );
		$this->returnCode = 1;
	}

	public function validateAllAlias() {
		$this->output( $this->indent . "\tValidating all alias..." );
		foreach ( CirrusSearch::getClient()->getStatus()
				->getIndicesWithAlias( CirrusSearch::getIndexName() ) as $index ) {
			if( $index->getName() === CirrusSearch::getIndexName( $this->indexType, $this->indexIdentifier ) ) {
				$this->output( "ok\n" );
				return;
			}
		}
		$this->output( "alias not already assigned to this index..." );
		$this->getIndex()->addAlias( CirrusSearch::getIndexName(), false );
		$this->output( "corrected\n" );
	}

	/**
	 * Rebuild the index by pulling everything out of it and putting it back in.  This should be faster than
	 * reparsing everything.
	 */
	private function reindex() {
		$query = new Elastica\Query();
		$query->setFields( array( '_id', '_source' ) );

		// Note here we dump from the current index (using the alias) so we can use CirrusSearch::getPageType
		$result = CirrusSearch::getPageType( $this->indexType )->search( $query, array(
			'search_type' => 'scan',
			'scroll' => '10m',
			'size'=> $this->reindexChunkSize / $this->getShardCount()
		) );
		$totalDocsToReindex = $result->getResponse()->getData();
		$totalDocsToReindex = $totalDocsToReindex['hits']['total'];
		$this->output( $this->indent . "About to reindex $totalDocsToReindex documents\n" );
		$operationStartTime = microtime( true );
		$completed = 0;
		$rate = 0;

		while ( true ) {
			wfProfileIn( __method__ . '::receiveDocs' );
			$result = $this->getIndex()->search( array(), array(
				'scroll_id' => $result->getResponse()->getScrollId(),
				'scroll' => '10m'
			) );
			wfProfileOut( __method__ . '::receiveDocs' );
			if ( !$result->count() ) {
				$this->output( $this->indent . "All done\n" );
				break;
			}
			wfProfileIn( __method__ . '::packageDocs' );
			$documents = array();
			while ( $result->current() ) {
				$documents[] = new \Elastica\Document( $result->current()->getId(), $result->current()->getSource() );
				$result->next();
			}
			wfProfileOut( __method__ . '::packageDocs' );
			wfProfileIn( __method__ . '::sendDocs' );
			$updateResult = $this->getPageType()->addDocuments( $documents );
			wfDebugLog( 'CirrusSearch', 'Update completed in ' . $updateResult->getEngineTime() . ' (engine) millis' );
			wfProfileOut( __method__ . '::sendDocs' );
			$completed += $result->count();
			$rate = round( $completed / ( microtime( true ) - $operationStartTime ) );
			$this->output( $this->indent . "Reindexed $completed/$totalDocsToReindex documents at $rate/second\n");
		}
	}

	private function createIndex( $rebuild ) {
		$this->getIndex()->create( array(
			'settings' => array(
				'number_of_shards' => $this->getShardCount(),
				'number_of_replicas' => $this->getReplicaCount(),
				'analysis' => CirrusSearchAnalysisConfigBuilder::build(),
			)
		), $rebuild );
		$this->closeOk = false;
	}

	private function closeAndCorrect( $callback ) {
		if ( $this->closeOk ) {
			$this->getIndex()->close();
			$this->closed = true;
			$callback();
			$this->output( "corrected\n" );
		} else {
			$this->output( "cannot correct\n" );
			$this->error("This script encountered an index difference that requires that the index be\n" .
				"closed, modified, and then reopened.  To allow this script to close the index run it\n" .
				"with the --closeOk parameter and it'll close the index for the briefest possible time\n" .
				"Note that the index will be unusable while closed." );
			$this->returnCode = 1;
		}
	}

	/**
	 * Get the index being updated by the search config.
	 */
	private function getIndex() {
		return CirrusSearch::getIndex( $this->indexType, $this->indexIdentifier );
	}

	/**
	 * Get the type being updated by the search config.
	 */
	private function getPageType() {
		return $this->getIndex()->getType( CirrusSearch::PAGE_TYPE_NAME );
	}

	private function getShardCount() {
		global $wgCirrusSearchShardCount;
		return $wgCirrusSearchShardCount[ $this->indexType ];
	}

	private function getReplicaCount() {
		global $wgCirrusSearchContentReplicaCount;
		return $wgCirrusSearchContentReplicaCount[ $this->indexType ];
	}
}

$maintClass = "UpdateOneSearchIndexConfig";
require_once RUN_MAINTENANCE_IF_MAIN;