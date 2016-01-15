<?php
/**
 * ShoutWiki-specific API modules
 *
 * @file
 * @ingroup Extensions
 * @version 0.5
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @license https://en.wikipedia.org/wiki/Public_domain Public domain
 * @link https://www.mediawiki.org/wiki/Extension:ShoutWiki_API Documentation
 * @see https://bugzilla.shoutwiki.com/show_bug.cgi?id=193
 */

// Extension credits that will show up on Special:Version
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'ShoutWiki API',
	'version' => '0.5',
	'author' => 'Jack Phoenix',
	'descriptionmsg' => 'shoutwikiapi-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:ShoutWiki_API'
);

$wgMessagesDirs['ShoutWikiAPI'] = __DIR__ . '/i18n';

// Autoload classes and register them as API modules
$wgAutoloadClasses['ShoutWikiListApi'] = __DIR__ . '/ShoutWikiListApi.php';
$wgAPIListModules['listwikis'] = 'ShoutWikiListApi';
