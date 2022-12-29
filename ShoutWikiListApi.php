<?php

use MediaWiki\MediaWikiServices;

/**
 * ShoutWikiListApi
 * List all available ShoutWiki wikis, mainly for s23.org stats.
 *
 * @file
 * @ingroup API
 * @author Jack Phoenix
 * @license https://en.wikipedia.org/wiki/Public_domain Public domain
 * @link https://www.mediawiki.org/wiki/Extension:ShoutWiki_API Documentation
 */
class ShoutWikiListApi extends ApiQueryBase {

	/**
	 * Constructor
	 *
	 * @param ApiQuery $query
	 * @param string $moduleName
	 */
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'sw' );
	}

	/**
	 * Main function
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		// E_STRICT bitching about undefined indexes
		$wikiId = ( !empty( $params['wid'] ) ? $params['wid'] : 0 );
		$to = ( !empty( $params['to'] ) ? $params['to'] : 0 );
		$from = ( !empty( $params['from'] ) ? $params['from'] : 0 );
		$lang = ( !empty( $params['lang'] ) ? $params['lang'] : '' );
		$countOnly = ( !empty( $params['countonly'] ) ? $params['countonly'] : false );
		$limit = ( !empty( $params['limit'] ) ? $params['limit'] : 0 );
		$dir = ( !empty( $params['dir'] ) ? $params['dir'] : 'older' );
		$start = ( !empty( $params['start'] ) ? $params['start'] : 0 );
		$end = ( !empty( $params['end'] ) ? $params['end'] : 0 );

		/**
		 * database instance
		 */
		$db = $this->getDB();

		// Only active (=not deleted) wikis are displayed by default since
		// 22 July 2013
		$activeOnly = true;
		if ( isset( $params['deleted'] ) ) {
			$activeOnly = false;
		}

		/**
		 * query builder
		 */
		$this->addTables( [ 'wiki_list' ] );

		if ( !empty( $start ) || !empty( $end ) ) {
			$this->addTimestampWhereRange( 'wl_timestamp', $dir, $start, $end );
		}

		if ( $countOnly ) {
			$this->addOption( 'LIMIT', $limit + 1 );
		}

		if ( $activeOnly ) {
			$this->addWhereFld( 'wl_deleted', 0 );
		}
		if ( !empty( $wikiId ) ) {
			$this->addWhereFld( 'wl_id', $wikiId );
		}

		if ( empty( $wikiId ) ) {
			if ( !empty( $to ) ) {
				if ( $to && is_int( $to ) && $to > 0 ) {
					$this->addWhere( 'wl_id <= ' . intval( $to ) );
				}
			}

			if ( !empty( $from ) ) {
				if ( $from && is_int( $from ) && $from > 0 ) {
					$this->addWhere( 'wl_id >= ' . intval( $from ) );
				}
			}
		}

		if ( !empty( $lang ) ) {
			if ( !array_key_exists( $lang,
				MediaWikiServices::getInstance()->getLanguageNameUtils()->getLanguageNames() )
			) {
				$this->dieWithError( new RawMessage( 'No such language' ), 'nosuchlang' );
			}

			$this->addTables( 'wiki_settings' );
			$this->addWhere( [
				'ws_wiki = wl_id',
				'ws_setting' => 'wgLanguageCode',
				'ws_value' => $lang
			] );
		}

		if ( $countOnly ) {
			// Query builder
			$this->addFields( [ 'COUNT(*) AS cnt' ] );
			$data = [];
			$res = $this->select( __METHOD__ );
			$row = $res->fetchObject();

			if ( $row ) {
				$data['count'] = $row->cnt;
				ApiResult::setContentValue( $data, 'content', $row->cnt );
			}

			$this->getResult()->setIndexedTagName( $data, 'wiki' );
			$this->getResult()->addValue( 'query', $this->getModuleName(), $data );
		} else {
			$this->addFields( [ 'wl_id', 'wl_timestamp' ] );
			$this->addOption( 'ORDER BY ', 'wl_id' );

			// result builder
			$data = [];
			$res = $this->select( __METHOD__ );
			$result = $this->getResult();

			$user = $this->getUser();
			$effectiveGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserEffectiveGroups( $user );
			$userIsStaff = in_array( 'staff', $effectiveGroups );

			$count = 0;
			foreach ( $res as $row ) {
				$wid = $row->wl_id;
				$wikiType = ShoutWiki::getType( $wid );

				// Do not show private wikis to non-staff users
				// This is so that anons using the API will get that 100 results
				// per query instead of 83 or something (i.e 100 - amount of
				// private wikis)
				if ( $wikiType == 'private' && !$userIsStaff ) {
					continue;
				}

				// support for the query-continue parameter, mostly c+p'd from
				// core /includes/api/ApiQueryLogEvents.php
				if ( ++$count > $limit ) {
					// We've reached the one extra which shows that there are additional pages to be had.
					// Stop here...
					$this->setContinueEnumParameter( 'start', wfTimestamp( TS_ISO_8601, $row->wl_timestamp ) );
					break;
				}
				// end query-continue stuff

				$data[$wid] = [
					'id' => $wid,
					'lang' => ShoutWiki::getLanguageCode( $wid ),
					'url' => 'http://' . ShoutWiki::getFullSubdomain( $wid ) . '.shoutwiki.com/',
					'sitename' => ShoutWiki::getSitename( $wid ),
					'description' => ShoutWiki::getDescription( $wid ),
					'category' => ShoutWiki::getCategory( $wid ),
					'creationtimestamp' => $row->wl_timestamp
				];
				// Show wiki type to staff so that they can identify
				// private wikis easily
				if ( $userIsStaff ) {
					$data[$wid]['type'] = $wikiType;
				}
				ApiResult::setContentValue( $data[$wid], 'content', '' );

				// query-continue parameter support
				$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $data[$wid] );
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'start', wfTimestamp( TS_ISO_8601, $row->wl_timestamp ) );
					break;
				}
				// end query-continue stuff
			}

			$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'wiki' );
		}
	}

	/**
	 * @see ApiQueryBase::getAllowedParams()
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'wid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'deleted' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MAX => 1,
				ApiBase::PARAM_MIN => 0,
			],
			'from' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			],
			'to' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			],
			'countonly' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			],
			'lang' => null,
			'limit' => [
				ApiBase::PARAM_DFLT => 100,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'start' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'end' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'dir' => [
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_TYPE => [
					'newer',
					'older'
				],
				ApiBase::PARAM_HELP_MSG => 'api-help-param-direction'
			],
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&list=listwikis'
				=> 'apihelp-query+listwikis-example-1',
			'action=query&list=listwikis&swdeleted=1'
				=> 'apihelp-query+listwikis-example-2',
			'action=query&list=listwikis&swwid=177'
				=> 'apihelp-query+listwikis-example-3',
			'action=query&list=listwikis&swfrom=100&swto=150'
				=> 'apihelp-query+listwikis-example-4',
			'action=query&list=listwikis&swfrom=10&swto=50&swlang=fi'
				=> 'apihelp-query+listwikis-example-5',
			'action=query&list=listwikis&swcountonly=1'
				=> 'apihelp-query+listwikis-example-6',
			'action=query&list=listwikis&swactive=1&swcountonly=1'
				=> 'apihelp-query+listwikis-example-7',
		];
	}
}
