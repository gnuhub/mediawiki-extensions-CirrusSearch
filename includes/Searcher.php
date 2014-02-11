<?php

namespace CirrusSearch;
use Elastica;
use \CirrusSearch;
use \MWNamespace;
use \PoolCounterWorkViaCallback;
use \Sanitizer;
use \Status;
use \Title;
use \UsageException;

/**
 * Performs searches using Elasticsearch.  Note that each instance of this class
 * is single use only.
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
class Searcher extends ElasticsearchIntermediary {
	const SUGGESTION_NAME_TITLE = 'title';
	const SUGGESTION_NAME_REDIRECT = 'redirect';
	const SUGGESTION_NAME_TEXT = 'text_suggestion';
	const SUGGESTION_HIGHLIGHT_PRE = '<em>';
	const SUGGESTION_HIGHLIGHT_POST = '</em>';
	const HIGHLIGHT_PRE = '<span class="searchmatch">';
	const HIGHLIGHT_POST = '</span>';
	const HIGHLIGHT_REGEX = '/<span class="searchmatch">.*?<\/span>/';

	/**
	 * Maximum title length that we'll check in prefix and keyword searches.
	 * Since titles can be 255 bytes in length we're setting this to 255
	 * characters.
	 */
	const MAX_TITLE_SEARCH = 255;

	/**
	 * @var integer search offset
	 */
	private $offset;
	/**
	 * @var integer maximum number of result
	 */
	private $limit;
	/**
	 * @var array(integer) namespaces in which to search
	 */
	private $namespaces;
	/**
	 * @var ResultsType|null type of results.  null defaults to FullTextResultsType
	 */
	private $resultsType;
	/**
	 * @var string sort type
	 */
	private $sort = 'relevance';
	/**
	 * @var array(string) prefixes that should be prepended to suggestions.  Can be added to externally and is added to
	 * during search syntax parsing.
	 */
	private $suggestPrefixes = array();
	/**
	 * @var array(string) suffixes that should be prepended to suggestions.  Can be added to externally and is added to
	 * during search syntax parsing.
	 */
	private $suggestSuffixes = array();


	// These fields are filled in by the particule search methods
	/**
	 * @var string term to search.
	 */
	private $term;
	/**
	 * @var \Elastica\Query\AbstractQuery|null main query.  null defaults to \Elastica\Query\MatchAll
	 */
	private $query = null;
	/**
	 * @var array(\Elastica\Filter\AbstractFilter) filters that MUST hold true of all results
	 */
	private $filters = array();
	/**
	 * @var array(\Elastica\Filter\AbstractFilter) filters that MUST NOT hold true of all results
	 */
	private $notFilters = array();
	private $suggest = null;
	/**
	 * @var null|array of rescore configuration as used by elasticsearch.  The query needs to be an Elastica query.
	 */
	private $rescore = null;
	/**
	 * @var float portion of article's score which decays with time.  Defaults to 0 meaning don't decay the score
	 * with time since the last update.
	 */
	private $preferRecentDecayPortion = 0;
	/**
	 * @var float number of days it takes an the portion of an article score that will decay with time
	 * since last update to decay half way.  Defaults to 0 meaning don't decay the score with time.
	 */
	private $preferRecentHalfLife = 0;
	/**
	 * @var string should the query results boost pages with more incoming links.  Default to empty stream meaning
	 * don't boost.  Other values are 'linear' meaning boost score linearly with number of incoming links or 'log'
	 * meaning boost score by log10(incoming_links + 2).
	 */
	private $boostLinks = '';
	/**
	 * @var array template name to boost multiplier for having a template.  Defaults to none but initialized by
	 * queries that use it to self::getDefaultBoostTemplates() if they need it.  That is too expensive to do by
	 * default though.
	 */
	private $boostTemplates = array();
	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;
	/**
	 * @var bool should this search show redirects?
	 */
	private $showRedirects;

	/**
	 * @var boolean is this a fuzzy query?
	 */
	private $fuzzyQuery = false;
	/**
	 * @var boolean did this search contain any special search syntax?
	 */
	private $searchContainedSyntax = false;

	/**
	 * Constructor
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param array $namespaces Namespace numbers to search
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string $index Base name for index to search from, defaults to wfWikiId()
	 */
	public function __construct( $offset, $limit, $namespaces, $user, $index = false ) {
		global $wgCirrusSearchSlowSearch;

		parent::__construct( $user, $wgCirrusSearchSlowSearch );
		$this->offset = $offset;
		$this->limit = $limit;
		$this->namespaces = $namespaces;
		$this->indexBaseName = $index ?: wfWikiId();
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->resultsType = $resultsType;
	}

	/**
	 * Set the type of sort to perform.  Must be 'relevance', 'title_asc', 'title_desc'.
	 * @param string sort type
	 */
	public function setSort( $sort ) {
		$this->sort = $sort;
	}

	/**
	 * Perform a "near match" title search which is pretty much a prefix match without the prefixes.
	 * @param string $search text by which to search
	 * @return Status(mixed) status containing results defined by resultsType on success
	 */
	public function nearMatchTitleSearch( $search ) {
		wfProfileIn( __METHOD__ );
		self::checkTitleSearchRequestLength( $search );

		$match = new \Elastica\Query\Match();
		$match->setField( 'title.near_match', $search );
		$this->filters[] = new \Elastica\Filter\Query( $match );
		$this->boostLinks = ''; // No boost

		$result = $this->search( 'near_match', $search );
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * Perform a prefix search.
	 * @param string $search text by which to search
	 * @param Status(mixed) status containing results defined by resultsType on success
	 */
	public function prefixSearch( $search ) {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchPrefixSearchStartsWithAnyWord;

		self::checkTitleSearchRequestLength( $search );

		if ( $wgCirrusSearchPrefixSearchStartsWithAnyWord ) {
			$match = new \Elastica\Query\Match();
			$match->setField( 'title.word_prefix', array(
				'query' => $search,
				'analyzer' => 'plain',
				'operator' => 'and',
			) );
			$this->filters[] = new \Elastica\Filter\Query( $match );
		} else {
			$this->filters[] = $this->buildPrefixFilter( $search );
		}
		$this->boostLinks = 'linear';
		$this->boostTemplates = self::getDefaultBoostTemplates();

		$result = $this->search( 'prefix', $search );
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * @param string $suggestPrefix prefix to be prepended to suggestions
	 */
	public function addSuggestPrefix( $suggestPrefix ) {
		$this->suggestPrefixes[] = $suggestPrefix;
	}

	/**
	 * Search articles with provided term.
	 * @param $term string term to search
	 * @param $showRedirects boolean should this request show redirects?
	 * @param boolean $showSuggestion should this search suggest alternative searches that might be better?
	 * @param Status(mixed) status containing results defined by resultsType on success
	 */
	public function searchText( $term, $showRedirects, $showSuggestion ) {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchPhraseRescoreBoost;
		global $wgCirrusSearchPhraseRescoreWindowSize;
		global $wgCirrusSearchPhraseUseText;
		global $wgCirrusSearchPreferRecentDefaultDecayPortion;
		global $wgCirrusSearchPreferRecentDefaultHalfLife;
		global $wgCirrusSearchStemmedWeight;

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$searcher = $this;
		$originalTerm = $term;
		$searchContainedSyntax = false;
		$this->showRedirects = $showRedirects;
		$this->term = trim( $term );
		// Handle title prefix notation
		wfProfileIn( __METHOD__ . '-prefix-filter' );
		$prefixPos = strpos( $this->term, 'prefix:' );
		if ( $prefixPos !== false ) {
			$value = substr( $this->term, 7 + $prefixPos );
			$value = trim( $value, '"' ); // Trim quotes in case the user wanted to quote the prefix
			if ( strlen( $value ) > 0 ) {
				$searchContainedSyntax = true;
				$this->term = substr( $this->term, 0, max( 0, $prefixPos - 1 ) );
				$this->suggestSuffixes[] = ' prefix:' . $value;
				// Suck namespaces out of $value
				$cirrusSearchEngine = new CirrusSearch();
				$value = trim( $cirrusSearchEngine->replacePrefixes( $value ) );
				$this->namespaces = $cirrusSearchEngine->namespaces;
				// If the namespace prefix wasn't the entire prefix filter then add a filter for the title
				if ( strpos( $value, ':' ) !== strlen( $value ) - 1 ) {
					$this->filters[] = $this->buildPrefixFilter( $value );
				}
			}
		}
		wfProfileOut( __METHOD__ . '-prefix-filter' );

		wfProfileIn( __METHOD__ . '-prefer-recent' );
		$preferRecentDecayPortion = $wgCirrusSearchPreferRecentDefaultDecayPortion;
		$preferRecentHalfLife = $wgCirrusSearchPreferRecentDefaultHalfLife;
		// Matches "prefer-recent:" and then an optional floating point number <= 1 but >= 0 (decay
		// portion) and then an optional comma followed by another floating point number >= 0 (half life)
		$this->extractSpecialSyntaxFromTerm(
			'/prefer-recent:(1|(?:0?(?:\.[0-9]+)?))?(?:,([0-9]*\.?[0-9]+))? ?/',
			function ( $matches ) use ( &$preferRecentDecayPortion, &$preferRecentHalfLife,
					&$searchContainedSyntax ) {
				global $wgCirrusSearchPreferRecentUnspecifiedDecayPortion;
				if ( isset( $matches[ 1 ] ) && strlen( $matches[ 1 ] ) ) {
					$preferRecentDecayPortion = floatval( $matches[ 1 ] );
				} else {
					$preferRecentDecayPortion = $wgCirrusSearchPreferRecentUnspecifiedDecayPortion;
				}
				if ( isset( $matches[ 2 ] ) ) {
					$preferRecentHalfLife = floatval( $matches[ 2 ] );
				}
				$searchContainedSyntax = true;
				return '';
			}
		);
		$this->preferRecentDecayPortion = $preferRecentDecayPortion;
		$this->preferRecentHalfLife = $preferRecentHalfLife;
		wfProfileOut( __METHOD__ . '-prefer-recent' );

		// Handle other filters
		wfProfileIn( __METHOD__ . '-other-filters' );
		$filters = $this->filters;
		$notFilters = $this->notFilters;
		$boostTemplates = self::getDefaultBoostTemplates();
		// Match filters that look like foobar:thing or foobar:"thing thing"
		// The {7,15} keeps this from having horrible performance on big strings
		$this->extractSpecialSyntaxFromTerm(
			'/(?<key>[a-z\\-]{7,15}):(?<value>(?:"[^"]+")|(?:[^ "]+)) ?/',
			function ( $matches ) use ( $searcher, &$filters, &$notFilters, &$boostTemplates,
					&$searchContainedSyntax ) {
				$key = $matches['key'];
				$value = $matches['value'];  // Note that if the user supplied quotes they are not removed
				$filterDestination = &$filters;
				$keepText = true;
				if ( $key[ 0 ] === '-' ) {
					$key = substr( $key, 1 );
					$filterDestination = &$notFilters;
					$keepText = false;
				}
				switch ( $key ) {
					case 'boost-templates':
						$boostTemplates = Searcher::parseBoostTemplates( trim( $value, '"' ) );
						if ( $boostTemplates === null ) {
							$boostTemplates = self::getDefaultBoostTemplates();
						}
						$searchContainedSyntax = true;
						return '';
					case 'hastemplate':
						$value = trim( $value, '"' );
						// We emulate template syntax here as best as possible,
						// so things in NS_MAIN are prefixed with ":" and things
						// in NS_TEMPLATE don't have a prefix at all. Since we
						// don't actually index templates like that, munge the
						// query here
						if ( strpos( $value, ':' ) === 0 ) {
							$value = substr( $value, 1 );
						} else {
							$title = Title::newFromText( $value );
							if ( $title && $title->getNamespace() == NS_MAIN ) {
								$value = Title::makeTitle( NS_TEMPLATE,
									$title->getDBkey() )->getPrefixedText();
							}
						}
						// Intentional fall through
					case 'incategory':
						$queryKey = str_replace( array( 'in', 'has' ), '', $key );
						$queryValue = str_replace( '_', ' ', trim( $value, '"' ) );
						$match = new \Elastica\Query\Match();
						$match->setFieldQuery( $queryKey, $queryValue );
						$filterDestination[] = new \Elastica\Filter\Query( $match );
						$searchContainedSyntax = true;
						return '';
					case 'intitle':
						$filterDestination[] = new \Elastica\Filter\Query( new \Elastica\Query\Field( 'title',
							$searcher->fixupWholeQueryString(
								$searcher->fixupQueryStringPart( $value )
							) ) );
						$searchContainedSyntax = true;
						return $keepText ? "$value " : '';
					default:
						return $matches[0];
				}
			}
		);
		$this->filters = $filters;
		$this->notFilters = $notFilters;
		$this->boostTemplates = $boostTemplates;
		$this->boostLinks = 'log';
		$this->searchContainedSyntax = $searchContainedSyntax;
		wfProfileOut( __METHOD__ . '-other-filters' );
		wfProfileIn( __METHOD__ . '-find-phrase-queries' );
		// Match quoted phrases including those containing escaped quotes
		// Those phrases can optionally be followed by ~ then a number (this is the phrase slop)
		// That can optionally be followed by a ~ (this matches stemmed words in phrases)
		// The following all match: "a", "a boat", "a\"boat", "a boat"~, "a boat"~9, "a boat"~9~
		$query = self::replacePartsOfQuery( $this->term, '/(?<main>"((?:[^"]|(?:\"))+)"(?:~[0-9]+)?)(?<fuzzy>~)?/',
			function ( $matches ) use ( $searcher ) {
				$main = $searcher->fixupQueryStringPart( $matches[ 'main' ][ 0 ] );
				if ( !isset( $matches[ 'fuzzy' ] ) ) {
					$main = $searcher->switchSearchToExact( $main );
				}
				return array( 'escaped' => $main );
			} );
		wfProfileOut( __METHOD__ . '-find-phrase-queries' );
		wfProfileIn( __METHOD__ . '-switch-prefix-to-plain' );
		// Find prefix matches and force them to only match against the plain analyzed fields.  This
		// prevents prefix matches from getting confused by stemming.  Users really don't expect stemming
		// in prefix queries.
		$query = self::replaceAllPartsOfQuery( $query, '/\w*\*(?:\w*\*?)*/',
			function ( $matches ) use ( $searcher ) {
				$term = $searcher->fixupQueryStringPart( $matches[ 0 ][ 0 ] );
				return array( 'escaped' => $searcher->switchSearchToExact( $term ) );
			} );
		wfProfileOut( __METHOD__ . '-switch-prefix-to-plain' );

		wfProfileIn( __METHOD__ . '-escape' );
		$escapedQuery = array();
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'escaped' ] ) ) {
				$escapedQuery[] = $queryPart[ 'escaped' ];
				continue;
			}
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$escapedQuery[] = $this->fixupQueryStringPart( $queryPart[ 'raw' ] );
				continue;
			}
			wfLogWarning( 'Unknown query part:  ' . serialize( $queryPart ) );
		}
		wfProfileOut( __METHOD__ . '-escape' );

		// Actual text query
		$queryStringQueryString = $this->fixupWholeQueryString( implode( ' ', $escapedQuery ) );
		if ( $queryStringQueryString !== '' ) {
			if ( $this->queryStringContainsSyntax( $queryStringQueryString ) ) {
				$this->searchContainedSyntax = true;
				// We're unlikey to make good suggestions for query string with special syntax in them....
				$showSuggestion = false;
			}
			wfProfileIn( __METHOD__ . '-build-query' );
			$fields = array_merge(
				$this->buildFullTextSearchFields( 1, '.plain' ),
				$this->buildFullTextSearchFields( $wgCirrusSearchStemmedWeight, '' ) );
			$this->query = $this->buildSearchTextQuery( $fields, $queryStringQueryString );

			// Only do a phrase match rescore if the query doesn't include any phrases
			if ( $wgCirrusSearchPhraseRescoreBoost > 1.0 && strpos( $queryStringQueryString, '"' ) === false ) {
				$this->rescore = array(
					'window_size' => $wgCirrusSearchPhraseRescoreWindowSize,
					'query' => array(
						'rescore_query' => $this->buildSearchTextQuery( $fields, '"' . $queryStringQueryString . '"' ),
						'query_weight' => 1.0,
						'rescore_query_weight' => $wgCirrusSearchPhraseRescoreBoost,
					)
				);
			}

			if ( $showSuggestion ) {
				$this->suggest = array(
					'text' => $this->term,
					self::SUGGESTION_NAME_TITLE => $this->buildSuggestConfig( 'title.suggest' ),
				);
				if ( $showRedirects ) {
					$this->suggest[ self::SUGGESTION_NAME_REDIRECT ] = $this->buildSuggestConfig( 'redirect.title.suggest' );
				}
				if ( $wgCirrusSearchPhraseUseText ) {
					$this->suggest[ self::SUGGESTION_NAME_TEXT ] = $this->buildSuggestConfig( 'text.suggest' );
				}
			}
			wfProfileOut( __METHOD__ . '-build-query' );

			$result = $this->search( 'full_text', $originalTerm );

			if ( !$result->isOK() && $this->isParseError( $result ) ) {
				wfProfileIn( __METHOD__ . '-degraded-query' );
				// Elasticsearch has reported a parse error and we've already logged it when we built the status
				// so at this point all we can do is retry the query as a simple query string query.
				$this->query = new \Elastica\Query\Simple( array( 'simple_query_string' => array(
					'fields' => $fields,
					'query' => $queryStringQueryString,
					'default_operator' => 'AND',
				) ) );
				$this->rescore = null; // Not worth trying in this state.
				$result = $this->search( 'degraded_full_text', $originalTerm );
				// If that doesn't work we're out of luck but it should.  There no guarantee it'll work properly
				// with the syntax we've built above but it'll do _something_ and we'll still work on fixing all
				// the parse errors that come in.
				wfProfileOut( __METHOD__ . '-degraded-query' );
			}
		} else {
			$result = $this->search( 'full_text', $originalTerm );
			// No need to check for a parse error here because we don't actually create a query for
			// Elasticsearch to parse
		}
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * @param $id article id to search
	 * @return Status(ResultSet|null)
	 */
	public function moreLikeThisArticle( $id ) {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchMoreLikeThisConfig;

		// It'd be better to be able to have Elasticsearch fetch this during the query rather than make
		// two passes but it doesn't support that at this point
		$found = $this->get( $id, array( 'text' ) );
		if ( !$found->isOk() ) {
			return $found;
		}
		$found = $found->getValue();
		if ( $found === null ) {
			// If the page doesn't exist we can't find any articles like it
			return Status::newGood( null );
		}

		$this->query = new \Elastica\Query\MoreLikeThis();
		$this->query->setParams( $wgCirrusSearchMoreLikeThisConfig );
		// TODO figure out why we strip tags here and document it.
		$this->query->setLikeText( Sanitizer::stripAllTags( $found->text ) );
		$this->query->setFields( array( 'text' ) );
		$idFilter = new \Elastica\Filter\Ids();
		$idFilter->addId( $id );
		$this->filters[] = new \Elastica\Filter\BoolNot( $idFilter );

		$result = $this->search( 'more_like', "$found->namespace:$found->title" );
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * Get the page with $id.
	 * @param $id int page id
	 * @param $fields array(string) fields to fetch
	 * @return Status containing page data, null if not found, or an error if there was an error
	 */
	public function get( $id, $fields ) {
		wfProfileIn( __METHOD__ );
		$searcher = $this;
		$indexType = $this->pickIndexTypeFromNamespaces();
		$indexBaseName = $this->indexBaseName;
		$getWork = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $searcher, $id, $fields, $indexType, $indexBaseName ) {
				try {
					$searcher->start( "get of $indexType.$id" );
					$pageType = Connection::getPageType( $indexBaseName, $indexType );
					return $searcher->success( $pageType->getDocument( $id, array( 'fields' => $fields, ) ) );
				} catch ( \Elastica\Exception\NotFoundException $e ) {
					// NotFoundException just means the field didn't exist.
					// It is up to the called to decide if that is and error.
					return $searcher->success( null );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			},
			'error' => function( $status ) {
				$status = $status->getErrorsArray();
				wfLogWarning( 'Pool error performing a get against Elasticsearch:  ' . $status[ 0 ][ 0 ] );
				return Status::newFatal( 'cirrussearch-backend-error' );
			}
		) );
		$result = $getWork->execute();
		wfProfileOut( __METHOD__ );
		return $result;
	}

	private function extractSpecialSyntaxFromTerm( $regex, $callback ) {
		$suggestPrefixes = $this->suggestPrefixes;
		$this->term = preg_replace_callback( $regex,
			function ( $matches ) use ( $callback, &$suggestPrefixes ) {
				$result = $callback( $matches );
				if ( $result === '' ) {
					$suggestPrefixes[] = $matches[ 0 ];
				}
				return $result;
			},
			$this->term
		);
		$this->suggestPrefixes = $suggestPrefixes;
	}

	private static function replaceAllPartsOfQuery( $query, $regex, $callable ) {
		$result = array();
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$result = array_merge( $result, self::replacePartsOfQuery( $queryPart[ 'raw' ], $regex, $callable ) );
				continue;
			}
			$result[] = $queryPart;
		}
		return $result;
	}

	private static function replacePartsOfQuery( $queryPart, $regex, $callable ) {
		$destination = array();
		$matches = array();
		$offset = 0;
		while ( preg_match( $regex, $queryPart, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$startOffset = $matches[ 0 ][ 1 ];
			if ( $startOffset > $offset ) {
				$destination[] = array( 'raw' => substr( $queryPart, $offset, $startOffset - $offset ) );
			}

			$callableResult = call_user_func( $callable, $matches );
			if ( $callableResult ) {
				$destination[] = $callableResult;
			}

			$offset = $startOffset + strlen( $matches[ 0 ][ 0 ] );
		}
		if ( $offset < strlen( $queryPart ) ) {
			$destination[] = array( 'raw' => substr( $queryPart, $offset ) );
		}
		return $destination;
	}

	/**
	 * Get the version of Elasticsearch with which we're communicating.
	 * @return Status(string) version number as a string
	 */
	public function getElasticsearchVersion() {
		global $wgMemc;
		wfProfileIn( __METHOD__ );
		$mcKey = wfMemcKey( 'CirrusSearch', 'Elasticsearch', 'version' );
		$result = $wgMemc->get( $mcKey );
		if ( !$result ) {
			try {
				$this->start( 'fetching elasticsearch version' );
				$result = Connection::getClient()->request( '' );
				$this->success();
			} catch ( \Elastica\Exception\ExceptionInterface $e ) {
				wfProfileOut( __METHOD__ );
				return $this->failure( $e );
			}
			$result = $result->getData();
			$result = $result[ 'version' ][ 'number' ];
			$wgMemc->set( $mcKey, $result, 3600 * 12 );
		}
		wfProfileOut( __METHOD__ );
		return Status::newGood( $result );
	}

	/**
	 * Powers full-text-like searches including prefix search.
	 * @return Status(ResultSet|null|array(String)) results, no results, or title results
	 */
	private function search( $type, $for ) {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchMoreAccurateScoringMode;

		if ( $this->resultsType === null ) {
			$this->resultsType = new FullTextResultsType();
		}
		// Default null queries now so the rest of the method can assume it is not null.
		if ( $this->query === null ) {
			$this->query = new \Elastica\Query\MatchAll();
		}

		$query = new Elastica\Query();
		$query->setFields( $this->resultsType->getFields() );

		$extraIndexes = array();
		if ( $this->namespaces ) {
			if ( count( $this->namespaces ) < count( MWNamespace::getValidNamespaces() ) ) {
				$this->filters[] = new \Elastica\Filter\Terms( 'namespace', $this->namespaces );
			}
			$extraIndexes = $this->getAndFilterExtraIndexes();
		}

		// Wrap $this->query in a filtered query if there are filters.
		$filterCount = count( $this->filters );
		$notFilterCount = count( $this->notFilters );
		if ( $filterCount > 0 || $notFilterCount > 0 ) {
			if ( $filterCount > 1 || $notFilterCount > 0 ) {
				$filter = new \Elastica\Filter\Bool();
				foreach ( $this->filters as $must ) {
					$filter->addMust( $must );
				}
				foreach ( $this->notFilters as $mustNot ) {
					$filter->addMustNot( $mustNot );
				}
			} else {
				$filter = $this->filters[ 0 ];
			}
			$this->query = new \Elastica\Query\Filtered( $this->query, $filter );
		}

		$query->setQuery( self::boostQuery( $this->query ) );

		$highlight = $this->resultsType->getHighlightingConfiguration();
		if ( $highlight ) {
			// Fuzzy queries work _terribly_ with the plain highlighter so just drop any field that is forcing
			// the plain highlighter all together.  Do this here because this works so badly that no
			// ResultsType should be able to use the plain highlighter for these queries.
			if ( $this->fuzzyQuery ) {
				$highlight[ 'fields' ] = array_filter( $highlight[ 'fields' ], function( $field ) {
					return $field[ 'type' ] !== 'plain';
				});
			}
			$query->setHighlight( $highlight );
		}
		if ( $this->suggest ) {
			$query->setParam( 'suggest', $this->suggest );
			$query->addParam( 'stats', 'suggest' );
		}
		if( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if( $this->limit ) {
			$query->setSize( $this->limit );
		}
		if ( $this->rescore ) {
			// Wrap the rescore query in the boostQuery just as we wrap the regular query.
			$this->rescore[ 'query' ][ 'rescore_query' ] =
				self::boostQuery( $this->rescore[ 'query' ][ 'rescore_query' ] )->toArray();
			$query->setParam( 'rescore', $this->rescore );
		}
		$query->addParam( 'stats', $type );
		switch ( $this->sort ) {
		case 'relevance':
			break;  // The default
		case 'title_asc':
			$query->setSort( array( 'title.keyword' => 'asc' ) );
			break;
		case 'title_desc':
			$query->setSort( array( 'title.keyword' => 'desc' ) );
			break;
		default:
			wfLogWarning( "Invalid sort type:  $this->sort" );
		}

		$queryOptions = array();
		if ( $wgCirrusSearchMoreAccurateScoringMode ) {
			$queryOptions[ 'search_type' ] = 'dfs_query_then_fetch';
		}

		// Setup the search
		$search = Connection::getPageType( $this->indexBaseName, $this->pickIndexTypeFromNamespaces() )
			->createSearch( $query, $queryOptions );
		foreach ( $extraIndexes as $i ) {
			$search->addIndex( $i );
		}

		$description = "$type search for '$for'";

		// Perform the search
		$searcher = $this;
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $searcher, $search, $description ) {
				try {
					$searcher->start( $description );
					return $searcher->success( $search->search() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			},
			'error' => function( $status ) {
				$status = $status->getErrorsArray();
				wfLogWarning( 'Pool error searching Elasticsearch:  ' . $status[ 0 ][ 0 ] );
				return Status::newFatal( 'cirrussearch-backend-error' );
			}
		) );
		$result = $work->execute();
		if ( $result->isOK() ) {
			$result->setResult( true, $this->resultsType->transformElasticsearchResult( $this->suggestPrefixes,
				$this->suggestSuffixes, $result->getValue(), $this->searchContainedSyntax ) );
		}
		wfProfileOut( __METHOD__ );
		return $result;
	}

	private function buildSearchTextQuery( $fields, $query ) {
		global $wgCirrusSearchPhraseSlop;
		$query = new \Elastica\Query\QueryString( $query );
		$query->setFields( $fields );
		$query->setAutoGeneratePhraseQueries( true );
		$query->setPhraseSlop( $wgCirrusSearchPhraseSlop );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( false );
		$query->setFuzzyPrefixLength( 2 );
		return $query;
	}

	/**
	 * Build suggest config for $field.
	 * @var $field string field to suggest against
	 * @return array of Elastica configuration
	 */
	private function buildSuggestConfig( $field ) {
		global $wgCirrusSearchPhraseSuggestMaxErrors;
		global $wgCirrusSearchPhraseSuggestConfidence;
		return array(
			'phrase' => array(
				'field' => $field,
				'size' => 1,
				'max_errors' => $wgCirrusSearchPhraseSuggestMaxErrors,
				'confidence' => $wgCirrusSearchPhraseSuggestConfidence,
				'direct_generator' => array(
					array(
						'field' => $field,
						'suggest_mode' => 'always', // Forces us to generate lots of phrases to try.
					),
				),
				'highlight' => array(
					'pre_tag' => self::SUGGESTION_HIGHLIGHT_PRE,
					'post_tag' => self::SUGGESTION_HIGHLIGHT_POST,
				),
			),
		);
	}

	public function switchSearchToExact( $term ) {
		$exact = join( ' OR ', $this->buildFullTextSearchFields( 1, ".plain:$term" ) );
		return "($exact)";
	}

	/**
	 * Build fields searched by full text search.
	 * @param float $weight weight to multiply by all fields
	 * @param string $fieldSuffix suffux to add to field names
	 * @return array(string) of fields to query
	 */
	public function buildFullTextSearchFields( $weight, $fieldSuffix ) {
		global $wgCirrusSearchWeights;
		$titleWeight = $weight * $wgCirrusSearchWeights[ 'title' ];
		$headingWeight = $weight * $wgCirrusSearchWeights[ 'heading' ];
		$fileTextWeight = $weight * $wgCirrusSearchWeights[ 'file_text' ];
		$fields = array(
			"title${fieldSuffix}^${titleWeight}",
			"heading${fieldSuffix}^${headingWeight}",
			"text${fieldSuffix}^${weight}",
			"file_text${fieldSuffix}^${fileTextWeight}",
		);
		if ( $this->showRedirects ) {
			$redirectWeight = $weight * $wgCirrusSearchWeights[ 'redirect' ];
			$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
		}
		return $fields;
	}

	/**
	 * Pick the index type to search based on the list of namespaces to search.
	 * @return string|false either an index type or false to use all index types
	 */
	private function pickIndexTypeFromNamespaces() {
		if ( !$this->namespaces ) {
			return false; // False selects all index types
		}

		$indexTypes = array();
		foreach ( $this->namespaces as $namespace ) {
			$indexTypes[] =
				Connection::getIndexSuffixForNamespace( $namespace );
		}
		$indexTypes = array_unique( $indexTypes );
		return count( $indexTypes ) > 1 ? false : $indexTypes[0];
	}

	/**
	 * Retrieve the extra indexes for our searchable namespaces, if any
	 * exist. If they do exist, also add our wiki to our notFilters so
	 * we can filter out duplicates properly.
	 *
	 * @return array(string)
	 */
	private function getAndFilterExtraIndexes() {
		$extraIndexes = OtherIndexes::getExtraIndexesForNamespaces( $this->namespaces );
		if ( $extraIndexes ) {
			$this->notFilters[] = new \Elastica\Filter\Term(
				array( 'local_sites_with_dupe' => wfWikiId() ) );
		}
		return $extraIndexes;
	}

	private function buildPrefixFilter( $search ) {
		$match = new \Elastica\Query\Match();
		$match->setField( 'title.prefix', $search );
		return new \Elastica\Filter\Query( $match );
	}

	/**
	 * Make sure the the query string part is well formed by escaping some syntax that we don't
	 * want users to get direct access to and making sure quotes are balanced.
	 * These special characters _aren't_ escaped:
	 * *: Do a prefix or postfix search against the stemmed text which isn't strictly a good
	 * idea but this is so rarely used that adding extra code to flip prefix searches into
	 * real prefix searches isn't really worth it.  The same goes for postfix searches but
	 * doubly because we don't have a postfix index (backwards ngram.)
	 * ~: Do a fuzzy match against the stemmed text which isn't strictly a good idea but it
	 * gets the job done and fuzzy matches are a really rarely used feature to be creating an
	 * extra index for.
	 * ": Perform a phrase search for the quoted term.  If the "s aren't balanced we insert one
	 * at the end of the term to make sure elasticsearch doesn't barf at us.
	 * +/-/!/||/&&: Symbols meaning AND, NOT, NOT, OR, and AND respectively.  - was supported by
	 * LuceneSearch so we need to allow that one but there is no reason not to allow them all.
	 */
	public function fixupQueryStringPart( $string ) {
		wfProfileIn( __METHOD__ );
		// Escape characters that can be escaped with \\
		$string = preg_replace( '/(
				\/|		(?# no regex searches allowed)
				\(|     (?# no user supplied groupings)
				\)|
				\{|     (?# no exclusive range queries)
				}|
				\[|     (?# no inclusive range queries either)
				]|
				\^|     (?# no user supplied boosts at this point, though I cant think why)
				:|		(?# no specifying your own fields)
				\\\
			)/x', '\\\$1', $string );

		// If the string doesn't have balanced quotes then add a quote on the end so Elasticsearch
		// can parse it.
		$inQuote = false;
		$inEscape = false;
		$len = strlen( $string );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $inEscape ) {
				$inEscape = false;
				continue;
			}
			switch ( $string[ $i ] ) {
			case '"':
				$inQuote = !$inQuote;
				break;
			case '\\':
				$inEscape = true;
			}
		}
		if ( $inQuote ) {
			$string = $string . '"';
		}
		wfProfileOut( __METHOD__ );
		return $string;
	}

	/**
	 * Make sure that all operators and lucene syntax is used correctly in the query string
	 * and store if this is a fuzzy query.
	 * If it isn't then the syntax escaped so it becomes part of the query text.
	 */
	public function fixupWholeQueryString( $string ) {
		wfProfileIn( __METHOD__ );
		// Be careful when editing this method because the ordering of the replacements matters.


		// Escape ~ that don't follow a term or a quote
		$string = preg_replace_callback( '/(?<![\w"])~/',
			'CirrusSearch\Searcher::escapeBadSyntax', $string );

		// Escape ? and * that don't follow a term.  These are slow so we turned them off.
		$string = preg_replace_callback( '/(?<![\w])[?*]/',
			'CirrusSearch\Searcher::escapeBadSyntax', $string );

		// Reduce token ranges to bare tokens without the < or >
		$string = preg_replace( '/(?:<|>)([^\s])/', '$1', $string );

		// Turn bad fuzzy searches into searches that contain a ~ and set $this->fuzzyQuery for good ones.
		$searcher = $this;
		$fuzzyQuery = $this->fuzzyQuery;
		$string = preg_replace_callback( '/(?<leading>\w)~(?<trailing>\S*)/',
			function ( $matches ) use ( $searcher, &$fuzzyQuery ) {
				if ( preg_match( '/^(?:|0|(?:0?\.[0-9]+)|(?:1(?:\.0)?))$/', $matches[ 'trailing' ] ) ) {
					$fuzzyQuery = true;
					return $matches[ 0 ];
				} else {
					return $matches[ 'leading' ] . '\\~' .
						preg_replace( '/(?<!\\\\)~/', '\~', $matches[ 'trailing' ] );
				}
			}, $string );
		$this->fuzzyQuery = $fuzzyQuery;

		// Turn bad proximity searches into searches that contain a ~
		$string = preg_replace_callback( '/"~(?<trailing>\S*)/', function ( $matches ) {
			if ( preg_match( '/[0-9]+/', $matches[ 'trailing' ] ) ) {
				return $matches[ 0 ];
			} else {
				return '"\\~' . $matches[ 'trailing' ];
			}
		}, $string );

		// Escape +, -, and ! when not followed immediately by a term.
		$string = preg_replace_callback( '/[+\-!]+(?!\w)/',
			'CirrusSearch\Searcher::escapeBadSyntax', $string );

		// Escape || when not between terms
		$string = preg_replace_callback( '/^\s*\|\|/',
			'CirrusSearch\Searcher::escapeBadSyntax', $string );
		$string = preg_replace_callback( '/\|\|\s*$/',
			'CirrusSearch\Searcher::escapeBadSyntax', $string );

		// Lowercase AND and OR when not surrounded on both sides by a term.
		// Lowercase NOT when it doesn't have a term after it.
		$string = preg_replace_callback( '/^\s*(?:AND|OR)/',
			'CirrusSearch\Searcher::lowercaseMatched', $string );
		$string = preg_replace_callback( '/(?:AND|OR|NOT)\s*$/',
			'CirrusSearch\Searcher::lowercaseMatched', $string );
		wfProfileOut( __METHOD__ );
		return $string;
	}

	/**
	 * Does $string contain unescaped query string syntax?  Note that we're not
	 * careful about if the syntax is escaped - that still count.
	 * @param $string string query string to check
	 * @return boolean does it contain special syntax?
	 */
	private function queryStringContainsSyntax( $string ) {
		// Matches the upper case syntax and character syntax
		return preg_match( '/[?*+~"!|-]|AND|OR|NOT/', $string );
	}

	private static function escapeBadSyntax( $matches ) {
		return "\\" . implode( "\\", str_split( $matches[ 0 ] ) );
	}

	private static function lowercaseMatched( $matches ) {
		return strtolower( $matches[ 0 ] );
	}

	/**
	 * Wrap query in a CustomScore query if its score need to be modified.
	 * @param $query Elastica\Query query to boost.
	 * @return query that will run $query and boost results based on links
	 */
	private function boostQuery( $query ) {
		$fuctionScore = new \Elastica\Query\FunctionScore();
		$fuctionScore->setQuery( $query );
		$useFunctionScore = false;

		// Customize score by boosting based on incoming links count
		if ( $this->boostLinks ) {
			$incomingLinks = "(doc['incoming_links'].isEmpty() ? 0 : doc['incoming_links'].value)";
			// TODO remove redirect links once they are empty and switch prefix search to some kind of sort
			$incomingRedirectLinks = "(doc['incoming_redirect_links'].isEmpty() ? 0 : doc['incoming_redirect_links'].value)";
			$scoreBoostMvel = "$incomingLinks + $incomingRedirectLinks";
			switch ( $this->boostLinks ) {
			case 'linear':
				break;  // scoreBoostMvel already correct
			case 'log':
				$scoreBoostMvel = "log10($scoreBoostMvel + 2)";
				break;
			default:
				wfLogWarning( "Invalid links boost type:  $this->boostLinks" );
			}
			$fuctionScore->addScriptScoreFunction( new \Elastica\Script( $scoreBoostMvel ) );
			$useFunctionScore = true;
		}

		// Customize score by decaying a portion by time since last update
		if ( $this->preferRecentDecayPortion > 0 && $this->preferRecentHalfLife > 0 ) {
			// Convert half life for time in days to decay constant for time in milliseconds.
			$decayConstant = log( 2 ) / $this->preferRecentHalfLife / 86400000;
			// e^ct - 1 where t is last modified time - now which is negative
			$exponentialDecayMvel = "Math.expm1($decayConstant * (doc['timestamp'].value - time()))";
			// p(e^ct - 1)
			if ( $this->preferRecentDecayPortion !== 1.0 ) {
				$exponentialDecayMvel = "$exponentialDecayMvel * $this->preferRecentDecayPortion";
			}
			// p(e^ct - 1) + 1 which is easier to calculate than, but reduces to 1 - p + pe^ct
			// Which breaks the score into an unscaled portion (1 - p) and a scaled portion (p)
			$lastUpdateDecayMvel = "$exponentialDecayMvel + 1";
			$fuctionScore->addScriptScoreFunction( new \Elastica\Script( $lastUpdateDecayMvel ) );
			$useFunctionScore = true;
		}

		if ( $this->boostTemplates ) {
			foreach ( $this->boostTemplates as $name => $boost ) {
				$match = new \Elastica\Query\Match();
				$match->setFieldQuery( 'template', $name );
				// TODO replace with a boost_factor function when that is supported by elastica
				$fuctionScore->addScriptScoreFunction( new \Elastica\Script( 'boost', array( 'boost' => $boost ) ),
					new \Elastica\Filter\Query( $match ) );
			}
			$useFunctionScore = true;
		}

		if ( $useFunctionScore ) {
			return $fuctionScore;
		}
		return $query;
	}

	private static function getDefaultBoostTemplates() {
		static $defaultBoostTemplates = null;
		if ( $defaultBoostTemplates === null ) {
			$source = wfMessage( 'cirrussearch-boost-templates' )->inContentLanguage();
			if( $source->isDisabled() ) {
				$defaultBoostTemplates = array();
			} else {
				$lines = explode( "\n", $source->plain() );
				$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
				$lines = array_map( 'trim', $lines );          // Remove extra spaces
				$lines = array_filter( $lines );               // Remove empty lines
				$defaultBoostTemplates = self::parseBoostTemplates(
					implode( ' ', $lines ) );                  // Now parse the templates
			}
		}
		return $defaultBoostTemplates;
	}

	/**
	 * Parse boosted templates.  Parse failures silently return no boosted templates.
	 * @param string $text text representation of boosted templates
	 * @return array of boosted templates.
	 */
	public static function parseBoostTemplates( $text ) {
		$boostTemplates = array();
		$templateMatches = array();
		if ( preg_match_all( '/([^|]+)\|([0-9]+)% ?/', $text, $templateMatches, PREG_SET_ORDER ) ) {
			foreach ( $templateMatches as $templateMatch ) {
				$boostTemplates[ $templateMatch[ 1 ] ] = floatval( $templateMatch[ 2 ] ) / 100;
			}
		}
		return $boostTemplates;
	}

	private function checkTitleSearchRequestLength( $search ) {
		$requestLength = strlen( $search );
		if ( $requestLength > self::MAX_TITLE_SEARCH ) {
			throw new UsageException( 'Prefix search request was longer longer than the maximum allowed length.' .
				" ($requestLength > " . self::MAX_TITLE_SEARCH . ')', 'request_too_long', 400 );
		}
	}
}
