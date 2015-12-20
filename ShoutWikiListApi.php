<?php
/**
 * ShoutWikiListApi
 * List all available ShoutWiki wikis, mainly for s23.org stats.
 *
 * @file
 * @ingroup API
 */
class ShoutWikiListApi extends ApiQueryBase {

	/**
	 * Constructor
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
		if( isset( $params['deleted'] ) ) {
			$activeOnly = false;
		}

		/**
		 * query builder
		 */
		$this->addTables( array( 'wiki_list' ) );

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
			if ( !array_key_exists( $lang, Language::getLanguageNames() ) ) {
				$this->dieUsage( 'No such language', 'nosuchlang' );
			}

			$this->addTables( 'wiki_settings' );
			$this->addWhere( array(
				'ws_wiki = wl_id',
				'ws_setting' => 'wgLanguageCode',
				'ws_value' => $lang
			) );
		}

		if ( $countOnly ) {
			// Query builder
			$this->addFields( array( 'COUNT(*) AS cnt' ) );
			$data = array();
			$res = $this->select( __METHOD__ );
			$row = $db->fetchObject( $res );

			if ( $row ) {
				$data['count'] = $row->cnt;
				ApiResult::setContent( $data, $row->cnt );
			}

			$this->getResult()->setIndexedTagName( $data, 'wiki' );
			$this->getResult()->addValue( 'query', $this->getModuleName(), $data );
		} else {
			$this->addFields( array( 'wl_id', 'wl_timestamp' ) );
			$this->addOption( 'ORDER BY ', 'wl_id' );

			// result builder
			$data = array();
			$res = $this->select( __METHOD__ );
			$result = $this->getResult();

			$user = $this->getUser();
			$userIsStaff = in_array( 'staff', $user->getEffectiveGroups() );

			$count = 0;
			foreach ( $res as $row ) {
				$wid = $row->wl_id;
				$wikiType = self::getWikiType( $wid );

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
					// We've reached the one extra which shows that there are additional pages to be had. Stop here...
					$this->setContinueEnumParameter( 'start', wfTimestamp( TS_ISO_8601, $row->wl_timestamp ) );
					break;
				}
				// end query-continue stuff

				$data[$wid] = array(
					'id' => $wid,
					'lang' => self::getWikiLanguage( $wid ),
					'url' => 'http://' . self::getWikiURL( $wid ) . '.shoutwiki.com/',
					'sitename' => self::getWikiSitename( $wid ),
					'description' => self::getWikiDescription( $wid ),
					'category' => self::getWikiCategory( $wid ),
					'creationtimestamp' => $row->wl_timestamp
				);
				// Show wiki type to staff so that they can identify
				// private wikis easily
				if ( $userIsStaff ) {
					$data[$wid]['type'] = $wikiType;
				}
				ApiResult::setContent( $data[$wid], '' );

				// query-continue parameter support
				$fit = $result->addValue( array( 'query', $this->getModuleName() ), null, $data[$wid] );
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'start', wfTimestamp( TS_ISO_8601, $row->wl_timestamp ) );
					break;
				}
				// end query-continue stuff
			}

			$result->setIndexedTagName_internal( array( 'query', $this->getModuleName() ), 'wiki' );
		}
	}

	/**
	 * Fetch the wiki type for the wiki with ID number $wikiID
	 *
	 * @param int $wikiID Wiki ID number
	 * @return string Wiki type, either "public", "private" or "school"
	 */
	public static function getWikiType( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		$wikiType = $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgWikiType',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);

		return $wikiType;
	}

	/**
	 * Fetch the language code for the wiki with ID number $wikiID
	 *
	 * @param int $wikiID Wiki ID number
	 * @return string Language code, such as 'en', 'fr', 'de-formal', etc.
	 */
	public static function getWikiLanguage( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		$wikiLang = $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgLanguageCode',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);

		return $wikiLang;
	}

	/**
	 * Get the URL for a wiki by its ID number
	 *
	 * @param int $wikiID Wiki ID number
	 * @return string|bool Wiki URL on success, boolean false on failure
	 */
	public static function getWikiURL( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );
		$url = $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgWikiFullSubdomain',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);

		if ( !empty( $url ) ) {
			return $url;
		} else {
			return false;
		}
	}

	/**
	 * Get a wiki's sitename by its ID number
	 *
	 * @param int $wikiID Wiki ID number
	 * @return string|bool Wiki sitename on success, boolean false on failure
	 */
	public static function getWikiSitename( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );
		$sitename = $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgSitename',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);

		if ( !empty( $sitename ) ) {
			return $sitename;
		} else {
			return false;
		}
	}

	/**
	 * Fetch the wiki description for the wiki with ID number $wikiID
	 *
	 * @param int $wikiID Wiki ID number
	 * @return string The wiki description the founder supplied when creating the wiki
	 */
	public static function getWikiDescription( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		$wikiDesc = $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgWikiDescription',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);

		return $wikiDesc;
	}

	/**
	 * Fetch the wiki category for the wiki with ID number $wikiID
	 *
	 * @param int $wikiID Wiki ID number
	 * @return string One of the pre-defined wiki categories (Television, Music, etc.)
	 *                as chosen by the wiki's founder
	 */
	public static function getWikiCategory( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		$wikiCat = $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgWikiCategory',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);

		return $wikiCat;
	}

	/**
	 * @return string The description string for this module
	 */
	public function getDescription() {
		return 'List all available wikis on ShoutWiki';
	}

	public function getAllowedParams() {
		return array(
			'wid' => array(
				ApiBase::PARAM_TYPE => 'integer'
			),
			'deleted' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MAX => 1,
				ApiBase::PARAM_MIN => 0,
			),
			'from' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			),
			'to' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			),
			'countonly' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			),
			'lang' => null,
			'limit' => array(
				ApiBase::PARAM_DFLT => 100,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			),
			'start' => array(
				ApiBase::PARAM_TYPE => 'timestamp'
			),
			'end' => array(
				ApiBase::PARAM_TYPE => 'timestamp'
			),
			'dir' => array(
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_TYPE => array(
					'newer',
					'older'
				)
			),
		);
	}

	public function getParamDescription() {
		return array(
			'wid' => 'Unique, wiki-specific ID number; use this when you want to get info about a specific wiki and you know its ID number',
			'deleted' => 'Also list deleted wikis (optional)',
			'from' => 'Get all wikis with ID that is equal to or greater than this value',
			'to' => 'Get all wikis with ID that is equal to or lower than this value',
			'lang' => 'Wiki language code (i.e. en for English, fi for Finnish, etc.)',
			'countonly' => 'Return only the amount of wikis',
			'limit' => 'Get information about this many wikis at once',
			'dir' => $this->getDirectionDescription( $this->getModulePrefix() ),
			'start' => 'The timestamp to start enumerating from',
			'end' => 'The timestamp to end enumerating',
		);
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
			array( 'code' => 'nosuchlang', 'info' => 'No such language' ),
		) );
	}

	public function getExamples() {
		return array(
			'api.php?action=query&list=listwikis' => 'Get all active wikis',
			'api.php?action=query&list=listwikis&swdeleted=1' => 'Also include deleted wikis in the listing',
			'api.php?action=query&list=listwikis&swwid=177' => 'Get information about the wiki whose ID is #177',
			'api.php?action=query&list=listwikis&swfrom=100&swto=150' => 'Get all (active) wikis with IDs ranging from 100 to 150',
			'api.php?action=query&list=listwikis&swfrom=10&swto=50&swlang=fi' => 'Show all Finnish (fi) wikis whose IDs range between 10-50',
			'api.php?action=query&list=listwikis&swcountonly=1' => 'Show the total amount of active wikis',
			'api.php?action=query&list=listwikis&swactive=1&swcountonly=1' => 'Show the total amount of all wikis ever, including deleted ones',
		);
	}
}
