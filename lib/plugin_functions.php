<?php
/**
 * Has the current user has dugg the plugin project
 * @param $project
 * @return bool
 */
function plugins_is_dugg($project) {
	if (check_entity_relationship(get_loggedin_userid(), "has_dugg", $project->guid)) {
		return TRUE;
	} else {
		return FALSE;
	}
}

/**
 * Get the mimetype of the plugin archive
 * 
 * @param string $name
 * @return string/bool
 */
function plugins_get_mimetype($name) {
	// We're only allowing archives.
	if (!substr_count($_FILES[$name]['name'],'.tar.gz') &&
		!substr_count($_FILES[$name]['name'],'.tgz') &&
		!substr_count($_FILES[$name]['name'],'.zip')) {

		return FALSE;
	}

	if (substr_count($_FILES[$name]['name'],'.tar.gz') ||
		substr_count($_FILES[$name]['name'],'.tgz')) {

		return 'application/x-gzip';
	} else {
		return 'application/zip';
	}
}

/**
 * Strip img and link tags
 *
 * @param string $string
 * @return string
 */
function plugins_strip_tags($string) {
	return strip_tags($string, '<p><strong><em><span><ul><li><ol><blockquote>');
}

/**
 * Get the download trends
 *
 * @param int $guid Plugin project guid or 0 for all plugins
 * @param int $days Number of days starting from today or 0 for dawn of time
 * @return array
 */
function plugins_get_downloads_histogram($guid = 0, $days = 30) {
	$start_date = time() - $days * 3600 * 24;
	if ($days == 0) {
		$start_date = 0;
	}

	$downloads = get_annotations($guid, 'object', 'plugin_project', 'download', '', 0, 9999999, 0, 'asc', $start_date);

	// if queried for all downloads, need to set epoch based on first download
	$first_time = $downloads[0]->time_created;
	$num_actual_days = (int)(time() - $first_time) / (3600 * 24) + 1;
	if ($start_date == 0) {
		$start_date = $first_time;
		$days = max($days, $num_actual_days);
	}

	// compute histogram of downloads
	$histogram = array_fill(0, $days, 0);
	foreach ($downloads as $download) {
		$day = (int)floor(($download->time_created - $start_date) / (3600 * 24));
		$histogram[$day]++;
	}

	return $histogram;
}

/**
 * Plugin project search hook
 * 
 * @param string $hook
 * @param string $type
 * @param <type> $value
 * @param <type> $params
 * @return array
 */
function plugins_search_hook($hook, $type, $value, $params) {
	global $CONFIG;
	$query = sanitise_string($params['query']);

	$join = "JOIN {$CONFIG->dbprefix}objects_entity oe ON e.guid = oe.guid";
	$params['joins'] = array($join);
	$params['joins'][] = "JOIN {$CONFIG->dbprefix}metadata summary_md on e.guid = summary_md.entity_guid";
	$params['joins'][] = "JOIN {$CONFIG->dbprefix}metastrings summary_msn on summary_md.name_id = summary_msn.id";
	$params['joins'][] = "JOIN {$CONFIG->dbprefix}metastrings summary_msv on summary_md.value_id = summary_msv.id";

	$fields = array('title', 'description');
	$where = search_get_where_sql('oe', $fields, $params);

	// cheat and use LIKE for the summary field
	// this is kinda dirty.
	$likes = array();
	$query_arr = explode(' ', $query);
	foreach ($query_arr as $word) {
		$likes[] = "summary_msv.string LIKE \"%$word%\"";
	}
	$like_str = implode(' OR ', $likes);

	//$params['wheres'] = array("($where OR ($like_str))");
	$params['wheres'] = array($where);

//	If metastrings were fulltext'd we could do this :(
//	$select = "summary_msv.string summary_string";
//	$params['selects'] = array($select);
//
//	$fields = array('string');
//	$summary_where = search_get_where_sql('summary_msv', $fields, $params);
//	$params['wheres'][] = $summary_where;

	if (($category = get_input('category')) && ($category != 'all')) {
		$params['metadata_name_value_pair'] = array ('name' => 'plugincat', 'value' => $category, 'case_sensitive' => FALSE);
	}
	$params['order_by'] = search_get_order_by_sql('e', 'oe', $params['sort'], $params['order']);


	$entities = elgg_get_entities_from_metadata($params);
	$params['count'] = TRUE;
	$count = elgg_get_entities_from_metadata($params);

	// no need to continue if nothing here.
	if (!$count) {
		return array('entities' => array(), 'count' => $count);
	}

	// add the volatile data for why these entities have been returned.
	foreach ($entities as $entity) {
		$title = search_get_highlighted_relevant_substrings($entity->title, $params['query']);
		$entity->setVolatileData('search_matched_title', $title);

		$desc = search_get_highlighted_relevant_substrings($entity->summary, $params['query']);
		$entity->setVolatileData('search_matched_description', $desc);
	}

	return array(
		'entities' => $entities,
		'count' => $count,
	);
}

// need our own notification code because core Elgg code uses container for matches
function plugins_send_notifications($entity) {
	global $CONFIG, $NOTIFICATION_HANDLERS;

	$owner = $entity->getOwnerEntity();

	// Get users interested in content from this person and notify them
	foreach ($NOTIFICATION_HANDLERS as $method => $foo) {
		$interested_users = elgg_get_entities_from_relationship(array(
			'relationship' => 'notify' . $method,
			'relationship_guid' => $owner->guid,
			'inverse_relationship' => TRUE,
			'types' => 'user',
			'limit' => 99999
		));

		if ($interested_users && is_array($interested_users)) {
			foreach ($interested_users as $user) {
				if ($user instanceof ElggUser && !$user->isBanned()) {
					if (($user->guid != get_loggedin_userid()) && has_access_to_entity($entity, $user)) {

						$subtype = $entity->getSubtype();
						if ($subtype == 'plugin_project') {
							$text = $entity->description;
						} else {
							$text = $entity->release_notes;
						}

						$subject = sprintf(elgg_echo("plugins:$subtype:notify:subject"), $owner->name, $entity->title);
						$body = sprintf(elgg_echo("plugins:$subtype:notify:body"), $owner->name, $entity->title, $text, $entity->getURL());

						notify_user(
							$user->guid,
							$entity->owner_guid,
							$subject,
							$body,
							NULL,
							array($method)
						);
					}
				}
			}
		}
	}
}


/**
 * Return the count of all downloads
 * Adds 1.2M to the figure to account for downloads before this system as implemented.
 * 
 * @return int
 */
function plugins_get_all_download_count() {
	// see revision [7001] if interested in cached calculations on downloads
	$c = count_annotations(0, 'object', 'plugin_project', 'download', '', NULL);
	return $c + 1200000;
}
