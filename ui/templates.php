<?php
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/hosts.inc.php';
require_once dirname(__FILE__).'/include/forms.inc.php';

$page['type'] = detect_page_type(PAGE_TYPE_HTML);
$page['title'] = _('Configuration of templates');
$page['file'] = 'templates.php';
$page['scripts'] = ['class.tagfilteritem.js'];

require_once dirname(__FILE__).'/include/page_header.php';

//		VAR						TYPE		OPTIONAL FLAGS					VALIDATION	EXCEPTION
$fields = [
	'groups' =>					[null,      O_OPT, P_ONLY_ARRAY,			NOT_EMPTY,	'isset({add}) || isset({update})'],
	'templates' =>				[T_ZBX_INT, O_OPT, P_ONLY_ARRAY,			DB_ID,	null],
	'templateid' =>				[T_ZBX_INT, O_OPT, P_SYS,					DB_ID,	'isset({form}) && {form} == "update"'],
	'template_name' =>			[T_ZBX_STR, O_OPT, null,					NOT_EMPTY,	'isset({add}) || isset({update})', _('Template name')],
	'visiblename' =>			[T_ZBX_STR, O_OPT, null,					null,	'isset({add}) || isset({update})'],
	'groupids' =>				[T_ZBX_INT, O_OPT, P_ONLY_ARRAY,			DB_ID,	null],
	'tags' =>					[T_ZBX_STR, O_OPT, P_ONLY_TD_ARRAY,			null,	null],
	'description' =>			[T_ZBX_STR, O_OPT, null,					null,	null],
	'macros' =>					[null,      O_OPT, P_SYS|P_ONLY_TD_ARRAY,	null,	null],
	'show_inherited_macros' =>	[T_ZBX_INT, O_OPT, null,					IN([0,1]),	null],
	'valuemaps' =>				[null,      O_OPT, P_ONLY_TD_ARRAY,			null,	null],
	// actions
	'action' =>					[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				IN('"template.export","template.massdelete","template.massdeleteclear"'),	null],
	'add' =>					[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				null,	null],
	'update' =>					[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				null,	null],
	'clone' =>					[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				null,	null],
	'full_clone' =>				[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				null,	null],
	'delete' =>					[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				null,	null],
	'delete_and_clear' =>		[T_ZBX_STR, O_OPT, P_SYS|P_ACT,				null,	null],
	'cancel' =>					[T_ZBX_STR, O_OPT, P_SYS,					null,	null],
	'form' =>					[T_ZBX_STR, O_OPT, P_SYS,					null,	null],
	'form_refresh' =>			[T_ZBX_INT, O_OPT, P_SYS,					null,	null],
	// filter
	'filter_set' =>				[T_ZBX_STR, O_OPT, P_SYS,					null,	null],
	'filter_rst' =>				[T_ZBX_STR, O_OPT, P_SYS,					null,	null],
	'filter_name' =>			[T_ZBX_STR, O_OPT, null,					null,	null],
	'filter_vendor_name' =>		[T_ZBX_STR, O_OPT, null,					null,	null],
	'filter_vendor_version' =>	[T_ZBX_STR, O_OPT, null,					null,	null],
	'filter_groups' =>			[T_ZBX_INT, O_OPT, P_ONLY_ARRAY,			DB_ID,	null],
	'filter_evaltype' =>		[T_ZBX_INT, O_OPT, null,					IN([TAG_EVAL_TYPE_AND_OR, TAG_EVAL_TYPE_OR]),	null],
	'filter_tags' =>			[T_ZBX_STR, O_OPT, P_ONLY_TD_ARRAY,			null,	null],
	// sort and sortorder
	'sort' =>					[T_ZBX_STR, O_OPT, P_SYS, 					IN('"name"'),	null],
	'sortorder' =>				[T_ZBX_STR, O_OPT, P_SYS,					IN('"'.ZBX_SORT_DOWN.'","'.ZBX_SORT_UP.'"'),	null]
];
check_fields($fields);

/*
 * Permissions
 */
if (getRequest('templateid')) {
	$templates = API::Template()->get([
		'output' => [],
		'templateids' => getRequest('templateid'),
		'editable' => true
	]);

	if (!$templates) {
		access_deny();
	}
}

$tags = getRequest('tags', []);
foreach ($tags as $key => $tag) {
	// remove empty new tag lines
	if ($tag['tag'] === '' && $tag['value'] === '') {
		unset($tags[$key]);
		continue;
	}

	// remove inherited tags
	if (array_key_exists('type', $tag) && !($tag['type'] & ZBX_PROPERTY_OWN)) {
		unset($tags[$key]);
	}
	else {
		unset($tags[$key]['type']);
	}
}

// Remove inherited macros data (actions: 'add', 'update' and 'form').
$macros = cleanInheritedMacros(getRequest('macros', []));

// Remove empty new macro lines.
$macros = array_filter($macros, function($macro) {
	$keys = array_flip(['hostmacroid', 'macro', 'value', 'description']);

	return (bool) array_filter(array_intersect_key($macro, $keys));
});

/*
 * Actions
 */
if (hasRequest('templateid') && (hasRequest('clone') || hasRequest('full_clone'))) {
	$_REQUEST['form'] = hasRequest('clone') ? 'clone' : 'full_clone';

	$groups = getRequest('groups', []);
	$groupids = [];

	// Remove inaccessible groups from request, but leave "new".
	foreach ($groups as $group) {
		if (!is_array($group)) {
			$groupids[] = $group;
		}
	}

	if ($groupids) {
		$groups_allowed = API::TemplateGroup()->get([
			'output' => [],
			'groupids' => $groupids,
			'editable' => true,
			'preservekeys' => true
		]);

		foreach ($groups as $idx => $group) {
			if (!is_array($group) && !array_key_exists($group, $groups_allowed)) {
				unset($groups[$idx]);
			}
		}

		$_REQUEST['groups'] = $groups;
	}

	if ($macros && in_array(ZBX_MACRO_TYPE_SECRET, array_column($macros, 'type'))) {
		// Reset macro type and value.
		$macros = array_map(function($value) {
			return ($value['type'] == ZBX_MACRO_TYPE_SECRET)
				? ['value' => '', 'type' => ZBX_MACRO_TYPE_TEXT] + $value
				: $value;
		}, $macros);

		warning(_('The cloned template contains user defined macros with type "Secret text". The value and type of these macros were reset.'));
	}

	$macros = array_map(function($macro) {
		return array_diff_key($macro, array_flip(['hostmacroid']));
	}, $macros);

	if (hasRequest('clone')) {
		unset($_REQUEST['templateid']);
	}
}
elseif (hasRequest('add') || hasRequest('update')) {
	try {
		DBstart();

		foreach ($macros as &$macro) {
			unset($macro['discovery_state']);
			unset($macro['allow_revert']);
		}
		unset($macro);

		$input_templateid = getRequest('templateid', 0);
		$cloneTemplateId = 0;

		if (getRequest('form') === 'full_clone') {
			$cloneTemplateId = $input_templateid;
			$input_templateid = 0;
		}

		if ($input_templateid == 0) {
			$messageSuccess = _('Template added');
			$messageFailed = _('Cannot add template');
		}
		else {
			$messageSuccess = _('Template updated');
			$messageFailed = _('Cannot update template');
		}

		// Add new group.
		$groups = getRequest('groups', []);
		$new_groups = [];

		foreach ($groups as $idx => $group) {
			if (is_array($group) && array_key_exists('new', $group)) {
				$new_groups[] = ['name' => $group['new']];
				unset($groups[$idx]);
			}
		}

		if ($new_groups) {
			$new_groupid = API::TemplateGroup()->create($new_groups);

			if (!$new_groupid) {
				throw new Exception();
			}

			$groups = array_merge($groups, $new_groupid['groupids']);
		}

		$template_name = getRequest('template_name', '');

		// create / update template
		$template = [
			'host' => $template_name,
			'name' => (getRequest('visiblename', '') === '') ? $template_name : getRequest('visiblename'),
			'description' => getRequest('description', ''),
			'groups' => zbx_toObject($groups, 'groupid'),
			'tags' => $tags,
			'macros' => $macros
		];

		if ($input_templateid == 0) {
			$result = API::Template()->create($template);

			if ($result) {
				$input_templateid = reset($result['templateids']);
			}
			else {
				throw new Exception();
			}
		}
		else {
			$template['templateid'] = $input_templateid;

			$result = API::Template()->update($template);

			if (!$result) {
				throw new Exception();
			}
		}

		$valuemaps = getRequest('valuemaps', []);
		$ins_valuemaps = [];
		$upd_valuemaps = [];
		$del_valuemapids = [];

		if (getRequest('form', '') === 'full_clone' || getRequest('form', '') === 'clone') {
			foreach ($valuemaps as &$valuemap) {
				unset($valuemap['valuemapid']);
			}
			unset($valuemap);
		}
		else if (hasRequest('update')) {
			$del_valuemapids = API::ValueMap()->get([
				'output' => [],
				'hostids' => $input_templateid,
				'preservekeys' => true
			]);
		}

		foreach ($valuemaps as $valuemap) {
			if (array_key_exists('valuemapid', $valuemap)) {
				$upd_valuemaps[] = $valuemap;
				unset($del_valuemapids[$valuemap['valuemapid']]);
			}
			else {
				$ins_valuemaps[] = $valuemap + ['hostid' => $input_templateid];
			}
		}

		if ($upd_valuemaps && !API::ValueMap()->update($upd_valuemaps)) {
			throw new Exception();
		}

		if ($ins_valuemaps && !API::ValueMap()->create($ins_valuemaps)) {
			throw new Exception();
		}

		if ($del_valuemapids && !API::ValueMap()->delete(array_keys($del_valuemapids))) {
			throw new Exception();
		}

		// full clone
		if ($cloneTemplateId != 0 && getRequest('form') === 'full_clone') {

			/*
			 * First copy web scenarios with web items, so that later regular items can use web item as their master
			 * item.
			 */
			if (!copyHttpTests($cloneTemplateId, $input_templateid)) {
				throw new Exception();
			}

			if (!copyItemsToHosts('templateids', [$cloneTemplateId], true, [$input_templateid])) {
				throw new Exception();
			}

			// copy triggers
			if (!copyTriggersToHosts([$input_templateid], $cloneTemplateId)) {
				throw new Exception();
			}

			// copy graphs
			$dbGraphs = API::Graph()->get([
				'output' => ['graphid'],
				'hostids' => $cloneTemplateId,
				'inherited' => false
			]);

			foreach ($dbGraphs as $dbGraph) {
				copyGraphToHost($dbGraph['graphid'], $input_templateid);
			}

			// copy discovery rules
			$dbDiscoveryRules = API::DiscoveryRule()->get([
				'output' => ['itemid'],
				'hostids' => $cloneTemplateId,
				'inherited' => false
			]);

			if ($dbDiscoveryRules) {
				if (!API::DiscoveryRule()->copy([
					'discoveryids' => zbx_objectValues($dbDiscoveryRules, 'itemid'),
					'hostids' => [$input_templateid]
				])) {
					$result = false;
				}

				if (!$result) {
					throw new Exception();
				}
			}

			// Copy template dashboards.
			$db_template_dashboards = API::TemplateDashboard()->get([
				'output' => API_OUTPUT_EXTEND,
				'templateids' => $cloneTemplateId,
				'selectPages' => API_OUTPUT_EXTEND,
				'preservekeys' => true
			]);

			if ($db_template_dashboards) {
				$db_template_dashboards = CDashboardHelper::prepareForClone($db_template_dashboards, $input_templateid);

				if (!API::TemplateDashboard()->create($db_template_dashboards)) {
					throw new Exception();
				}
			}
		}

		unset($_REQUEST['form'], $_REQUEST['templateid']);
		$result = DBend($result);

		if ($result) {
			uncheckTableRows();
		}
		show_messages($result, $messageSuccess, $messageFailed);
	}
	catch (Exception $e) {
		DBend(false);
		show_error_message($messageFailed);
	}
}
elseif (hasRequest('templateid') && hasRequest('delete')) {
	try {
		DBstart();

		$hosts = API::Host()->get([
			'output' => [],
			'templateids' => getRequest('templateid'),
			'preservekeys' => true
		]);

		if ($hosts) {
			$result = API::Host()->massRemove([
				'hostids' => array_keys($hosts),
				'templateids' => getRequest('templateid')
			]);

			if (!$result) {
				throw new Exception();
			}
		}

		$result = API::Template()->delete([getRequest('templateid')]);

		$result = DBend($result);
	}
	catch (Exception $e) {
		DBend(false);
	}

	if ($result) {
		unset($_REQUEST['form'], $_REQUEST['templateid']);
		uncheckTableRows();
	}

	unset($_REQUEST['delete']);
	show_messages($result, _('Template deleted'), _('Cannot delete template'));
}
elseif (hasRequest('templateid') && hasRequest('delete_and_clear')) {
	DBstart();

	$result = API::Template()->delete([getRequest('templateid')]);

	$result = DBend($result);

	if ($result) {
		unset($_REQUEST['form'], $_REQUEST['templateid']);
		uncheckTableRows();
	}
	unset($_REQUEST['delete']);
	show_messages($result, _('Template deleted'), _('Cannot delete template'));
}
elseif (hasRequest('templates') && hasRequest('action') && str_in_array(getRequest('action'), ['template.massdelete', 'template.massdeleteclear'])) {
	try {
		DBstart();

		$templateids = getRequest('templates');

		if (getRequest('action') === 'template.massdelete') {
			$hosts = API::Host()->get([
				'output' => [],
				'templateids' => $templateids,
				'preservekeys' => true
			]);

			if ($hosts) {
				$result = API::Host()->massRemove([
					'hostids' => array_keys($hosts),
					'templateids' => $templateids
				]);

				if (!$result) {
					throw new Exception();
				}
			}
		}

		$result = API::Template()->delete($templateids);

		$result = DBend($result);
	}
	catch (Exception $e) {
		DBend(false);
	}

	if ($result) {
		uncheckTableRows();
	}
	else {
		$templates = API::Template()->get([
			'output' => [],
			'templateids' => $templateids,
			'editable' => true,
			'preservekeys' => true
		]);

		uncheckTableRows(null, array_keys($templates));
	}

	show_messages($result, _('Template deleted'), _('Cannot delete template'));
}

/*
 * Display
 */
if (hasRequest('form')) {
	$data = [
		'form_refresh' => getRequest('form_refresh', 0),
		'form' => getRequest('form'),
		'templateid' => getRequest('templateid', 0),
		'tags' => $tags,
		'show_inherited_macros' => getRequest('show_inherited_macros', 0),
		'vendor' => [],
		'readonly' => false,
		'macros' => $macros,
		'valuemaps' => array_values(getRequest('valuemaps', []))
	];

	if ($data['templateid'] != 0) {
		$dbTemplates = API::Template()->get([
			'output' => API_OUTPUT_EXTEND,
			'selectTemplateGroups' => ['groupid'],
			'selectMacros' => API_OUTPUT_EXTEND,
			'selectTags' => ['tag', 'value'],
			'selectValueMaps' => ['valuemapid', 'name', 'mappings'],
			'templateids' => $data['templateid']
		]);
		$data['dbTemplate'] = reset($dbTemplates);

		if ($data['form'] !== 'full_clone') {
			$data['vendor'] = array_filter([
				'name' => $data['dbTemplate']['vendor_name'],
				'version' => $data['dbTemplate']['vendor_version']
			], 'strlen');
		}

		if (!hasRequest('form_refresh')) {
			$data['tags'] = $data['dbTemplate']['tags'];
			$data['macros'] = $data['dbTemplate']['macros'];

			foreach ($data['macros'] as &$macro) {
				if ($macro['type'] == ZBX_MACRO_TYPE_SECRET) {
					$macro['allow_revert'] = true;
				}
			}
			unset($macro);

			order_result($data['dbTemplate']['valuemaps'], 'name');
			$data['valuemaps'] = array_values($data['dbTemplate']['valuemaps']);
		}
	}

	// description
	$data['description'] = ($data['templateid'] != 0 && !hasRequest('form_refresh'))
		? $data['dbTemplate']['description']
		: getRequest('description', '');

	// tags
	if (!$data['tags']) {
		$data['tags'][] = ['tag' => '', 'value' => ''];
	}
	else {
		CArrayHelper::sort($data['tags'], ['tag', 'value']);
	}

	// Add global macros to template macros.
	if ($data['show_inherited_macros']) {
		addInheritedMacros($data['macros']);
	}

	// Sort only after inherited macros are added. Otherwise the list will look chaotic.
	$data['macros'] = array_values(order_macros($data['macros'], 'macro'));

	// The empty inputs will not be shown if there are inherited macros, for example.
	if (!$data['macros']) {
		$macro = ['macro' => '', 'value' => '', 'description' => '', 'type' => ZBX_MACRO_TYPE_TEXT];

		if ($data['show_inherited_macros']) {
			$macro['inherited_type'] = ZBX_PROPERTY_OWN;
		}

		$data['macros'][] = $macro;
	}

	foreach ($data['macros'] as &$macro) {
		$macro['discovery_state'] = CControllerHostMacrosList::DISCOVERY_STATE_MANUAL;
	}
	unset($macro);

	if (!hasRequest('form_refresh')) {
		if ($data['templateid'] != 0) {
			$groups = array_column($data['dbTemplate']['templategroups'], 'groupid');
		}
		else {
			$groups = getRequest('groupids', []);
		}
	}
	else {
		$groups = getRequest('groups', []);
	}

	$groupids = [];

	foreach ($groups as $group) {
		if (is_array($group) && array_key_exists('new', $group)) {
			continue;
		}

		$groupids[] = $group;
	}

	// Groups with R and RW permissions.
	$groups_all = $groupids
		? API::TemplateGroup()->get([
			'output' => ['name'],
			'groupids' => $groupids,
			'preservekeys' => true
		])
		: [];

	// Groups with RW permissions.
	$groups_rw = $groupids && (CWebUser::getType() != USER_TYPE_SUPER_ADMIN)
		? API::TemplateGroup()->get([
			'output' => [],
			'groupids' => $groupids,
			'editable' => true,
			'preservekeys' => true
		])
		: [];

	$data['groups_ms'] = [];

	// Prepare data for multiselect.
	foreach ($groups as $group) {
		if (is_array($group) && array_key_exists('new', $group)) {
			$data['groups_ms'][] = [
				'id' => $group['new'],
				'name' => $group['new'].' ('._x('new', 'new element in multiselect').')',
				'isNew' => true
			];
		}
		elseif (array_key_exists($group, $groups_all)) {
			$data['groups_ms'][] = [
				'id' => $group,
				'name' => $groups_all[$group]['name'],
				'disabled' => (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) && !array_key_exists($group, $groups_rw)
			];
		}
	}
	CArrayHelper::sort($data['groups_ms'], ['name']);

	$data['template_name'] = getRequest('template_name', '');
	$data['visible_name'] = getRequest('visiblename', '');

	if ($data['templateid'] != 0 && !hasRequest('form_refresh')) {
		$data['template_name'] = $data['dbTemplate']['host'];
		$data['visible_name'] = $data['dbTemplate']['name'];

		// Display empty visible name if equal to host name.
		if ($data['visible_name'] === $data['template_name']) {
			$data['visible_name'] = '';
		}
	}

	$view = new CView('configuration.template.edit', $data);
}
else {
	$sortField = getRequest('sort', CProfile::get('web.'.$page['file'].'.sort', 'name'));
	$sortOrder = getRequest('sortorder', CProfile::get('web.'.$page['file'].'.sortorder', ZBX_SORT_UP));

	CProfile::update('web.'.$page['file'].'.sort', $sortField, PROFILE_TYPE_STR);
	CProfile::update('web.'.$page['file'].'.sortorder', $sortOrder, PROFILE_TYPE_STR);

	// filter
	if (hasRequest('filter_set')) {
		CProfile::update('web.templates.filter_name', getRequest('filter_name', ''), PROFILE_TYPE_STR);
		CProfile::update('web.templates.filter_vendor_name', getRequest('filter_vendor_name', ''), PROFILE_TYPE_STR);
		CProfile::update('web.templates.filter_vendor_version', getRequest('filter_vendor_version', ''), PROFILE_TYPE_STR);
		CProfile::updateArray('web.templates.filter_groups', getRequest('filter_groups', []), PROFILE_TYPE_ID);
		CProfile::update('web.templates.filter.evaltype', getRequest('filter_evaltype', TAG_EVAL_TYPE_AND_OR),
			PROFILE_TYPE_INT
		);

		$filter_tags = ['tags' => [], 'values' => [], 'operators' => []];
		foreach (getRequest('filter_tags', []) as $filter_tag) {
			if ($filter_tag['tag'] === '' && $filter_tag['value'] === '') {
				continue;
			}

			$filter_tags['tags'][] = $filter_tag['tag'];
			$filter_tags['values'][] = $filter_tag['value'];
			$filter_tags['operators'][] = $filter_tag['operator'];
		}
		CProfile::updateArray('web.templates.filter.tags.tag', $filter_tags['tags'], PROFILE_TYPE_STR);
		CProfile::updateArray('web.templates.filter.tags.value', $filter_tags['values'], PROFILE_TYPE_STR);
		CProfile::updateArray('web.templates.filter.tags.operator', $filter_tags['operators'], PROFILE_TYPE_INT);
	}
	elseif (hasRequest('filter_rst')) {
		CProfile::delete('web.templates.filter_name');
		CProfile::delete('web.templates.filter_vendor_name');
		CProfile::delete('web.templates.filter_vendor_version');
		CProfile::deleteIdx('web.templates.filter_groups');
		CProfile::delete('web.templates.filter.evaltype');
		CProfile::deleteIdx('web.templates.filter.tags.tag');
		CProfile::deleteIdx('web.templates.filter.tags.value');
		CProfile::deleteIdx('web.templates.filter.tags.operator');
	}

	$filter = [
		'name' => CProfile::get('web.templates.filter_name', ''),
		'vendor_name' => CProfile::get('web.templates.filter_vendor_name', ''),
		'vendor_version' => CProfile::get('web.templates.filter_vendor_version', ''),
		'groups' => CProfile::getArray('web.templates.filter_groups', null),
		'evaltype' => CProfile::get('web.templates.filter.evaltype', TAG_EVAL_TYPE_AND_OR),
		'tags' => []
	];

	foreach (CProfile::getArray('web.templates.filter.tags.tag', []) as $i => $tag) {
		$filter['tags'][] = [
			'tag' => $tag,
			'value' => CProfile::get('web.templates.filter.tags.value', null, $i),
			'operator' => CProfile::get('web.templates.filter.tags.operator', null, $i)
		];
	}

	// Get template groups.
	$filter['groups'] = $filter['groups']
		? CArrayHelper::renameObjectsKeys(API::TemplateGroup()->get([
			'output' => ['groupid', 'name'],
			'groupids' => $filter['groups'],
			'editable' => true,
			'preservekeys' => true
		]), ['groupid' => 'id'])
		: [];

	$filter_groupids = $filter['groups'] ? array_keys($filter['groups']) : null;
	if ($filter_groupids) {
		$filter_groupids = getTemplateSubGroups($filter_groupids);
	}

	// Select templates.
	$limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 1;
	$templates = API::Template()->get([
		'output' => ['templateid', $sortField],
		'evaltype' => $filter['evaltype'],
		'tags' => $filter['tags'],
		'search' => array_filter([
			'name' => $filter['name'],
			'vendor_name' => $filter['vendor_name'],
			'vendor_version' => $filter['vendor_version']
		]),
		'groupids' => $filter_groupids,
		'editable' => true,
		'sortfield' => $sortField,
		'limit' => $limit
	]);

	order_result($templates, $sortField, $sortOrder);

	// pager
	if (hasRequest('page')) {
		$page_num = getRequest('page');
	}
	elseif (isRequestMethod('get') && !hasRequest('cancel')) {
		$page_num = 1;
	}
	else {
		$page_num = CPagerHelper::loadPage($page['file']);
	}

	CPagerHelper::savePage($page['file'], $page_num);

	$paging = CPagerHelper::paginate($page_num, $templates, $sortOrder, new CUrl('templates.php'));

	$templates = API::Template()->get([
		'output' => ['templateid', 'name', 'vendor_name', 'vendor_version'],
		'selectHosts' => ['hostid'],
		'selectItems' => API_OUTPUT_COUNT,
		'selectTriggers' => API_OUTPUT_COUNT,
		'selectGraphs' => API_OUTPUT_COUNT,
		'selectDiscoveries' => API_OUTPUT_COUNT,
		'selectDashboards' => API_OUTPUT_COUNT,
		'selectHttpTests' => API_OUTPUT_COUNT,
		'selectTags' => ['tag', 'value'],
		'templateids' => array_column($templates, 'templateid'),
		'editable' => true,
		'preservekeys' => true
	]);

	order_result($templates, $sortField, $sortOrder);

	$linked_hostids = [];
	$editable_hosts = [];

	foreach ($templates as &$template) {
		$template['hosts'] = array_flip(array_column($template['hosts'], 'hostid'));
		$linked_hostids += $template['hosts'];
	}
	unset($template);

	if ($linked_hostids) {
		$editable_hosts = API::Host()->get([
			'output' => ['hostid'],
			'hostids' => array_keys($linked_hostids),
			'editable' => true,
			'preservekeys' => true
		]);
	}

	$data = [
		'templates' => $templates,
		'paging' => $paging,
		'page' => $page_num,
		'filter' => $filter,
		'sortField' => $sortField,
		'sortOrder' => $sortOrder,
		'editable_hosts' => $editable_hosts,
		'profileIdx' => 'web.templates.filter',
		'active_tab' => CProfile::get('web.templates.filter.active', 1),
		'tags' => makeTags($templates, true, 'templateid', ZBX_TAG_COUNT_DEFAULT, $filter['tags']),
		'config' => [
			'max_in_table' => CSettingsHelper::get(CSettingsHelper::MAX_IN_TABLE)
		],
		'allowed_ui_conf_hosts' => CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS)
	];

	$view = new CView('configuration.template.list', $data);
}

echo $view->getOutput();

require_once dirname(__FILE__).'/include/page_footer.php';
